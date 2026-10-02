<?php

namespace App\Policies;

use App\Models\Announcement;
use App\Models\User;

/** F-CON-04 — annonces. */
class AnnouncementPolicy
{
    public function view(User $user, Announcement $announcement): bool
    {
        return $this->manage($user, $announcement)
            || $announcement->classroom->hasAcceptedMember($user->id);
    }

    public function manage(User $user, Announcement $announcement): bool
    {
        return $announcement->classroom->isOwnedBy($user) && ! $announcement->classroom->isReadOnly();
    }

    public function update(User $user, Announcement $announcement): bool
    {
        return $this->manage($user, $announcement);
    }

    public function delete(User $user, Announcement $announcement): bool
    {
        return $this->update($user, $announcement);
    }
}
