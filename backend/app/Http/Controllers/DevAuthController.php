<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\TokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * §25 — mécanisme de développement.
 *
 * La route n'existe QUE si `config('classlink.dev_auth.enabled')` vaut vrai,
 * ce qui est impossible en production (`APP_ENV=production`).
 * Elle émet un vrai jeton Sanctum sur un compte de démonstration afin de
 * lancer la maquette sans Microsoft ni serveur d'email.
 */
class DevAuthController extends Controller
{
    public function __construct(private readonly TokenService $tokens) {}

    public function login(Request $request): JsonResponse
    {
        if (! config('classlink.dev_auth.enabled') || ! app()->environment(['local', 'testing']) || ! $this->localDatabase()) {
            return response()->json(['message' => __('api.errors.not_found')], 404);
        }

        $data = $request->validate([
            // `email:rfc` : meme garde-fou que la connexion par code, y compris
            // si ce raccourci est active par megarde sur un environnement
            // non nominal (CVE-2026-48019).
            'email' => ['nullable', 'string', 'email:rfc'],
            'role' => ['nullable', 'string', 'in:student,teacher,admin'],
        ]);

        $user = $this->resolve($data);

        if (! $user || ! $user->canAccessApp()) {
            return response()->json([
                'message' => 'Aucun compte de démonstration. Lancez : php artisan db:seed',
            ], 404);
        }

        $user->update(['last_login_at' => now()]);

        /*
         * On passe par le TokenService comme pour une vraie connexion : le
         * jeton a la même durée de vie et la connexion est journalisée, ce
         * qui rend la maquette fidèle à la production.
         */
        $token = $this->tokens->issue($user, 'dev');

        return response()->json([
            'token' => $token,
            'user' => UserResource::forSelf($user),
        ]);
    }

    private function resolve(array $data): ?User
    {
        if (! empty($data['email'])) {
            $matches = User::whereRaw('LOWER(email) = ?', [strtolower($data['email'])])->limit(2)->get();

            return $matches->count() === 1 ? $matches->first() : null;
        }

        $role = $data['role'] ?? 'student';

        return User::where('role', $role)->orderBy('id')->first();
    }

    private function localDatabase(): bool
    {
        $driver = config('database.default');
        $connection = config('database.connections.'.$driver, []);
        if (! empty($connection['url'])) {
            return false;
        }
        if ($driver === 'sqlite') {
            $file = (string) ($connection['database'] ?? '');

            return ($file === ':memory:' && app()->environment('testing'))
                || (is_file($file) && ! str_starts_with($file, '\\\\') && ! str_starts_with($file, '//'));
        }

        return $driver === 'pgsql' && app()->environment('testing')
            && in_array($connection['host'] ?? '', ['127.0.0.1', 'localhost', '::1'], true)
            && str_ends_with((string) ($connection['database'] ?? ''), '_test');
    }
}
