<?php

namespace App\Workspace;

enum CapabilityAvailability: string
{
    case Implemented = 'implemented';
    case Future = 'future';
}
