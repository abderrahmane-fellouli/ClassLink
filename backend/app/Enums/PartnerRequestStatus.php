<?php

namespace App\Enums;

/** F-PAR-03 : demande de contact partenaire. */
enum PartnerRequestStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
}
