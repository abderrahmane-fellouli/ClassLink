<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * §17.11 : la route du résumé quotidien est « appelée par une tâche
 * planifiée GitHub Actions ». Elle est donc protégée par un secret
 * partagé, jamais par un jeton utilisateur.
 */
class VerifyDigestToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('classlink.digest_token');

        // Sans secret configuré, la route est fermée.
        if ($expected === '') {
            return response()->json(['message' => 'Tâche planifiée non configurée.'], 404);
        }

        $provided = (string) $request->header('X-Digest-Token', '');

        if ($provided === '' || ! hash_equals($expected, $provided)) {
            return response()->json(['message' => 'Jeton de tâche invalide.'], 401);
        }

        return $next($request);
    }
}
