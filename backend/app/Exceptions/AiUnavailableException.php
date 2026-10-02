<?php

namespace App\Exceptions;

use Exception;

/**
 * F-IA-06 / RG-15 — « Si tous les fournisseurs échouent, l'enseignant voit un
 * message et peut créer le quiz manuellement. »
 *
 * Cette exception ne bloque jamais la création manuelle : elle transforme un
 * échec technique en message clair (§15.2).
 */
class AiUnavailableException extends Exception
{
    /**
     * @param  array<int, array{provider: string, reason: string}>  $attempts
     */
    public function __construct(
        ?string $message = null,
        public readonly array $attempts = [],
    ) {
        // Le message par défaut est traduit à la construction : `__()` n'est
        // pas autorisé en valeur par défaut d'un paramètre.
        parent::__construct($message ?? __('api.ai.unavailable'));
    }
}
