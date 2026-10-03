<?php

namespace App\Policies;

use App\Models\PartnerRequest;
use App\Models\User;

/** F-PAR-03 : seul le destinataire répond à une demande. */
class PartnerRequestPolicy
{
    public function create(User $user, \App\Models\Classroom $classroom): bool
    {
        return $user->isStudent() && ! $classroom->isReadOnly()
            && $classroom->hasAcceptedMember($user->id);
    }
    public function view(User $user, PartnerRequest $request): bool
    {
        return $request->from_user_id === $user->id || $request->to_user_id === $user->id;
    }

    public function respond(User $user, PartnerRequest $request): bool
    {
        return $request->to_user_id === $user->id && ! $request->classroom->isReadOnly();
    }
}
