<?php

namespace App\Policies;

use App\Models\Submission;
use App\Models\User;

/** F-DEV-03 : PATCH /submissions/{id} — propriétaire de la classe. */
class SubmissionPolicy
{
    public function view(User $user, Submission $submission): bool
    {
        return $submission->student_id === $user->id
            || $submission->assignment->classroom->isOwnedBy($user);
    }

    public function grade(User $user, Submission $submission): bool
    {
        return $submission->assignment->classroom->isOwnedBy($user);
    }
}
