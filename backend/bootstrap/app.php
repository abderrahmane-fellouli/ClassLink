<?php

use App\Exceptions\AiUnavailableException;
use App\Exceptions\BusinessRuleException;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\VerifyDigestToken;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        /*
         * Laravel 11 enregistre par défaut `redirectGuestsTo(fn () => route('login'))`.
         * Or cette application n'a aucune route web `login` : c'est une API
         * pure. Sans cette surcharge, toute requête non authentifiée dont
         * `expectsJson()` est faux (donc sans en-tête `Accept: application/json` :
         * `curl`, application mobile, sonde de supervision) voyait
         * `Authenticate::redirectTo()` résoudre `route('login')` et levait
         * « Route [login] not defined », soit un **500** au lieu du **401**
         * documenté.
         *
         * Pour `/api/*` on renvoie `null` : l'exception `AuthenticationException`
         * est alors rendue en JSON par le gestionnaire de `bootstrap/app.php`.
         */
        $middleware->redirectGuestsTo(
            fn (Request $request) => $request->is('api/*') ? null : url('/')
        );

        $middleware->api(prepend: [
            \Illuminate\Http\Middleware\HandleCors::class,
            // Redondance volontaire : garantit `expectsJson()` même si une
            // requête API traverse un chemin qui court-circuite le groupe
            // (erreur levée avant le routage, appel interne direct).
            ForceJsonResponse::class,
        ]);

        $middleware->alias([
            'role' => EnsureRole::class,
            'active' => EnsureActiveUser::class,
            'locale' => SetLocale::class,
            'digest.token' => VerifyDigestToken::class,
        ]);

        // L'API est sans état : aucun cookie de session, uniquement le
        // jeton Bearer (§16 « Vol de jeton »).
        $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (BusinessRuleException $e, Request $request) {
            SetLocale::apply($request);
            return response()->json(array_filter([
                'message' => $e->getMessage(),
                'context' => $e->context() ?: null,
            ]), $e->status());
        });

        $exceptions->render(function (ValidationException $e, Request $request) {

            SetLocale::apply($request);
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => __('api.errors.invalid_data'),
                'errors' => $e->errors(),
            ], 422);
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {

            SetLocale::apply($request);
            if (! $request->is('api/*')) {
                return null;
            }

            // T-25 : jeton expiré -> 401 avec message explicite.
            return response()->json([
                'message' => __('api.errors.session_expired'),
                'code' => 'session_expired',
            ], 401);
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) {

            SetLocale::apply($request);
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json(['message' => __('api.errors.forbidden')], 403);
        });

        /*
         * `Gate::authorize()` lève une `AuthorizationException`, que Laravel
         * convertit en `AccessDeniedHttpException` **avant** d'invoquer les
         * callbacks `render()`. Sans ce callback, le 403 retombait donc sur
         * le gestionnaire générique `HttpExceptionInterface` et renvoyait le
         * libellé anglais « This action is unauthorized. » à un utilisateur
         * français. §12 / T-26 : le message renvoyé est traduit (§17.6).
         */
        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) {

            SetLocale::apply($request);
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json(['message' => __('api.errors.forbidden')], 403);
        });

        $exceptions->render(function (ModelNotFoundException $e, Request $request) {

            SetLocale::apply($request);
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json(['message' => __('api.errors.not_found')], 404);
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {

            SetLocale::apply($request);
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json(['message' => __('api.errors.not_found')], 404);
        });

        $exceptions->render(function (TooManyRequestsHttpException $e, Request $request) {

            SetLocale::apply($request);
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => __('api.errors.too_many_requests'),
            ], 429, $e->getHeaders());
        });

        $exceptions->render(function (AiUnavailableException $e, Request $request) {

            SetLocale::apply($request);
            return response()->json([
                'message' => $e->getMessage(),
                'manual_fallback' => true,
                'attempts' => $e->attempts,
            ], 503);
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {

            SetLocale::apply($request);
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => $e->getMessage() ?: __('api.errors.server_error'),
            ], $e->getStatusCode(), $e->getHeaders());
        });
    })->create();
