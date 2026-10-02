<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Émission et révocation des jetons — §7 RG-19, F-AUTH-06, F-AUTH-07.
 */
class TokenService
{
    /**
     * RG-19 : « Un jeton d'accès expire après 8 heures. »
     */
    public function issue(User $user, string $method = 'otp'): string
    {
        $hours = (int) config('classlink.session.token_ttl_hours', 8);

        $token = $user
            ->createToken(config('classlink.session.token_name', 'web'), ['*'], now()->addHours($hours))
            ->plainTextToken;

        // §17.10 : toute emission de jeton est tracee (Microsoft, OTP, dev).
        AuditLog::record($user, 'auth.login', ['method' => $method, 'ip' => request()?->ip()]);

        return $token;
    }

    /**
     * F-AUTH-06 / §17.10 : « Le serveur supprime le jeton et écrit
     * auth.logout ». T-23 : la requête suivante avec l'ancien jeton
     * renvoie 401.
     */
    public function revokeCurrent(mixed $token, User $user): void
    {
        // Le type est vérifié : `currentAccessToken()` peut renvoyer un
        // jeton transitoire (tests) au lieu d'un PersonalAccessToken.
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        AuditLog::record($user, 'auth.logout');
    }

    /** « Se déconnecter de toutes les sessions » (écran Sécurité). */
    public function revokeAll(User $user): int
    {
        $count = $user->tokens()->delete();

        AuditLog::record($user, 'auth.logout_all', ['count' => $count]);

        return $count;
    }

    /**
     * Purge les jetons expirés. Appelé par la tâche planifiée afin que la
     * table ne grossisse pas indéfiniment.
     */
    public function purgeExpired(): int
    {
        return PersonalAccessToken::where('expires_at', '<', Carbon::now())->delete();
    }

    public function isAdmin(User $user): bool
    {
        return $user->role === Role::Admin->value;
    }
}
