<?php

namespace App\Enums;

/** RG-11 : seuls les quiz publiés sont visibles des étudiants. */
enum QuizStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}
