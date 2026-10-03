<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Resources\UserResource;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\TokenService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Socialite\Facades\Socialite;

/**
 * §12.1 Authentification, §17.6 et §17.10.
 */
class AuthController extends Controller
{
    public function __construct(private readonly TokenService $tokens) {}

    /** §12.1 — GET /auth/microsoft/redirect. */
    public function redirect()
    {
        return Socialite::driver('azure')->stateless()->redirect();
    }

    /**
     * §12.1 / §17.6 — GET /auth/microsoft/callback.
     * « Reçoit Microsoft, détecte le rôle, émet le jeton. »
     */
    public function callback()
    {
        $ms = Socialite::driver('azure')->stateless()->user();
        $email = strtolower((string) $ms->getEmail());

        $user = $this->resolveUser($email, $ms->getName());

        if (! $user) {
            return $this->deny();
        }

        // §17.6 B : format inconnu -> ecran « Compte en attente de validation ».
        if (! $user->canAccessApp()) {
            return redirect($this->frontend('pending'));
        }

        $user->update(['last_login_at' => now()]);

        // RG-20 : la connexion est tracée par TokenService::issue().
        $token = $this->tokens->issue($user, 'microsoft');

        // §17.6 : le jeton transite par le fragment, jamais par la query
        // string, afin de ne jamais figurer dans un journal de serveur.
        return redirect($this->frontend('callback').'#token='.$token);
    }

    /**
     * F-AUTH-06 / §17.10 — POST /auth/logout (204).
     * T-23 : la requête suivante avec l'ancien jeton renvoie 401.
     */
    public function logout(Request $request): Response
    {
        $user = $request->user();

        $this->tokens->revokeCurrent($user->currentAccessToken(), $user);

        return response()->noContent();
    }

    /**
     * RG-01 / §17.6 : hors domaine -> « Accès refusé » ; compte inactif ou
     * « en attente » -> « Compte en attente ».
     */
    private function deny()
    {
        return redirect($this->frontend('denied'));
    }

    /**
     * Construit une URL frontend à partir de config('classlink.frontend_url')
     * et des chemins déclarés dans `config/classlink.php`, qui doivent rester
     * synchronisés avec `frontend/src/router.tsx`.
     */
    private function frontend(string $key): string
    {
        return rtrim((string) config('classlink.frontend_url'), '/')
            .config('classlink.frontend_routes.'.$key);
    }

    /**
     * §17.6 : création ou récupération du compte, avec détection de rôle
     * strictement côté serveur.
     */
    private function resolveUser(string $email, ?string $name): ?User
    {
        $detected = \App\Support\RoleDetector::fromEmail($email);

        if ($detected === Role::Denied->value) {
            AuditLog::record(null, 'auth.denied', ['email_domain' => 'external']);

            return null;
        }

        $user = User::where('email', $email)->first();

        if (! $user) {
            $user = User::create([
                'email' => $email,
                'display_name' => $name ?: 'User',
                'role' => $detected,
                'role_locked' => false,
                'locale' => config('app.locale', 'fr'),
                'is_active' => true,
            ]);
        } else {
            // RG-03 : un rôle verrouillé n'est jamais recalculé.
            // §17.6 : « pending » ne doit pas écraser un rôle attribué.
            $resolved = \App\Support\RoleDetector::resolveFor(
                $email,
                (bool) $user->role_locked,
                $user->role
            );

            if ($resolved !== $user->role) {
                $user->update(['role' => $resolved]);
            }
        }

        if (! $user->is_active) {
            AuditLog::record($user, 'auth.denied', ['reason' => 'inactive']);

            return null;
        }

        return $user;
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }
}
