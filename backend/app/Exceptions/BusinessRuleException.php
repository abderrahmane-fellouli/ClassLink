<?php

namespace App\Exceptions;

use Exception;

/**
 * Exception portant un code HTTP métier.
 *
 * §12 : « Codes de réponse utilisés : 200, 201, 202, 204, 401, 403, 404,
 * 409, 422, 429. »
 */
class BusinessRuleException extends Exception
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message,
        protected int $status = 422,
        protected array $context = [],
    ) {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }
}
