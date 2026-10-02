<?php

namespace App\Policies;

use App\Models\Classroom;
use App\Models\User;

/** F-DEV-01 à F-DEV-03 — devoirs et rendus. */
class AssignmentPolicy
{
    public function view(User $user, $assignment): bool
    {
        return $this->manage($user, $assignment->classroom)
            || $assignment->classroom->hasAcceptedMember($user->id);
    }

    /** F-DEV-01 : le proprietaire de la classe cree et modifie les devoirs. */
    public function create(User $user, Classroom $classroom): bool
    {
        return $classroom->isOwnedBy($user) && ! $classroom->isReadOnly();
    }

    /** L'enseignant lit un devoir meme apres archivage de la classe. */
    private function manage(User $user, Classroom $classroom): bool
    {
        return $classroom->isOwnedBy($user);
    }

    public function update(User $user, $assignment): bool
    {
        return $this->create($user, $assignment->classroom);
    }

    public function delete(User $user, $assignment): bool
    {
        return $this->update($user, $assignment);
    }

    /** F-DEV-02 : l'étudiant dépose son propre rendu. */
    public function submit(User $user, $assignment): bool
    {
        return $user->isStudent() && $assignment->classroom->hasAcceptedMember($user->id);
    }

    /** F-DEV-03 : le propriétaire lit et note les rendus. */
    public function grade(User $user, $assignment): bool
    {
        return $this->manage($user, $assignment->classroom);
    }
}
