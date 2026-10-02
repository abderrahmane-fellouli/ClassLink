<?php

namespace App\Exceptions;

use Exception;

/**
 * §15.3 / F-IA-06 — l'extraction du texte d'un PDF a échoué.
 *
 * Cette exception ne bloque jamais la création manuelle : l'enseignant reçoit
 * un message clair et peut saisir son contenu à la main.
 */
class PdfExtractionException extends Exception {}
