<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\MicrosoftAccountService;
use App\Services\TokenService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

/**
 * §12.1 Authentification, §17.6 et §17.10.
 */
class AuthController extends Controller
{
    public function __construct(private readonly TokenService $tokens, private readonly MicrosoftAccountService $accounts) {}

    /** §12.1 — GET /auth/microsoft/redirect. */
    public function redirect(Request $request)
    {
        foreach (['client_id', 'client_secret', 'redirect', 'tenant'] as $key) {
            if (! config('services.azure.'.$key)) {
                return redirect($this->frontend('callback').'#error=microsoft_unavailable');
            }
        }
        $authority = strtolower((string) config('services.azure.tenant'));
        if ($authority !== 'organizations' && ! Str::isUuid($authority)) {
            return redirect($this->frontend('callback').'#error=microsoft_unavailable');
        }

        // A short-lived, browser-bound nonce protects the OAuth handshake.
        // API authentication remains stateless Sanctum bearer authentication.
        $state = Str::random(64);
        Cache::put('oauth-state:'.hash('sha256', $state), true, now()->addMinutes(10));
        try {
            return Socialite::driver('azure')->stateless()->with(['state' => $state])->redirect()
                ->withCookie(cookie('classlink_oauth_state', $state, 10, '/api/auth/microsoft', null, $request->isSecure(), true, false, 'lax'));
        } catch (\Throwable $e) {
            Cache::forget('oauth-state:'.hash('sha256', $state));
            Log::warning('Microsoft redirect failed', ['exception_type' => get_class($e)]);

            return redirect($this->frontend('callback').'#error=microsoft_unavailable');
        }
    }

    /**
     * §12.1 / §17.6 — GET /auth/microsoft/callback.
     * « Reçoit Microsoft, détecte le rôle, émet le jeton. »
     */
    public function callback(Request $request)
    {
        $state = $request->query('state');
        $cookieState = $request->cookie('classlink_oauth_state');
        if (! is_string($state) || ! is_string($cookieState) || strlen($state) !== 64
            || ! hash_equals($cookieState, $state)) {
            return redirect($this->frontend('callback').'#error=invalid_state');
        }
        $key = 'oauth-state:'.hash('sha256', $state);
        $valid = Cache::lock($key.':lock', 10)->get(function () use ($key) {
            return Cache::pull($key);
        });
        if (! $valid) {
            return redirect($this->frontend('callback').'#error=invalid_state');
        }
        if ($request->has('error')) {
            return redirect($this->frontend('callback').'#error=microsoft_cancelled');
        }
        try {
            $ms = Socialite::driver('azure')->stateless()->user();
        } catch (\Throwable $e) {
            // Never log provider exceptions/messages, codes or access tokens.
            Log::warning('Microsoft callback failed', ['exception_type' => get_class($e)]);

            return redirect($this->frontend('callback').'#error=microsoft_unavailable');
        }
        try {
            $user = $this->accounts->resolve($ms);
        } catch (UniqueConstraintViolationException $e) {
            // Concurrent/conflicting links fail closed; never duplicate/merge identities.
            return $this->deny();
        }

        if (! $user) {
            return $this->deny();
        }

        // §17.6 B : format inconnu -> ecran « Compte en attente de validation ».
        if (! $user->canAccessApp()) {
            $receipt = Str::random(64);
            Cache::put('oauth-pending:'.hash('sha256', $receipt), $user->id, now()->addMinutes(10));

            return redirect($this->frontend('pending').'#verification='.$receipt);
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
        AuditLog::record(null, 'auth.denied', ['reason' => 'ineligible_microsoft_identity']);

        return redirect($this->frontend('denied'));
    }

    /** Non-login, single-use receipt for the pending screen, without exposing identity IDs. */
    public function pendingVerification(Request $request)
    {
        $data = $request->validate(['verification' => ['required', 'string', 'size:64']]);
        $key = 'oauth-pending:'.hash('sha256', $data['verification']);
        $id = Cache::lock($key.':lock', 10)->get(fn () => Cache::pull($key));
        $user = $id ? User::find($id) : null;
        abort_unless($user && $user->microsoft_verified_at !== null, 404);

        return response()->json([
            'verification_source' => 'microsoft',
            'role_candidate' => $user->role_candidate,
            'status' => ! $user->is_active ? 'denied' : ($user->role === 'pending' ? 'pending' : 'approved'),
        ]);
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
    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }
}
