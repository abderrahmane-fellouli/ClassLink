<?php

namespace App\Services;

use App\Enums\Role;
use App\Exceptions\BusinessRuleException;
use App\Models\OtpCode;
use App\Models\User;
use App\Support\RoleDetector;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * F-AUTH-02 — connexion de secours par code à 6 chiffres.
 *
 * §16 « Pour le code email : code haché, 10 minutes, 5 essais,
 * limitation de débit ».
 * §11 : « Les codes email sont stockés hachés, jamais en clair. »
 */
class OtpService
{
    public function __construct(private readonly TokenService $tokens) {}

    /**
     * Crée et envoie un code. Retourne 202 (traitement accepté).
     *
     * RG-01 : un email hors domaineofppt-edu.ma ne reçoit jamais de code.
     */
    public function request(string $email, ?int $userId = null): void
    {
        $email = strtolower(trim($email));

        if (RoleDetector::fromEmail($email) === Role::Denied->value) {
            // RG-01 : aucune enumeration possible, aucun envoi.
            return;
        }

        $this->invalidatePending($email);

        $digits = (int) config('classlink.otp.digits', 6);
        $ttl = (int) config('classlink.otp.ttl_minutes', 10);

        $code = str_pad((string) random_int(0, (10 ** $digits) - 1), $digits, '0', STR_PAD_LEFT);

        $otp = OtpCode::create([
            'user_id' => $userId,
            'email' => $email,
            'code_hash' => OtpCode::hash($code),
            'attempts' => 0,
            'expires_at' => Carbon::now()->addMinutes($ttl),
            'ip' => request()?->ip(),
        ]);

        try {
            $this->dispatch($email, $code, $otp->expires_at);
        } catch (\Throwable $e) {
            $otp->delete();
            Log::warning('OTP email delivery failed');
            throw new BusinessRuleException(__('api.otp.delivery_failed'), 503);
        }
    }

    protected function dispatch(string $email, string $code, Carbon $expiresAt): void
    {
        $minutes = (int) config('classlink.otp.ttl_minutes', 10);

        Mail::raw(__('api.otp.email_body', ['code' => $code, 'minutes' => $minutes]),
            function ($message) use ($email) {
                $message->to($email)->subject(__('api.otp.email_subject'));
            }
        );
    }

    /**
     * Vérifie le code et émet un jeton.
     *
     * T-05 : code correct dans les 10 minutes -> connexion réussie.
     * T-06 : code expiré ou 6e essai -> erreur, AUCUN jeton émis.
     *
     * @return array{ok: true, user: User, token: string}|array{ok: false, reason: string}
     */
    public function verify(string $email, string $code): array
    {
        return DB::transaction(fn () => $this->verifyLocked($email, $code));
    }

    private function verifyLocked(string $email, string $code): array
    {
        $email = strtolower(trim($email));

        $otp = OtpCode::where('email', $email)
            ->whereNull('consumed_at')
            ->latest('id')
            ->lockForUpdate()
            ->first();

        if (! $otp) {
            return ['ok' => false, 'reason' => __('api.otp.invalid')];
        }

        if ($otp->isExpired()) {
            return ['ok' => false, 'reason' => __('api.otp.expired')];
        }

        if (! $otp->hasAttemptsLeft()) {
            return ['ok' => false, 'reason' => __('api.otp.attempts_exhausted')];
        }

        if (! hash_equals($otp->code_hash, OtpCode::hash(trim($code)))) {
            $otp->increment('attempts');

            return ['ok' => false, 'reason' => __('api.otp.invalid')];
        }

        $user = $this->resolveUser($email);

        if (! $user->canAccessApp()) {
            return ['ok' => false, 'reason' => __('api.otp.account_denied')];
        }

        $otp->update(['consumed_at' => Carbon::now()]);

        $user->update(['last_login_at' => Carbon::now()]);

        return ['ok' => true, 'user' => $user, 'token' => $this->tokens->issue($user, 'otp')];
    }

    /**
     * Retrouve le compte, ou le crée avec le rôle calculé côté serveur.
     * RG-03 : un rôle verrouillé n'est pas recalculé.
     */
    private function resolveUser(string $email): User
    {
        $user = User::where('email', $email)->first();

        if ($user) {
            $resolved = RoleDetector::resolveFor($email, (bool) $user->role_locked, $user->role);
            if ($resolved !== $user->role) {
                $user->tokens()->delete();
            }
            $user->forceFill([
                'role' => $resolved,
                'role_candidate' => RoleDetector::fromEmail($email) === 'student' ? 'student' : 'teacher',
            ])->save();

            return $user;
        }

        $detected = RoleDetector::fromEmail($email);

        $user = new User([
            'email' => $email,
            'display_name' => $this->displayNameFromEmail($email),
            'role' => $detected,
            'role_locked' => false,
            'locale' => config('app.locale', 'fr'),
            'is_active' => true,
        ]);
        $user->forceFill(['role_candidate' => $detected === 'student' ? 'student' : 'teacher'])->save();

        return $user;
    }

    /** Nom d'affichage provisoire tant que Microsoft n'a rien fourni. */
    private function displayNameFromEmail(string $email): string
    {
        $local = strtok($email, '@');
        if (preg_match('/^[0-9]+$/D', (string) $local)) {
            return 'Student';
        }
        $name = str_replace(['.', '-', '_'], ' ', (string) $local);

        return trim(mb_convert_case($name, MB_CASE_TITLE, 'UTF-8')) ?: $email;
    }

    public function invalidatePending(string $email): void
    {
        OtpCode::where('email', strtolower(trim($email)))
            ->whereNull('consumed_at')
            ->update(['consumed_at' => Carbon::now()]);
    }

    /** Purge des codes expirés (RG-20 : « purge régulière », §11). */
    public function purgeExpired(): int
    {
        return OtpCode::where('expires_at', '<', Carbon::now())
            ->where(function ($q) {
                $q->whereNull('consumed_at')
                    ->orWhere('created_at', '<', Carbon::now()->subDays(1));
            })
            ->delete();
    }
}
