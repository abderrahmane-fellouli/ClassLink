<?php

namespace App\Policies;

use App\Models\Material;
use App\Models\User;

/**
 * F-CON-02, F-CON-03 — accès aux ressources d'une classe.
 *
 * RG-04 : « un enseignant ne gère que ses propres classes ». La gestion des
 * ressources est donc la prérogative de l'enseignant propriétaire, comme le
 * reste du contenu pédagogique (devoirs, quiz, annonces, decks).
 *
 * Le super-administrateur s'en tient aux capacités de §12.6 (F-ADM-01 à
 * F-ADM-06 : utilisateurs, classes, fournisseurs IA, journaux, statistiques) :
 * il n'est pas propriétaire d'une classe et n'a donc ni lecture ni
 * suppression sur les ressources d'un enseignant. On ne s'appuie pas ici sur
 * `Classroom::isOwnedBy()`, qui inclut l'admin pour les besoins de
 * l'administration des classes.
 */
class MaterialPolicy
{
    public function view(User $user, Material $material): bool
    {
        return $this->manage($user, $material)
            || $material->classroom->hasAcceptedMember($user->id);
    }

    public function manage(User $user, Material $material): bool
    {
        return $this->ownsClassroom($user, $material)
            && ! $material->classroom->isReadOnly();
    }

    public function update(User $user, Material $material): bool
    {
        return $this->manage($user, $material);
    }

    public function delete(User $user, Material $material): bool
    {
        return $this->update($user, $material);
    }

    /** L'utilisateur est l'enseignant propriétaire de la classe. */
    private function ownsClassroom(User $user, Material $material): bool
    {
        return $user->isTeacher() && $material->classroom->teacher_id === $user->id;
    }
}