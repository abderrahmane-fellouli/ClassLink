<?php

namespace App\Contracts;

use App\Exceptions\PdfExtractionException;

/**
 * §15.3 — « seul le texte du cours est envoyé à l'IA ».
 *
 * Le contrat est isolé dans `app/Contracts` pour deux raisons :
 *  - le moteur d'extraction (smalot/pdf-parser) est un composant optionnel ;
 *  - il devient remplaçable en test, ce qui permet de couvrir T-18, T-19 et
 *    T-20 sans dépendance native ni accès réseau.
 */
interface PdfTextExtractor
{
    /**
     * @return array{text: string, page_count: int}
     *
     * @throws PdfExtractionException
     */
    public function extract(string $absolutePath): array;
}
