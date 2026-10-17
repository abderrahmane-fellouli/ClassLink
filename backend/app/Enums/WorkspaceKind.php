<?php

namespace App\Enums;

enum WorkspaceKind: string
{
    case Personal = 'personal';
    case Organization = 'organization';
    case Institutional = 'institutional';
}
