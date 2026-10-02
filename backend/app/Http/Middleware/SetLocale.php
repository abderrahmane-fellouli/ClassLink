<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * F-UI-01 — la langue choisie par l'utilisateur est appliquée à la
 * validation. DRY : « Le frontend charge le profil et affiche le tableau de
 * bord du rôle ».
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        self::apply($request);

        return $next($request);
    }

    /** Langues autorisées en version 1.0. */
    public const SUPPORTED = ['fr', 'en'];

    /**
     * Détermine et applique la langue de la requête.
     *
     * F-UI-01 : le choix de l'utilisateur prime. Sinon on lit l'en-tête du
     * navigateur, puis la langue par défaut.
     *
     * Cette méthode est aussi appelée par le gestionnaire d'exceptions : une
     * route inconnue (404) ou une erreur d'authentification (401) court-circuite
     * le middleware, et le message serait sinon toujours en français.
     */
    public static function apply(Request $request): string
    {
        $locale = $request->user()?->locale
            ?? self::fromHeader((string) $request->header('Accept-Language', ''))
            ?? config('app.locale', 'fr');

        // Deux langues seulement en version 1.0 (§1.5 : l'arabe est exclu).
        if (! in_array($locale, self::SUPPORTED, true)) {
            $locale = config('app.locale', 'fr');
        }

        app()->setLocale($locale);

        return $locale;
    }

    /**
     * Extrait la langue d'un en-tête `Accept-Language`.
     *
     * Un navigateur envoie typiquement `en-GB,en;q=0.9,fr;q=0.8` : on retient
     * la première langue *supportée*, en ignorant la région et la qualité.
     * Sans cela, l'en-tête ne correspond jamais à « fr » ou « en » et l'on
     * retomberait silencieusement sur la langue par défaut.
     */
    public static function fromHeader(string $header): ?string
    {
        if ($header === '') {
            return null;
        }

        foreach (explode(',', $header) as $part) {
            $tag = trim(explode(';', $part)[0]);
            $primary = strtolower(explode('-', $tag)[0]);

            if (in_array($primary, self::SUPPORTED, true)) {
                return $primary;
            }
        }

        return null;
    }

    public static function forUser(User $user): void
    {
        app()->setLocale(in_array($user->locale, self::SUPPORTED, true)
            ? $user->locale
            : config('app.locale', 'fr'));
    }

    public static function user(): ?User
    {
        return Auth::user();
    }
}
