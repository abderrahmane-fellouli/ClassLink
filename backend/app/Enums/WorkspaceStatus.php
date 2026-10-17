<?php

namespace App\Enums;

enum WorkspaceStatus: string
{
    case Provisioning = 'provisioning';
    case Active = 'active';
    case Archived = 'archived';
}
