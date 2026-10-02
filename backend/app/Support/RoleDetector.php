<?php

namespace App\Support;

use App\Enums\Role;

/**
 * §17.5 — Détection du rôle (premier code métier, à lire tel quel).
 *
 * La logique est volontairement identique à l'extrait de référence fourni
 * dans le cahier des charges ; seule la lecture des motifs depuis
 * config/classlink.php a été ajoutée pour les rendre configurables
 * (aucune liste d'enseignants, aucun suffixe inventé).
 *
 * Sécurité : cette classe est le SEUL endroit où un rôle est calculé. Elle
 * n'est jamais appelée avec une valeur fournie par le navigateur
 * (§16 « Élévation de rôle »).
 */
class RoleDetector
{
    public static function fromEmail(string $email): string
    {
        $email = strtolower(trim($email));
        $domain = '@'.config('classlink.role_detection.domain');

        // 1. RG-01 : tout autre domaine est refusé.
        if (! str_ends_with($email, $domain)) {
            return Role::Denied->value;
        }

        $local = substr($email, 0, -strlen($domain));

        // Garde-fou : partie locale mal formée.
        if ($local === '' || preg_match(config('classlink.role_detection.denied_local_regex'), $local)) {
            return Role::Denied->value;
        }

        // 2. RG-02 : exactement 13 chiffres -> étudiant.
        if (preg_match(config('classlink.role_detection.student_local_regex'), $local)) {
            return Role::Student->value;
        }

        // 3. RG-02 : motif enseignant -> enseignant.
        if (preg_match(config('classlink.role_detection.teacher_local_regex'), $local)) {
            return Role::Teacher->value;
        }

        // 4. Domaine valide, format inconnu -> en attente de validation.
        return Role::Pending->value;
    }

    /**
     * Rôle applicable à un compte existant.
     *
     * RG-03 : si le rôle a été verrouillé par le super admin, il n'est plus
     * recalculé. §17.6 : un rôle « pending » ne doit jamais écraser un rôle
     * déjà attribué.
     */
    public static function resolveFor(string $email, bool $roleLocked, ?string $currentRole): string
    {
        $detected = self::fromEmail($email);

        if ($roleLocked) {
            return $currentRole ?? $detected;
        }

        if ($detected === Role::Pending->value) {
            return $currentRole ?? $detected;
        }

        return $detected;
    }
}
