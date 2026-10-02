<?php

namespace App\Enums;

/** F-CON-01 : une ressource est un fichier déposé ou un lien. */
enum MaterialType: string
{
    case File = 'file';
    case Link = 'link';
}
