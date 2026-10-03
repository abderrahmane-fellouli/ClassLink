<?php

namespace App\Policies;

use App\Models\Membership;
use App\Models\User;

/** F-REQ-06 : l'étudiant suit ses propres demandes. */
class MembershipPolicy
{
    public function decide(User $user, Membership $membership): bool
    {
        return $membership->classroom->isOwnedBy($user);
    }
    public function view(User $user, Membership $membership): bool
    {
        return $membership->student_id === $user->id;
    }
}
