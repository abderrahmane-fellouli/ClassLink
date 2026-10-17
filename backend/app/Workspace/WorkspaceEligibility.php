<?php

namespace App\Workspace;

use App\Models\User;

/** Applies only to new configuration authority; never changes existing User authorization. */
final class WorkspaceEligibility
{
    public static function canManage(User $user): bool
    {
        return $user->is_active && $user->role_locked && ($user->isTeacher() || $user->isAdmin());
    }
}
