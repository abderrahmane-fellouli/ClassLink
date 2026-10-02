<?php

namespace App\Enums;

/** Statuts d'adhésion — §7 RG-06 à RG-10, Figure 4. */
enum MembershipStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Removed = 'removed';
}
