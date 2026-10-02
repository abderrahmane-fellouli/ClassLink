<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Un compte désactivé ou « en attente » (F-AUTH-05) ne peut pas utiliser
 * l'API, même avec un jeton valide.
 */
class EnsureActiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Non authentifié.'], 401);
        }

        if (! $user->is_active) {
            return response()->json(['message' => 'Ce compte est désactivé.'], 403);
        }

        if (! Role::from($user->role)->canLogin()) {
            return response()->json([
                'message' => 'Votre compte est en attente de validation.',
                'role' => $user->role,
            ], 403);
        }

        return $next($request);
    }
}
