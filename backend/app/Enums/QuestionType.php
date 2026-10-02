<?php

namespace App\Enums;

/** F-QUI-01 : choix unique, choix multiple, vrai / faux. */
enum QuestionType: string
{
    case Single = 'single';
    case Multiple = 'multiple';
    case TrueFalse = 'true_false';

    /** Nombre d'options correctes autorisées pour ce type de question. */
    public function correctAnswerCount(): string
    {
        return match ($this) {
            self::Single => '1',
            self::TrueFalse => '1',
            self::Multiple => '1..many',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
