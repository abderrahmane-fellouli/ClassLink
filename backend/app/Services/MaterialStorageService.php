<?php

namespace App\Services;

use App\Enums\MembershipStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Assignment;
use App\Models\Classroom;
use App\Models\Quiz;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

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
    /**
     * `StorageUsageService` n'est pas optionnel : tout depot passe par lui.
     * On ne propose donc pas de le passer `null` « pour un test » — un test
     * doit injecter le vrai service, avec une base configuree.
     */
    public function __construct(
        private readonly FileContentValidator $validator,
        private readonly StorageUsageService $usage,
    ) {}

    public function disk(): string
    {
        return (string) config('filesystems.default', 'local');
    }

    /**
     * Valide puis stocke un fichier déposé.
     *
     * @param  UploadedFile  $file
     * @return array{path: string, name: string, mime: string, size: int}
     *
     * @throws BusinessRuleException
     */
    public function storeFile($file, string $prefix = 'materials'): array
    {
        $this->assertAllowed($file);

        return $this->storeCounted($file, $prefix, $this->kindFor($prefix));
    }

    /**
     * Depose un fichier en respectant le plafond de stockage.
     *
     * L'ordre est imperatif : reservation **avant** toute ecriture, liberation
     * de la reservation si l'ecriture echoue. C'est la seule facon d'etre
     * exact sous concurrence — une ecriture S3 ne participe pas a une
     * transaction SQL, donc l'ecriture et le compteur ne peuvent pas etre
     * atomiques. La reservation rend la zone critique « espace occupe »
     * immediate : deux depots simultanes ne peuvent pas franchir le plafond.
     *
     * `assertCanStore()` est appele avant `reserve()` pour echouer tot et
     * clairement ; `reserve()` reste la seule operation qui fait foi, car elle
     * seule est evaluee sous verrou par le SGBD.
     *
     * Le nom de derive est un UUID : le nom d'origine n'est jamais utilise
     * comme chemin (§16).
     *
     * @param  UploadedFile  $file
     * @return array{path: string, name: string, mime: string, size: int}
     */
    private function storeCounted($file, string $prefix, string $kind, ?object $owner = null): array
    {
        $size = (int) $file->getSize();
        $disk = $this->disk();

        $this->usage->assertCanStore($size, $kind);

        if (! $this->usage->reserve($size)) {
            // Le plafond a pu etre atteint entre la lecture et la reservation
            // (depot concurrent). C'est ici que se joue l'exclusion reelle.
            throw $this->usage->capacityException($size, $kind);
        }

        try {
            $path = $file->storeAs($prefix, $this->safeName($file), ['disk' => $disk]);
        } catch (Throwable $e) {
            // Pas de reservation fantome apres un echec d'ecriture.
            $this->usage->release($size);

            throw $e;
        }

        if (! is_string($path)) {
            $this->usage->release($size);

            throw new BusinessRuleException(__('api.files.storage_failed'), 500);
        }

        // Le registre et la conversion reservation -> usage sont atomiques.
        try {
            $this->usage->commitReservation($size, $size, [
                'disk' => $disk,
                'path' => $path,
                'kind' => $kind,
                'owner_type' => $owner ? $owner::class : null,
                'owner_id' => $owner?->getKey(),
            ]);
        } catch (Throwable $e) {
            try {
                $this->purge($path, $disk);
                $this->usage->release($size);
            } catch (Throwable $cleanupError) {
                report($cleanupError);
            }
            throw $e;
        }

        /*
         * L'ecriture S3 ne peut pas etre annulee par un `DB::rollBack()` : si la
         * transaction metier echoue apres le depot (ligne `materials`,
         * `submissions` ou `ai_jobs`), le fichier resterait dans le seau sans
         * aucune trace en base. On branche donc sa compensation : un rollback
         * supprime l'objet, et les deux etats convergent.
         */
        $this->usage->onRollback(function () use ($path, $disk): void {
            $this->purge($path, $disk);
        });

        return [
            'path' => $path,
            'name' => Str::limit($file->getClientOriginalName(), 200, ''),
            'mime' => (string) ($file->getClientMimeType() ?: 'application/octet-stream'),
            'size' => $size,
        ];
    }

    /**
     * Nom de derive : UUID + extension.
     *
     * Le nom d'origine n'est **jamais** utilise comme chemin : c'est ce qui
     * elimine la traversee de repertoire et l'execution de contenu (§16). L'UUID
     * garantit l'unicite sans avoir a assainir le nom, donc deux eleves
     * peuvent deposer « cours.pdf » sans collision.
     */
    private function safeName($file): string
    {
        $extension = Str::lower((string) $file->getClientOriginalExtension());

        return (string) Str::uuid().'.'.$extension;
    }

    /**
     * Categorie de comptabilite, deduite du prefixe S3.
     *
     * Utile pour dire *quel* usage a rempli le seau dans le contexte 507.
     */
    private function kindFor(string $prefix): string
    {
        return match ($prefix) {
            'materials' => 'material',
            'submissions' => 'submission',
            'ai-inputs' => 'ai_input',
            default => 'other',
        };
    }

    /**
     * @param  UploadedFile  $file
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
     * @return StreamedResponse
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

    /**
     * Supprime un fichier et rend sa place au seau.
     *
     * L'ordre est dicté par le seul piege de ce service : on rend la place
     * **apres** avoir efface l'objet, jamais avant.
     *
     * Dans l'autre sens, un echec reseau rendrait des octets alors que le
     * fichier est toujours la — c'est le seul cas ou le compteur
     * *sous-estime* le seau, donc ou le plafond de 9 Gio peut etre depasse. Ce
     * defaut serait irrattrapable : l'objet n'ayant plus de ligne de registre,
     * `classlink:storage-reconcile` (qui interroge le disque via `exists()`)
     * n'a plus rien a purger.
     *
     * A l'inverse, un `forget()` qui echoue apres une suppression reussie
     * sur-estime le seau : le plafond tient toujours, et `--prune` sait
     * reparer le registre en verifiant `exists()`.
     *
     * Un retour `false` du seau signifie « l'objet est peut-etre encore la » :
     * on leve donc une erreur plutot que de liberer la capacite, et l'appelant
     * garde sa ligne metier (l'utilisateur peut reessayer).
     */
    public function delete(?string $path): void
    {
        if (! $path) {
            return;
        }

        $disk = $this->disk();

        if (Storage::disk($disk)->delete($path) === false) {
            throw new BusinessRuleException(__('api.files.storage_failed'), 500);
        }

        $this->usage->forget($disk, $path);
    }

    /**
     * Compense un rollback metier en supprimant l'objet deja ecrit.
     *
     * Volontairement sans `forget()` : la transaction vient d'etre annulee, le
     * registre et le compteur ne connaissent donc plus ce fichier.
     *
     * Aucune exception ne doit sortir d'ici : ce code tourne dans l'ecouteur
     * `TransactionRolledBack`, ou lever masquerait l'erreur metier d'origine.
     * `StorageUsageService` capture et journalise ; l'echec de suppression
     * laisse un orphelin necessitant une intervention cote bucket.
     */
    private function purge(string $path, string $disk): void
    {
        if (Storage::disk($disk)->delete($path) === false) {
            throw new BusinessRuleException(__('api.files.storage_failed'), 500);
        }
    }

    // -------------------------------------------------------------------------
    // RG-16 : « Un devoir déposé après la date limite est marqué "en retard". »
    // -------------------------------------------------------------------------

    public function isLate(Assignment $assignment): bool
    {
        return $assignment->hasDeadline() && now()->gt($assignment->due_at);
    }

    /**
     * Depose un rendu et l'attache a la ligne de `submissions`.
     *
     * Le prefixe S3 est deja partitionne par devoir, donc le registre peut
     * memoriser le proprietaire : une reconciliation peut ainsi reperer un
     * fichier orphelin apres la suppression d'un devoir.
     *
     * @param  UploadedFile  $file
     */
    public function storeSubmission($file, Submission $submission, string $prefix = 'submissions'): void
    {
        $this->assertAllowed($file);

        $stored = $this->storeCounted(
            $file,
            $prefix.'/'.$submission->assignment_id,
            $this->kindFor($prefix),
            $submission
        );

        $submission->update([
            'file_path' => $stored['path'],
            'file_name' => $stored['name'],
            'mime_type' => $stored['mime'],
            'file_size' => $stored['size'],
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

    public function quizzesDueFor(User $student): Collection
    {
        $classIds = $student->memberships()
            ->where('status', MembershipStatus::Accepted->value)
            ->pluck('classroom_id');

        return Quiz::whereIn('classroom_id', $classIds)
            ->published()
            ->whereHas('attempts', fn ($q) => $q->where('student_id', $student->id))
            ->with('classroom')
            ->get();
    }
}
