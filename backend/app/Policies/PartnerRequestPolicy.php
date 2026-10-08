<?php

namespace App\Policies;

use App\Models\Classroom;
use App\Models\PartnerRequest;
use App\Models\User;
use App\Services\SchoolAccess;

/** F-PAR-03 : seul le destinataire répond à une demande. */
class PartnerRequestPolicy
{
    public function create(User $user, Classroom $classroom): bool
    {
        if ($classroom->is_official) {
            $access = app(SchoolAccess::class);

            return $access->enrolled($user, $classroom) && ! $access->readOnly($classroom);
        }

        return $user->isStudent() && ! $classroom->isReadOnly()
            && $classroom->hasAcceptedMember($user->id);
    }

    public function view(User $user, PartnerRequest $request): bool
    {
        return $request->from_user_id === $user->id || $request->to_user_id === $user->id;
    }

    public function respond(User $user, PartnerRequest $request): bool
    {
        if ($request->classroom->is_official) {
            $access = app(SchoolAccess::class);

            return $request->to_user_id === $user->id && $request->fromUser
                && $access->enrolled($user, $request->classroom) && $access->enrolled($request->fromUser, $request->classroom)
                && ! $access->readOnly($request->classroom);
        }

        return $request->to_user_id === $user->id && ! $request->classroom->isReadOnly();
    }
}
