<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Assignment;
use App\Models\Classroom;
use App\Models\Quiz;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * §16 « Fichiers dangereux : liste blanche de types, taille maximale, noms
 * nettoyés, stockage externe non exécutable ».
 *
 * T-21 : un .exe est refusé avec 422.
 * RG-12 : rien n'est servi publiquement — l'accès passe par une URL
 * temporaire après vérification de l'autorisation.
 */
class MaterialStorageService
{
    public function __construct(
        private readonly FileContentValidator $validator,
    ) {}

    public function disk(): string
    {
        return (string) config('filesystems.default', 'local');
    }

    /**
     * Valide puis stocke un fichier déposé.
     *
     * @param  \Illuminate\Http\UploadedFile  $file
     * @return array{path: string, name: string, mime: string, size: int}
     *
     * @throws BusinessRuleException
     */
    public function storeFile($file, string $prefix = 'materials'): array
    {
        $this->assertAllowed($file);

        // Nom nettoyé : UUID + extension. Le nom d'origine n'est jamais
        // utilisé comme chemin, ce qui élimine la traversée de répertoire et
        // l'exécution de contenu.
        $extension = Str::lower($file->getClientOriginalExtension());
        $safeName = (string) Str::uuid().'.'.$extension;
        $path = $file->storeAs($prefix, $safeName, ['disk' => $this->disk()]);

        if ($path === false) {
            throw new BusinessRuleException(__('api.files.storage_failed'), 500);
        }

        return [
            'path' => $path,
            'name' => Str::limit($file->getClientOriginalName(), 200, ''),
            'mime' => (string) $file->getClientMimeType(),
            'size' => (int) $file->getSize(),
        ];
    }

    /**
     * @param  \Illuminate\Http\UploadedFile  $file
     *
     * @throws BusinessRuleException
     */
    private function assertAllowed($file): void
    {
        $mimes = (array) config('classlink.files.material_mimes');
        $extensions = (array) config('classlink.files.material_extensions');
        $maxKb = (int) config('classlink.files.max_kb', 10240);

        $mime = (string) $file->getClientMimeType();
        $extension = Str::lower((string) $file->getClientOriginalExtension());

        // Liste blanche : ni le type MIME ni l'extension ne doivent être
        // absents. Un .exe est donc refusé -> 422 (T-21).
        if (! in_array($mime, $mimes, true) || ! in_array($extension, $extensions, true)) {
            throw new BusinessRuleException(
                __('api.files.type_not_allowed'),
                422,
                ['allowed_extensions' => $extensions]
            );
        }

        if ((int) $file->getSize() > $maxKb * 1024) {
            throw new BusinessRuleException(
                __('api.files.too_large', ['max' => $maxKb]),
                422,
                ['max_kb' => $maxKb]
            );
        }

        // RG-12 : l'extension et le type déclaré ne suffisent pas — un
        // document HTML renommé « .pdf » doit être refusé sur ses octets,
        // sinon il serait servi en text/html et s'exécuterait (§16).
        $this->validator->assertMatchesType($file, $extension);
    }

    /**
     * Le disque courant permet-il les URL signées ? (S3 oui, local non.)
     */
    public function supportsSignedUrls(): bool
    {
        return $this->disk() === 's3';
    }

    /**
     * URL temporaire signée, émise uniquement après contrôle d'accès.
     * Le disque reste privé.
     *
     * La branche S3 impose au navigateur les mêmes garanties que la
     * réponse locale : téléchargement forcé et type vérifié au dépôt, ce
     * qui évite que S3 serve une ressource en `text/html`.
     */
    public function temporaryUrl(string $path, int $minutes = 10, ?string $mimeType = null): string
    {
        if (! $this->supportsSignedUrls()) {
            throw new BusinessRuleException('Ce disque ne gère pas les URL signées.', 500);
        }

        return Storage::disk($this->disk())->temporaryUrl($path, now()->addMinutes($minutes), [
            'ResponseContentDisposition' => 'attachment',
            'ResponseContentType' => $this->safeMime($mimeType),
        ]);
    }

    /**
     * Sert un fichier du disque privé après un contrôle d'accès explicite.
     * Utilisé en développement, là où l'URL signée n'existe pas.
     *
     * RG-12 : le type servi est celui validé au dépôt, jamais celui que le
     * serveur déduit du contenu (`mimeType()` faisait exactement cela et
     * renvoyait `text/html` pour un fichier déposé sous un nom « .pdf »).
     * La réponse est par ailleurs non exécutable et non interpretables.
     *
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function download(string $path, ?string $fileName = null, ?string $mimeType = null)
    {
        $disk = Storage::disk($this->disk());

        if (! $disk->exists($path)) {
            throw new BusinessRuleException(__('api.files.not_found'), 404);
        }

        $name = $fileName ?: basename($path);

        // `Content-Type` est fourni explicitement : `$disk->response()` ne
        // fait alors plus appel au détecteur de contenu. La disposition est
        // « attachment » : le document est téléchargé, jamais rendu comme
        // page active. `nosniff` interdit au navigateur de deviner un autre
        // type, et le CSP neutralise tout usage en document embarqué.
        return $disk->response($path, $name, [
            'Content-Type' => $this->safeMime($mimeType),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox; frame-ancestors 'none'",
            // RG-12 : jamais de cache intermediaries sur un document prive.
            'Cache-Control' => 'private, no-store',
        ], 'attachment');
    }

    /**
     * Type servi : celui validé au dépôt s'il figure dans la liste blanche,
     * `application/octet-stream` sinon. Une valeur hors liste blanche ne
     * peut pas se produire par les voies normales — elle ne doit jamais
     * être servie telle quelle.
     */
    private function safeMime(?string $mimeType): string
    {
        $mimes = (array) config('classlink.files.material_mimes');

        return in_array((string) $mimeType, $mimes, true)
            ? (string) $mimeType
            : 'application/octet-stream';
    }

    public function delete(?string $path): void
    {
        if ($path) {
            Storage::disk($this->disk())->delete($path);
        }
    }

    // -------------------------------------------------------------------------
    // RG-16 : « Un devoir déposé après la date limite est marqué "en retard". »
    // -------------------------------------------------------------------------

    public function isLate(Assignment $assignment): bool
    {
        return $assignment->hasDeadline() && now()->gt($assignment->due_at);
    }

    public function storeSubmission($file, Submission $submission, string $prefix = 'submissions'): void
    {
        $this->assertAllowed($file);

        $extension = Str::lower($file->getClientOriginalExtension());
        $path = $file->storeAs(
            $prefix.'/'.$submission->assignment_id,
            (string) Str::uuid().'.'.$extension,
            ['disk' => $this->disk()]
        );

        $submission->update([
            'file_path' => (string) $path,
            'file_name' => Str::limit($file->getClientOriginalName(), 200, ''),
            'mime_type' => $file->getClientMimeType(),
            'file_size' => (int) $file->getSize(),
        ]);
    }

    // -------------------------------------------------------------------------
    // Aides de progression
    // -------------------------------------------------------------------------

    public function dueDateFor(?Carbon $dueAt): ?Carbon
    {
        return $dueAt;
    }

    public function studentIn(User $user, Classroom $classroom): bool
    {
        return $classroom->hasAcceptedMember($user->id);
    }

    public function quizzesDueFor(User $student): \Illuminate\Support\Collection
    {
        $classIds = $student->memberships()
            ->where('status', \App\Enums\MembershipStatus::Accepted->value)
            ->pluck('classroom_id');

        return Quiz::whereIn('classroom_id', $classIds)
            ->published()
            ->whereHas('attempts', fn ($q) => $q->where('student_id', $student->id))
            ->with('classroom')
            ->get();
    }
}
