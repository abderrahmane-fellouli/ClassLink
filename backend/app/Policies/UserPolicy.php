<?php

namespace App\Policies;

use App\Models\User;

/**
 * RG-18 : « L'email d'un étudiant n'est visible que par lui-même, par
 * l'enseignant de sa classe et par le super admin. »
 *
 * Cette policy ne governs que l'affichage de l'adresse ; elle ne remplace
 * pas UserResource, qui n'inclut le champ `email` que lorsque
 * `UserResource::forViewer()` l'autorise.
 */
class UserPolicy
{
    public function viewEmail(User $viewer, User $target): bool
    {
        if ($viewer->id === $target->id || $viewer->isAdmin()) {
            return true;
        }

        if ($viewer->isTeacher()) {
            // L'enseignant voit l'email de ses propres classes.
            return $target->memberships()
                ->whereIn(
                    'classroom_id',
                    $viewer->taughtClassrooms()->select('id')
                )
                ->exists();
        }

        return false;
    }

    /** F-ADM-02 : seul l'administrateur gère les comptes. */
    public function update(User $viewer, User $target): bool
    {
        return $viewer->isAdmin();
    }
}
