<?php

namespace App\Enums;

enum WorkspaceMembershipStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Left = 'left';
}
