<?php

namespace App\Support;

use App\Enums\Role;

/** Account classification is not proof of Microsoft identity. Never infer birth dates. */
class RoleDetector
{
    public static function normalizeOfpptAddress(string $email): ?string
    {
        if (preg_match('/[\r\n\x00]/', $email)) {
            return null;
        }
        $email = strtolower(trim($email));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || substr_count($email, '@') !== 1) {
            return null;
        }
        [$local, $domain] = explode('@', $email, 2);

        // Exact approved domain; no suffix, wildcard, or lookalike matching.
        return $domain === 'ofppt-edu.ma' && $local !== '' ? $email : null;
    }

    public static function fromEmail(string $email): string
    {
        $email = self::normalizeOfpptAddress($email);
        if ($email === null) {
            return Role::Denied->value;
        }
        $local = explode('@', $email, 2)[0];

        // Observed convention, not an official guarantee or date/length parser.
        return preg_match('/^[0-9]+$/D', $local) ? Role::Student->value : Role::Pending->value;
    }

    public static function resolveFor(string $email, bool $roleLocked, ?string $currentRole): string
    {
        $detected = self::fromEmail($email);
        if ($detected === Role::Denied->value) {
            return $detected;
        }

        // Only an explicitly approved role survives candidate reclassification.
        return $roleLocked ? ($currentRole ?? $detected) : $detected;
    }
}
