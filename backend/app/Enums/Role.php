<?php

namespace App\Enums;

/**
 * §7 RG-01 / RG-02, §17.5.
 *
 * Les valeurs correspondent exactement aux chaînes retournées par
 * App\Support\RoleDetector. Aucune autre valeur n'est acceptée par l'API.
 */
enum Role: string
{
    case Student = 'student';
    case Teacher = 'teacher';
    case Admin = 'admin';
    case Pending = 'pending';
    case Denied = 'denied';

    /**
     * Rôles réellement autorisés à se connecter. RG-05 : un compte « en
     * attente » n'a accès à rien tant que le super admin n'a pas validé son
     * rôle (F-AUTH-05).
     */
    public function canLogin(): bool
    {
        return in_array($this, [self::Student, self::Teacher, self::Admin], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Student => 'Étudiant',
            self::Teacher => 'Enseignant',
            self::Admin => 'Administrateur',
            self::Pending => 'En attente',
            self::Denied => 'Refusé',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Rôles que le super admin peut attribuer (F-AUTH-05, F-ADM-02). */
    public static function assignable(): array
    {
        return [self::Student->value, self::Teacher->value, self::Admin->value];
    }
}
