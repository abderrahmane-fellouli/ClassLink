<?php

namespace App\Policies;

use App\Models\Classroom;
use App\Models\User;

/**
 * §15.3 « Contrôle : Rôle autorisé -> Enseignant propriétaire de la classe ».
 */
class AiJobPolicy
{
    public function view(User $user, $aiJob): bool
    {
        return $aiJob->teacher_id === $user->id || $user->isAdmin();
    }

    public function generate(User $user, Classroom $classroom): bool
    {
        return $user->isTeacher()
            && $classroom->isOwnedBy($user)
            && ! $classroom->isReadOnly();
    }
}
