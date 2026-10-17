<?php

namespace App\Enums;

enum WorkspaceOwnershipState: string
{
    case Owned = 'owned';
    case UnassignedCompatibility = 'unassigned_compatibility';
    case RecoveryRequired = 'recovery_required';
}
