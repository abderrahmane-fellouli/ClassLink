<?php

namespace App\Services;

use App\Contracts\PdfTextExtractor;
use App\Exceptions\PdfExtractionException;

/**
 * §15.3 — extraction du texte d'un PDF de cours.
 *
 * L'analyse PDF est portée par `smalot/pdfparser`, dans `require`.
 * Si la bibliotheque n'est pas
 * presente, on leve une exception explicite : mieux vaut un message clair
 * (-> creation manuelle) qu'une generation IA silencieusement vide.
 */
class SmalotPdfTextExtractor implements PdfTextExtractor
{
    public function extract(string $absolutePath): array
    {
        if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
            throw new PdfExtractionException(__('api.ai.pdf_not_found'));
        }

        $parser = '\Smalot\PdfParser\Parser';

        if (! class_exists($parser)) {
            throw new PdfExtractionException(__('api.ai.pdf_not_configured'));
        }

        try {
            $document = (new $parser)->parseFile($absolutePath);
        } catch (\Throwable $e) {
            throw new PdfExtractionException(__('api.ai.pdf_corrupted'));
        }

        $text = trim((string) $document->getText());

        if ($text === '') {
            throw new PdfExtractionException(__('api.ai.pdf_unreadable'));
        }

        $pages = $document->getPages();

        return [
            'text' => $text,
            'page_count' => is_countable($pages) ? count($pages) : 0,
        ];
    }
}
