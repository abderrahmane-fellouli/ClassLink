<?php

namespace App\Enums;

/**
 * RG-10 : une classe archivée est en lecture seule.
 */
enum ClassStatus: string
{
    case Active = 'active';
    case Archived = 'archived';

    public function isReadOnly(): bool
    {
        return $this === self::Archived;
    }
}
