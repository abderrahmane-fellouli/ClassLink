<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * §16 « Fichiers dangereux » — T-21, RG-12.
 *
 * Le type MIME déclaré par le client et l'extension ne suffisent pas à
 * garantir qu'un dépôt est inoffensif : un document HTML renommé « .pdf »
 * était accepté puis servi en `text/html`, donc exécuté par le navigateur.
 *
 * On contrôle donc le **contenu réel** en trois temps :
 *
 *  1. le type actif détecté dans les octets (HTML, XHTML, SVG, PHP, JS,
 *     shell, exécutable, archive Java…) entraîne toujours un refus, quelle
 *     que soit l'extension ;
 *  2. pour un format binaire, la signature de début doit correspondre à la
 *     famille annoncée par l'extension (`%PDF-`, OLE2, ZIP) ;
 *  3. pour un format texte (txt, csv), le contenu doit être de l'UTF-8
 *     valide et sans balise active.
 *
 * La liste blanche de `config('classlink.files')` n'est pas modifiée : ce
 * contrôle n'ajoute que des refus.
 */
class FileContentValidator
{
    /**
     * Signature attendue, par famille binaire.
     */
    private const MAGIC = [
        'pdf' => ['%PDF-'],
        // .doc / .xls / .ppt anciens (conteneur OLE2).
        'ole2' => ["\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1"],
        // .docx / .xlsx / .pptx / .odt / .odp (conteneur ZIP).
        'zip' => ["PK\x03\x04", "PK\x05\x06", "PK\x07\x08"],
    ];

    /** Extension de la liste blanche -> famille de contrôle. */
    private const FAMILY = [
        'pdf' => 'pdf',
        'doc' => 'ole2',
        'xls' => 'ole2',
        'ppt' => 'ole2',
        'docx' => 'zip',
        'xlsx' => 'zip',
        'pptx' => 'zip',
        'odt' => 'zip',
        'odp' => 'zip',
        'txt' => 'text',
        'csv' => 'text',
    ];

    /**
     * Types qu'un navigateur peut exécuter comme document actif. Détectés
     * dans le contenu : le refus est immédiat, extension notwithstanding.
     */
    private const ACTIVE_MIMES = [
        'text/html',
        'application/xhtml+xml',
        'image/svg+xml',
        'application/x-httpd-php',
        'text/x-php',
        'application/x-php',
        'text/javascript',
        'application/javascript',
        'application/x-javascript',
        'text/ecmascript',
        'application/ecmascript',
        'application/x-sh',
        'text/x-shellscript',
        'application/x-csh',
        'application/x-bat',
        'application/java-archive',
        'application/x-java-archive',
        'application/x-mach-binary',
        'application/x-executable',
        'application/x-dosexec',
        'application/vnd.microsoft.portable-executable',
        'application/x-msdownload',
        'application/x-msi',
        'application/x-ms-shortcut',
        'application/x-shockwave-flash',
    ];

    /** Marqueurs de contenu actif dans un fichier texte. */
    private const ACTIVE_MARKERS = [
        '<!doctype html',
        '<html',
        '<script',
        '<iframe',
        '<object',
        '<embed',
        '<svg',
        '<?php',
        '<?=',
        '<%',
    ];

    /**
     * Vérifie que le contenu correspond au type autorisé.
     *
     * Le contrôle porte sur les octets lus depuis le dépôt lui-même (et non
     * sur un second `fopen` du chemin) : pas de réouverture, donc pas de
     * course entre la validation et le stockage.
     *
     * @throws BusinessRuleException 422
     */
    public function assertMatchesType(UploadedFile $file, string $extension): void
    {
        $content = $this->read($file);
        $detected = $this->detectMime($content);
        $family = self::FAMILY[Str::lower($extension)] ?? null;

        // 1. Un contenu actif est refusé quoi qu'il en coûte.
        if ($detected !== null && in_array($detected, self::ACTIVE_MIMES, true)) {
            throw $this->refuse($extension, $detected);
        }

        // 2. Format binaire : la signature doit être celle de l'extension.
        if ($family === 'pdf' || $family === 'ole2' || $family === 'zip') {
            if (! $this->startsWith($content, self::MAGIC[$family])) {
                throw $this->refuse($extension, $detected);
            }

            return;
        }

        // 3. Format texte : UTF-8 valide, sans balise active.
        if ($family === 'text') {
            $this->assertPlainText($content, $extension, $detected);

            return;
        }

        // Extension hors liste blanche : la liste blanche l'exclut déjà, ce
        // garde-fou ferme la porte si la configuration change.
        throw $this->refuse($extension, $detected);
    }

    /** Type MIME réel, d'après les octets et non d'après le nom. */
    public function detectMime(string $content): ?string
    {
        if ($content === '') {
            return null;
        }

        $finfo = @finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            return null;
        }

        $mime = finfo_buffer($finfo, $content);
        finfo_close($finfo);

        return is_string($mime) && $mime !== '' ? Str::lower($mime) : null;
    }

    private function read(UploadedFile $file): string
    {
        try {
            return (string) $file->get();
        } catch (\Throwable) {
            // Fichier illisible : on ne peut pas prouver qu'il est inoffensif,
            // donc on le refuse.
            return '';
        }
    }

    private function startsWith(string $content, array $signatures): bool
    {
        foreach ($signatures as $signature) {
            if (str_starts_with($content, $signature)) {
                return true;
            }
        }

        return false;
    }

    private function assertPlainText(string $content, string $extension, ?string $detected): void
    {
        // Un « texte » qui n'est pas de l'UTF-8 valide (PDF, exécutable
        // déguisé…) est refusé.
        if (! mb_check_encoding($content, 'UTF-8')) {
            throw $this->refuse($extension, $detected);
        }

        // Défense supplémentaire : aucun marqueur de document actif.
        $haystack = Str::lower($content);

        foreach (self::ACTIVE_MARKERS as $marker) {
            if (str_contains($haystack, $marker)) {
                throw $this->refuse($extension, $detected);
            }
        }
    }

    private function refuse(string $extension, ?string $detected): BusinessRuleException
    {
        return new BusinessRuleException(__('api.files.content_mismatch'), 422, [
            'allowed_extensions' => array_keys(self::FAMILY),
            'detected_type' => $detected,
        ]);
    }
}