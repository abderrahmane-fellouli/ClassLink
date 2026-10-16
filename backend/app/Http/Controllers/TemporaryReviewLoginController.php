<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Classroom;
use App\Models\User;
use App\Services\SchoolAccess;
use App\Services\TokenService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Temporary operator-authorized review access; remove after the review. */
final class TemporaryReviewLoginController extends Controller
{
    private const ACCOUNTS = [
        'student' => ['email' => '2007031400094@ofppt-edu.ma', 'role' => 'student'],
        'delegate' => ['email' => '2005080600439@ofppt-edu.ma', 'role' => 'student'],
        'teacher' => ['email' => 'ZAKARIYAE.CHERGUI@ofppt-edu.ma', 'role' => 'teacher'],
        // Must be pinned only after the operator explicitly chooses a verified admin.
        'admin' => ['email' => null, 'role' => 'admin'],
    ];

    public function __invoke(Request $request, string $selector, TokenService $tokens, SchoolAccess $access): Response
    {
        [$secret, $until] = $this->authorizeReview($request);
        abort_unless(isset(self::ACCOUNTS[$selector]), 404);
        abort_if(self::ACCOUNTS[$selector]['email'] === null, 409, 'Admin review account has not been selected.');
        $fingerprint = hash('sha256', $secret);
        $purpose = 'temporary-review:'.$selector.':'.$fingerprint;

        try {
            return DB::transaction(function () use ($tokens, $access, $until, $selector, $fingerprint, $purpose) {
                $user = $this->account($selector, true);
                abort_unless($this->eligible($user, $selector, $access) && $until->isFuture(), 404);
                $usedMethods = [$purpose];
                // A previously issued single-account review counts as the student's use.
                if ($selector === 'student') {
                    $usedMethods[] = 'temporary-review:'.$fingerprint;
                }
                abort_if(AuditLog::where('action', 'auth.login')->whereIn('context->method', $usedMethods)->exists(), 410);

                $plain = $tokens->issue($user, $purpose);
                $token = $user->tokens()->findOrFail(explode('|', $plain, 2)[0]);
                $token->forceFill(['name' => $purpose, 'expires_at' => $token->created_at->copy()->addMinutes(15)->min($until)])->save();

                return response('', 302, [
                    'Location' => 'https://classlink.space/auth/microsoft/callback#token='.rawurlencode($plain),
                    'Cache-Control' => 'no-store, private',
                    'Pragma' => 'no-cache',
                    'Referrer-Policy' => 'no-referrer',
                    'X-Robots-Tag' => 'noindex, nofollow',
                    'X-Review-Selector' => $selector,
                    'X-Review-Token-Id' => (string) $token->id,
                    'X-Review-Expires-At' => $token->expires_at->utc()->toIso8601String(),
                ]);
            });
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failure($e);
        }
    }

    /** Protected discovery only: no issuance, selection, account edits or login audit. */
    public function accounts(Request $request, SchoolAccess $access): Response
    {
        $this->authorizeReview($request);
        try {
            return DB::transaction(function () use ($access) {
                if (DB::getDriverName() === 'pgsql' && DB::transactionLevel() === 1) {
                    DB::statement('SET TRANSACTION READ ONLY');
                }
                $accounts = [];
                foreach (['student', 'delegate', 'teacher'] as $selector) {
                    $user = $this->account($selector);
                    $scopes = $user && $selector === 'delegate' ? $this->delegateScopes($user, $access) : [];
                    $accounts[$selector] = [
                        'expected_email' => self::ACCOUNTS[$selector]['email'],
                        'exists_unique' => $user !== null,
                        'current_role' => $user?->role,
                        'is_active' => $user?->is_active ?? false,
                        'normal_access_eligible' => $user?->canAccessApp() ?? false,
                        'selector_eligible' => $this->eligible($user, $selector, $access, $scopes),
                        'delegate_scopes' => $scopes,
                    ];
                }

                return response()->json([
                    'accounts' => $accounts,
                    'eligible_admin_emails' => User::where('role', 'admin')->where('is_active', true)->orderBy('email')->pluck('email')->all(),
                    'admin_selected' => self::ACCOUNTS['admin']['email'] !== null,
                ])->withHeaders(['Cache-Control' => 'no-store, private', 'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex, nofollow']);
            });
        } catch (\Throwable $e) {
            return $this->failure($e);
        }
    }

    private function authorizeReview(Request $request): array
    {
        $secret = config('temporary-review.secret');
        $cutoff = config('temporary-review.until');
        abort_unless(app()->environment('production') && $request->isSecure(), 404);
        abort_unless(is_string($secret) && strlen($secret) >= 64, 404);
        abort_unless(hash_equals($secret, (string) $request->bearerToken()), 404);
        abort_unless($request->all() === [], 404);
        abort_unless(is_string($cutoff) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $cutoff), 404);
        try {
            $until = Carbon::parse($cutoff, 'UTC');
        } catch (\Throwable) {
            abort(404);
        }
        abort_unless($until->isFuture(), 404);

        return [$secret, $until];
    }

    private function account(string $selector, bool $lock = false): ?User
    {
        $email = self::ACCOUNTS[$selector]['email'];
        if ($email === null) {
            return null;
        }
        $query = User::whereRaw('LOWER(email) = ?', [strtolower($email)])->limit(2);
        $matches = ($lock ? $query->lockForUpdate() : $query)->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    private function eligible(?User $user, string $selector, SchoolAccess $access, ?array $scopes = null): bool
    {
        return $user && $user->role === self::ACCOUNTS[$selector]['role'] && $user->canAccessApp()
            && ($selector !== 'delegate' || ($scopes ?? $this->delegateScopes($user, $access)) !== []);
    }

    private function delegateScopes(User $user, SchoolAccess $access): array
    {
        if (! $user->isStudent() || ! $user->canAccessApp()) {
            return [];
        }
        $assignments = DB::table('class_delegates')->where('student_id', $user->id)
            ->whereNotNull('active_slot')->whereNull('revoked_at')->where('starts_at', '<=', now())->where('ends_at', '>', now())->get();
        $groups = Classroom::where('is_official', true)->whereIn('id', $assignments->pluck('classroom_id'))->get()->keyBy('id');
        $scopes = [];
        foreach ($assignments as $assignment) {
            $group = $groups->get($assignment->classroom_id);
            if (! $group || ! $access->delegate($user, $group) || $access->readOnly($group)) {
                continue;
            }
            if (! DB::table('school_enrollments')->where('classroom_id', $group->id)->where('academic_year_id', $group->academic_year_id)
                ->where('student_id', $user->id)->where('active_student_id', $user->id)->whereNull('ends_at')->where('starts_at', '<=', now())->exists()) {
                continue;
            }
            $scopes[] = ['classroom_id' => $group->id, 'official_code' => $group->official_code,
                'academic_year_id' => $group->academic_year_id, 'school_year' => $group->school_year,
                'starts_at' => $assignment->starts_at, 'ends_at' => $assignment->ends_at,
                'delegate_notices_enabled' => $group->delegate_notices_enabled];
        }

        return $scopes;
    }

    private function failure(\Throwable $exception): Response
    {
        Log::error('Temporary review access failed', ['exception_class' => $exception::class]);

        return response()->json(['message' => __('api.errors.server_error')], 503)->header('Cache-Control', 'no-store, private');
    }
}
