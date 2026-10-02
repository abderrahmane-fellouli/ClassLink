<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * §12 / §16 — l'API est une API JSON, sans état et sans page web.
 *
 * Sans ce middleware, un appel sans en-tête `Accept: application/json`
 * (`curl`, une application mobile, une sonde de supervision) fait échouer
 * `Authenticate::redirectTo()` : le framework tente alors de résoudre
 * `route('login')`, route inexistante puisque l'API n'a pas de garde web. Il en
 * résulte une `UrlGenerationException` (« Route [login] not defined ») qui
 * remonte en **500** au lieu du **401** attendu, et la charge utile JSON
 * documentée n'est jamais produite.
 *
 * On force donc la sémantique JSON pour tout `/api/*` : les gestionnaires
 * d'exceptions de `bootstrap/app.php` appliquent alors toujours le contrat
 * documenté (401 `session_expired`, 422 `invalid_data`, 404 `not_found`),
 * quel que soit le client.
 *
 * Un `Accept` explicite est respecté tel quel : un appel réellement non-JSON
 * (par exemple un `Accept: text/html` pour un diagnostic) reçoit toujours
 * `application/json`, l'API n'ayant aucune représentation HTML à offrir.
 */
class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->wantsJson()) {
            $request->headers->set('Accept', 'application/json');
        }

        return $next($request);
    }
}
