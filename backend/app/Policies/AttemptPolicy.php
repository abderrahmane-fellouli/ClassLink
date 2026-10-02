<?php

namespace App\Policies;

use App\Models\Attempt;
use App\Models\User;

/** §12.4 : GET /attempts/{id} — étudiant, sa propre tentative. */
class AttemptPolicy
{
    public function view(User $user, Attempt $attempt): bool
    {
        return $attempt->student_id === $user->id;
    }

    public function submit(User $user, Attempt $attempt): bool
    {
        return $attempt->student_id === $user->id;
    }
}
