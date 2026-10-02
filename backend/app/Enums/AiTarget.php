<?php

namespace App\Enums;

/** §15.1 : l'IA produit un brouillon de quiz ou de flashcards. */
enum AiTarget: string
{
    case Quiz = 'quiz';
    case Flashcard = 'flashcard';
}
