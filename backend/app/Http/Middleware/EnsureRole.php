<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * §17.7 — `Route::middleware('role:teacher')`.
 *
 * Applique le rôle déclaré par le serveur. Le client n'envoie jamais de
 * rôle (§16 « Élévation de rôle »).
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Non authentifié.'], 401);
        }

        if (! in_array($user->role, $roles, true)) {
            return response()->json(['message' => 'Accès refusé.'], 403);
        }

        return $next($request);
    }
}
