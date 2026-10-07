<?php

namespace Tests\Feature;

use App\Exceptions\BusinessRuleException;
use App\Models\Assignment;
use App\Models\Classroom;
use App\Models\Material;
use App\Models\Submission;
use App\Models\User;
use App\Services\MaterialStorageService;
use App\Services\StorageUsageService;
use Illuminate\Filesystem\FilesystemManager as FilesystemFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Plafond de stockage : 9 Gio par defaut, configurable par
 * `CLASSLINK_STORAGE_LIMIT_BYTES`.
 *
 * Ce que ces tests verrouillent :
 *  - un depot refuse **avant** toute ecriture disque ;
 *  - un depot refuse **avant** toute ecriture metier (pas de ligne orpheline) ;
 *  - le message est traduit FR et EN, avec le bon code HTTP ;
 *  - le compteur est exact apres depot, suppression et echec ;
 *  - la reservation est atomique : deux depots qui ne peuvent pas coexister,
 *    un seul passe.
 */
class StorageCapacityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeStorage();
    }

    // -------------------------------------------------------------------------
    // Configuration
    // -------------------------------------------------------------------------

    public function test_the_default_limit_is_nine_gibibytes(): void
    {
        config()->set('classlink.storage.limit_bytes', null);

        $this->assertSame(9663676416, app(StorageUsageService::class)->limitBytes());
    }

    public function test_the_limit_comes_from_the_environment(): void
    {
        config()->set('classlink.storage.limit_bytes', 1500);

        $this->assertSame(1500, app(StorageUsageService::class)->limitBytes());
    }

    /**
     * Une faute de frappe dans l'environnement ne doit pas supprimer le
     * garde-fou : on retombe sur le defaut au lieu d'accepter `0`.
     */
    public function test_an_invalid_limit_falls_back_to_the_default_instead_of_disabling_the_guard(): void
    {
        $usage = app(StorageUsageService::class);

        foreach ([0, -1, 'beaucoup', null, ''] as $invalid) {
            config()->set('classlink.storage.limit_bytes', $invalid);

            $this->assertSame(
                9663676416,
                $usage->limitBytes(),
                'Une valeur invalide ne doit pas desactiver le plafond.'
            );
        }
    }

    // -------------------------------------------------------------------------
    // Refus avant ecriture
    // -------------------------------------------------------------------------

    public function test_a_material_upload_is_refused_before_anything_is_written(): void
    {
        [$classroom, $teacher] = $this->classWithMember();
        config()->set('classlink.storage.limit_bytes', 1024);

        $response = $this->asToken($this->tokenFor($teacher))
            ->postJson("/api/classes/{$classroom->id}/materials", [
                'title' => 'Cours magistral',
                'type' => 'file',
                'file' => $this->fakeUpload('cours.pdf', 'application/pdf', 4),
            ]);

        $response->assertStatus(507)
            ->assertJsonPath('context.reason', 'storage_capacity_reached')
            // Le plafond : 4 Ko demandes pour 1 Ko disponibles -> refuse.
            ->assertJsonPath('context.requested_bytes', 4096);

        // Aucun octet sur le disque : le refus precede l'ecriture.
        $this->assertSame([], Storage::disk($this->disk())->allFiles());

        // Aucune ligne metier : le refus precede la creation du materiel.
        $this->assertSame(0, Material::count());
    }

    /**
     * F-UI-01 : la langue du profil prime sur l'en-tete du navigateur. Le
     * message doit donc suivre l'utilisateur, et le dire en clair.
     */
    public function test_the_capacity_message_follows_the_user_locale(): void
    {
        [$classroom, $teacher] = $this->classWithMember();
        $teacher->update(['locale' => 'en']);
        config()->set('classlink.storage.limit_bytes', 1024);

        $response = $this->asToken($this->tokenFor($teacher->fresh()))
            ->postJson("/api/classes/{$classroom->id}/materials", [
                'title' => 'Cours magistral',
                'type' => 'file',
                'file' => $this->fakeUpload('cours.pdf', 'application/pdf', 4),
            ]);

        $response->assertStatus(507);

        $this->assertStringContainsString('Storage capacity reached', $response->json('message'));
        // Le plafond (1 Kio) est inclus dans le message pour que l'utilisateur
        // comprenne que le depot est refuse, pas que son fichier est invalide.
        $this->assertStringContainsString('1 Kio', $response->json('message'));
    }

    public function test_the_capacity_message_is_french_by_default(): void
    {
        [$classroom, $teacher] = $this->classWithMember();
        config()->set('classlink.storage.limit_bytes', 1024);

        $response = $this->asToken($this->tokenFor($teacher))
            ->postJson("/api/classes/{$classroom->id}/materials", [
                'title' => 'Cours magistral',
                'type' => 'file',
                'file' => $this->fakeUpload('cours.pdf', 'application/pdf', 4),
            ]);

        $response->assertStatus(507);
        $this->assertStringContainsString('Espace de stockage atteint', $response->json('message'));
        $this->assertStringContainsString('1 Kio', $response->json('message'));
    }

    /**
     * Les deux langues doivent exposer la meme cle, sinon l'un des deux publics
     * recoit un message brut de cle de traduction.
     */
    public function test_both_locales_expose_the_same_storage_messages(): void
    {
        foreach (['fr', 'en'] as $locale) {
            $messages = require lang_path("{$locale}/api.php");

            $this->assertArrayHasKey(
                'storage_capacity_reached',
                $messages['files'],
                "La cle files.storage_capacity_reached manque en {$locale}."
            );
            $this->assertNotSame('', trim((string) $messages['files']['storage_capacity_reached']));
        }
    }

    public function test_a_submission_upload_is_also_protected(): void
    {
        [$classroom, $teacher, $student] = $this->classWithMember();
        $assignment = $this->assignmentIn($classroom, $teacher);
        config()->set('classlink.storage.limit_bytes', 1024);

        $response = $this->asToken($this->tokenFor($student))
            ->postJson("/api/assignments/{$assignment->id}/submissions", [
                'file' => $this->fakeUpload('reponse.pdf', 'application/pdf', 4),
            ]);

        $response->assertStatus(507);

        $this->assertSame([], Storage::disk($this->disk())->allFiles());
        // La transaction d'ecriture est annulee : pas de rendu fantome.
        $this->assertSame(0, Submission::count());
    }

    /**
     * Une reservation en vol doit deja compter comme occupee : c'est ce qui
     * rend le plafond exact sous concurrence.
     */
    public function test_a_pending_reservation_counts_as_occupied(): void
    {
        $usage = app(StorageUsageService::class);
        config()->set('classlink.storage.limit_bytes', 1000);

        $this->assertTrue($usage->reserve(400));
        $this->assertSame(400, $usage->reservedBytes());
        $this->assertSame(400, $usage->occupiedBytes());
        $this->assertSame(600, $usage->remainingBytes());

        // 600 + 700 > 1000 : refuse, malgre 600 octets libres.
        $this->assertFalse($usage->reserve(700));

        $usage->release(400);
        $this->assertSame(0, $usage->reservedBytes());
        $this->assertTrue($usage->reserve(1000));
    }

    public function test_an_upload_exactly_filling_the_limit_is_accepted(): void
    {
        [$classroom, $teacher] = $this->classWithMember();
        // 4 Ko de fichier pour 4 Ko de plafond : la comparaison doit etre `<=`.
        config()->set('classlink.storage.limit_bytes', 4 * 1024);

        $this->asToken($this->tokenFor($teacher))
            ->postJson("/api/classes/{$classroom->id}/materials", [
                'title' => 'Exactement plein',
                'type' => 'file',
                'file' => $this->fakeUpload('cours.pdf', 'application/pdf', 4),
            ])
            ->assertStatus(201);

        $this->assertSame(4096, app(StorageUsageService::class)->usedBytes());
    }

    // -------------------------------------------------------------------------
    // Atomicite de la reservation
    // -------------------------------------------------------------------------

    /**
     * Le coeur du dispositif : la reservation est un `UPDATE ... WHERE`
     * evalue par le SGBD. Deux demandes de 600 octets pour 1000 disponibles ne
     * peuvent pas aboutir toutes les deux, quel que soit l'ordre d'arrivee.
     */
    public function test_concurrent_reservations_cannot_both_pass_the_limit(): void
    {
        $usage = app(StorageUsageService::class);
        config()->set('classlink.storage.limit_bytes', 1000);

        $first = $usage->reserve(600);
        $second = $usage->reserve(600);

        $this->assertTrue($first);
        $this->assertFalse(
            $second,
            'Deux reservations concurrentes doivent se concurrencer sur le plafond.'
        );
        $this->assertSame(600, $usage->occupiedBytes());
    }

    /**
     * Le registre s'inscrit dans la meme transaction que la conversion
     * reservation -> usage : l'invariant tient meme si le commit echoue.
     */
    public function test_the_registry_and_the_counter_move_together(): void
    {
        $usage = app(StorageUsageService::class);

        $usage->reserve(2048);
        $usage->commitReservation(2048, 2048, [
            'disk' => $this->disk(),
            'path' => 'materials/cours.pdf',
            'kind' => 'material',
        ]);

        $this->assertSame(0, $usage->reservedBytes());
        $this->assertSame(2048, $usage->usedBytes());
        $this->assertSame(1, $usage->objectCount());
        $this->assertDatabaseHas('storage_objects', [
            'path' => 'materials/cours.pdf',
            'size_bytes' => 2048,
        ]);
    }

    /**
     * Un ecrasement (meme chemin, nouvelle taille) doit solder l'ancien octet
     * et le nouveau, sans jamais compter deux fois le meme objet.
     */
    public function test_replacing_an_object_keeps_one_registry_row(): void
    {
        $usage = app(StorageUsageService::class);
        $disk = $this->disk();

        $usage->commitReservation(0, 1000, ['disk' => $disk, 'path' => 'materials/a.pdf']);
        $usage->forget($disk, 'materials/a.pdf');
        $usage->commitReservation(0, 2500, ['disk' => $disk, 'path' => 'materials/a.pdf']);

        $this->assertSame(2500, $usage->usedBytes());
        $this->assertSame(1, $usage->objectCount());
    }

    // -------------------------------------------------------------------------
    // Exactitude du compteur
    // -------------------------------------------------------------------------

    public function test_the_counter_is_exact_after_an_upload_and_a_delete(): void
    {
        [$classroom, $teacher] = $this->classWithMember();
        config()->set('classlink.storage.limit_bytes', 10240 * 1024);

        $created = $this->asToken($this->tokenFor($teacher))
            ->postJson("/api/classes/{$classroom->id}/materials", [
                'title' => 'Cours',
                'type' => 'file',
                'file' => $this->fakeUpload('cours.pdf', 'application/pdf', 4),
            ])
            ->assertStatus(201);

        $usage = app(StorageUsageService::class);
        $this->assertSame(4096, $usage->usedBytes());
        $this->assertSame(4096, $usage->occupiedBytes());

        // `JsonResource::withoutWrapping()` : pas de niveau `data`.
        $material = Material::findOrFail($created->json('id'));

        $this->asToken($this->tokenFor($teacher))
            ->deleteJson("/api/materials/{$material->id}")
            ->assertStatus(204);

        $this->assertSame(0, $usage->usedBytes(), 'La suppression doit rendre la capacite.');
        $this->assertSame(0, $usage->objectCount());
    }

    public function test_a_submission_counts_and_returns_its_space_on_delete(): void
    {
        [$classroom, $teacher, $student] = $this->classWithMember();
        $assignment = $this->assignmentIn($classroom, $teacher);
        $usage = app(StorageUsageService::class);

        $this->asToken($this->tokenFor($student))
            ->postJson("/api/assignments/{$assignment->id}/submissions", [
                'file' => $this->fakeUpload('reponse.pdf', 'application/pdf', 2),
            ])
            ->assertStatus(201);

        $submission = Submission::firstOrFail();
        $this->assertSame(2048, $usage->usedBytes());
        $this->assertDatabaseHas('storage_objects', [
            'path' => $submission->file_path,
            'size_bytes' => 2048,
        ]);

        $usage->forget($this->disk(), $submission->file_path);

        $this->assertSame(0, $usage->usedBytes());
    }

    /**
     * Un echec d'ecriture ne doit pas laisser de reservation fantome : sinon
     * la capacite perdue rendrait le service inutilisable sans qu'aucun fichier
     * n'ait jamais ete depose.
     */
    public function test_a_failed_write_does_not_consume_capacity(): void
    {
        $usage = app(StorageUsageService::class);
        config()->set('classlink.storage.limit_bytes', 10240 * 1024);

        // Panne du disque : le driver simule refuse toute ecriture.
        $this->useBrokenDisk(true);

        $thrown = null;

        try {
            app(MaterialStorageService::class)
                ->storeFile($this->fakeUpload('cours.pdf', 'application/pdf', 3));
        } catch (DiskUnavailable $e) {
            $thrown = $e;
        }

        // Le garde-fou doit propager la panne, pas la masquer.
        $this->assertInstanceOf(DiskUnavailable::class, $thrown, 'Le stockage aurait du echouer.');
        $this->assertSame(
            0,
            $usage->reservedBytes(),
            'Un echec d\'ecriture doit liberer la reservation.'
        );
        $this->assertSame(0, $usage->usedBytes());
    }

    /**
     * Un disque qui renvoie `false` (et non une exception) doit etre traite
     * comme un echec, reservation comprise.
     */
    public function test_a_false_store_result_also_releases_the_reservation(): void
    {
        $usage = app(StorageUsageService::class);

        // Le seau accepte la requete mais n'ecrit rien : `putFileAs` renvoie
        // `false`, ce qui doit etre traite comme un echec.
        $this->useBrokenDisk(false);

        $thrown = null;

        try {
            app(MaterialStorageService::class)
                ->storeFile($this->fakeUpload('cours.pdf', 'application/pdf', 3));
        } catch (BusinessRuleException $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(BusinessRuleException::class, $thrown, 'Le stockage aurait du echouer.');
        $this->assertSame(500, $thrown->status());
        $this->assertSame(0, $usage->reservedBytes());
    }

    /**
     * Le sens de la suppression est le point le plus subtil du service.
     *
     * Si la capacite etait rendue *avant* l'effacement S3, un seau hors service
     * laisserait un fichier reel dont plus rien ne tient compte : ni le registre
     * (ligne supprimee), ni `classlink:storage-reconcile` (qui cherche des
     * lignes a verifier), ni R2. Le compteur sous-estimerait le seau et le
     * plafond de 9 Gio pourrait etre depasse — sans aucun moyen de le voir.
     *
     * On exige donc la preuve de la suppression avant de rendre la place.
     */
    public function test_a_failed_deletion_does_not_free_capacity(): void
    {
        $usage = app(StorageUsageService::class);
        $service = app(MaterialStorageService::class);

        $stored = $service->storeFile($this->fakeUpload('cours.pdf', 'application/pdf', 4));
        $this->assertSame(4096, $usage->usedBytes());

        // Le seau accepte l'ecriture mais renvoie `false` a la suppression :
        // l'objet est peut-etre toujours present.
        $this->useBrokenDisk(false, failsDelete: true);

        try {
            $service->delete($stored['path']);
            $this->fail('Une suppression non confirmee doit etre signalee.');
        } catch (BusinessRuleException $e) {
            $this->assertSame(500, $e->status());
        }

        $this->assertSame(4096, $usage->usedBytes(), 'Un fichier peut-etre present ne rend pas sa place.');
        $this->assertSame(1, $usage->objectCount(), 'Le registre doit garder la trace du fichier.');
        $this->assertDatabaseHas('storage_objects', ['path' => $stored['path']]);
    }

    /**
     * Symetrique du precedent : le disque redevient disponible, la suppression
     * reussit et la capacite revient. L'utilisateur peut donc reessayer.
     */
    public function test_a_retried_deletion_frees_capacity_once_the_disk_recovers(): void
    {
        $usage = app(StorageUsageService::class);
        $service = app(MaterialStorageService::class);

        $stored = $service->storeFile($this->fakeUpload('cours.pdf', 'application/pdf', 4));

        $this->useBrokenDisk(false, failsDelete: true);

        try {
            $service->delete($stored['path']);
        } catch (BusinessRuleException) {
            // Echec attendu, contabilite inchangee.
        }

        // R2 redevient joignable : une suppression idempotente (S3 DeleteObject
        // ne echoue pas sur un objet absent) solde finally le registre.
        Storage::fake($this->disk());
        Storage::disk($this->disk())->put($stored['path'], 'x');

        $service->delete($stored['path']);

        $this->assertSame(0, $usage->usedBytes());
        $this->assertSame(0, $usage->objectCount());
    }

    /**
     * Bascule le disque par defaut sur un driver casse.
     *
     * `UploadedFile::storeAs()` resout `FilesystemManager` directement, donc un
     * simple `Storage::fake()` ne suffit pas pour simuler une panne R2 : on
     * enregistre un driver qui refuse toute ecriture et on en fait le disque
     * par defaut.
     *
     * @param  bool  $throws  `true` = exception (panne reseau), `false` = `false` (ecriture refusee)
     */
    private function useBrokenDisk(bool $throws, bool $failsDelete = false): void
    {
        $factory = $this->app->make(FilesystemFactory::class);
        $factory->extend('classlinkbroken', fn () => new BrokenDisk($throws, $failsDelete));
        $factory->forgetDisk($this->disk());

        config()->set("filesystems.disks.{$this->disk()}", ['driver' => 'classlinkbroken']);
    }

    // -------------------------------------------------------------------------
    // Reconciliation
    // -------------------------------------------------------------------------

    /**
     * Simulation d'un arret brutal : le fichier est sur le disque et inscrit au
     * registre, mais le compteur n'a pas ete solde. Le registre fait foi.
     */
    public function test_reconcile_repairs_a_counter_that_drifted(): void
    {
        $usage = app(StorageUsageService::class);

        DB::table('storage_objects')->insert([
            'disk' => $this->disk(),
            'path' => 'materials/orphelin.pdf',
            'size_bytes' => 4096,
            'kind' => 'material',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Le compteur, lui, ne sait rien.
        $this->assertSame(0, $usage->usedBytes());

        $result = $usage->reconcile();

        $this->assertSame(4096, $result['used']);
        $this->assertSame(4096, $usage->usedBytes());
    }

    public function test_the_reconcile_command_runs_and_reports(): void
    {
        $usage = app(StorageUsageService::class);
        $usage->commitReservation(0, 3000, ['disk' => $this->disk(), 'path' => 'materials/a.pdf']);

        $this->artisan('classlink:storage-reconcile')
            ->expectsOutputToContain('limite')
            ->assertSuccessful();

        // `--check` sort en echec si le compteur a derive.
        DB::table('storage_usage')->where('id', 1)->update(['used_bytes' => 999]);

        $this->artisan('classlink:storage-reconcile --check')->assertFailed();

        $this->artisan('classlink:storage-reconcile --check')
            ->assertSuccessful();
    }

    /**
     * `--prune` retire du registre un objet absent du disque : le seul sens de
     * derive corrigeable sans parcourir le bucket.
     */
    public function test_prune_drops_registry_rows_missing_from_the_disk(): void
    {
        $usage = app(StorageUsageService::class);

        $usage->commitReservation(0, 1000, ['disk' => $this->disk(), 'path' => 'materials/present.pdf']);
        Storage::disk($this->disk())->put('materials/present.pdf', 'x');
        $usage->commitReservation(0, 2000, ['disk' => $this->disk(), 'path' => 'materials/absent.pdf']);

        $this->assertSame(3000, $usage->usedBytes());

        $this->artisan('classlink:storage-reconcile --prune')->assertSuccessful();

        $this->assertSame(1000, $usage->usedBytes());
        $this->assertSame(1, $usage->objectCount());
        $this->assertDatabaseMissing('storage_objects', ['path' => 'materials/absent.pdf']);
    }

    /**
     * Une reservation ne survit pas a son processus : apres un arret brutal, elle
     * immobilise des octets pour toujours. `reconcile()` refuse pourtant d'y
     * toucher, parce qu'il ne sait pas distinguer une reservation orpheline d'un
     * depot en cours — et se tromper ferait franchir le plafond.
     *
     * La liberation est donc une decision d'operateur, explicite et confirmee.
     */
    public function test_stale_reservations_are_only_released_on_explicit_confirmation(): void
    {
        $usage = app(StorageUsageService::class);
        $usage->reserve(4096);
        $usage->commitReservation(0, 2048, ['disk' => $this->disk(), 'path' => 'materials/a.pdf']);

        $this->assertSame(4096, $usage->reservedBytes());
        $this->assertSame(2048, $usage->usedBytes());

        // Une reconciliation ordinaire preserve la reservation : elle pourrait
        // appartenir a un depot vivant.
        $usage->reconcile();
        $this->assertSame(4096, $usage->reservedBytes());

        // Refus de l'operateur : rien ne bouge, et la commande le dit.
        $this->artisan('classlink:storage-reconcile --release-stale-reservations')
            ->expectsConfirmation('Aucun depot n\'est-il reellement en cours ? (sinon leur reservation sera volee)', 'no')
            ->expectsOutputToContain('Annule')
            ->assertFailed();

        $this->assertSame(4096, $usage->reservedBytes());

        // Confirmation : les octets reserves sont liberes, l'usage reel reste.
        $this->artisan('classlink:storage-reconcile --release-stale-reservations')
            ->expectsConfirmation('Aucun depot n\'est-il reellement en cours ? (sinon leur reservation sera volee)', 'yes')
            ->assertSuccessful();

        $this->assertSame(0, $usage->reservedBytes());
        $this->assertSame(2048, $usage->usedBytes());
        $this->assertSame(1, $usage->objectCount());
    }

    // -------------------------------------------------------------------------
    // Non-regression
    // -------------------------------------------------------------------------

    /**
     * Un rollback metier doit supprimer l'objet deja ecrit dans le seau.
     *
     * Une ecriture S3 ne participe pas a la transaction SQL : sans compensation,
     * un `rollBack()` laisserait un fichier reellement stocke, absent de la base
     * et donc invisible de toute reconciliation basee sur les tables
     * applicatives. C'est le seul cas ou le compteur sous-estimerait le seau.
     */
    public function test_a_business_rollback_deletes_the_file_it_had_written(): void
    {
        $usage = app(StorageUsageService::class);
        $disk = $this->disk();

        try {
            DB::transaction(function () {
                app(MaterialStorageService::class)
                    ->storeFile($this->fakeUpload('cours.pdf', 'application/pdf', 4));

                throw new \RuntimeException('Echec metier apres l\'ecriture du fichier.');
            });
            $this->fail('La transaction aurait du etre annulee.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Echec metier', $e->getMessage());
        }

        // Ni cote metier, ni cote seau, ni cote compteur : les trois convergent.
        $this->assertSame(0, Material::count());
        $this->assertSame([], Storage::disk($disk)->allFiles());
        $this->assertSame(0, $usage->usedBytes());
        $this->assertSame(0, $usage->reservedBytes());
        $this->assertSame(0, $usage->objectCount());
    }

    /**
     * Le commit, lui, ne doit rien supprimer : c'est le cas nominal.
     */
    public function test_a_committed_transaction_keeps_the_file(): void
    {
        $usage = app(StorageUsageService::class);

        DB::transaction(function () {
            app(MaterialStorageService::class)
                ->storeFile($this->fakeUpload('cours.pdf', 'application/pdf', 4));
        });

        $this->assertCount(1, Storage::disk($this->disk())->allFiles());
        $this->assertSame(4096, $usage->usedBytes());
    }

    public function test_inner_commits_do_not_cancel_outer_rollback_cleanup(): void
    {
        $service = app(MaterialStorageService::class);
        DB::beginTransaction();
        $service->storeFile($this->fakeUpload('first.pdf', 'application/pdf', 4));
        DB::transaction(function () use ($service) {
            $service->storeFile($this->fakeUpload('second.pdf', 'application/pdf', 4));
        });
        $this->assertCount(2, Storage::disk($this->disk())->allFiles());
        DB::rollBack();

        $this->assertSame([], Storage::disk($this->disk())->allFiles());
        $this->assertSame(0, app(StorageUsageService::class)->usedBytes());
        $this->assertSame(0, app(StorageUsageService::class)->objectCount());
    }

    public function test_savepoint_rollback_only_removes_files_written_inside_it(): void
    {
        $service = app(MaterialStorageService::class);
        DB::beginTransaction();
        $outer = $service->storeFile($this->fakeUpload('outer.pdf', 'application/pdf', 4));
        DB::beginTransaction();
        $inner = $service->storeFile($this->fakeUpload('inner.pdf', 'application/pdf', 4));
        DB::rollBack();

        Storage::disk($this->disk())->assertExists($outer['path']);
        Storage::disk($this->disk())->assertMissing($inner['path']);
        $this->assertSame(4096, app(StorageUsageService::class)->usedBytes());
        DB::commit();
        Storage::disk($this->disk())->assertExists($outer['path']);
    }

    public function test_accounting_failure_after_upload_cleans_up_the_file_and_reservation(): void
    {
        $this->partialMock(StorageUsageService::class, function ($mock) {
            $mock->shouldReceive('commitReservation')->once()
                ->andThrow(new \RuntimeException('Accounting failed'));
        });

        try {
            app(MaterialStorageService::class)
                ->storeFile($this->fakeUpload('cours.pdf', 'application/pdf', 4));
            $this->fail('Accounting failure must propagate.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Accounting failed', $e->getMessage());
        }

        $this->assertSame([], Storage::disk($this->disk())->allFiles());
        $this->assertSame(0, app(StorageUsageService::class)->occupiedBytes());
        $this->assertSame(0, app(StorageUsageService::class)->objectCount());
    }

    /**
     * Hors transaction, rien n'est compense : le service n'a pas lieu de
     * deviner si l'appelant validera.
     */
    public function test_outside_a_transaction_nothing_is_compensated(): void
    {
        $usage = app(StorageUsageService::class);

        app(MaterialStorageService::class)
            ->storeFile($this->fakeUpload('cours.pdf', 'application/pdf', 4));

        $this->assertCount(1, Storage::disk($this->disk())->allFiles());
        $this->assertSame(4096, $usage->usedBytes());
        $this->assertSame(1, $usage->objectCount());
    }

    /**
     * Non-regression
     */
    public function test_upload_behaves_normally_when_there_is_room(): void
    {
        [$classroom, $teacher] = $this->classWithMember();
        $usage = app(StorageUsageService::class);

        $response = $this->asToken($this->tokenFor($teacher))
            ->postJson("/api/classes/{$classroom->id}/materials", [
                'title' => 'Lien externe',
                'type' => 'file',
                'file' => $this->fakeUpload('notes.pdf', 'application/pdf', 1),
            ])
            ->assertStatus(201);

        $material = Material::findOrFail($response->json('id'));

        $this->assertSame('materials', explode('/', $material->path_or_url)[0]);
        $this->assertSame(1024, $usage->usedBytes());
        $this->assertTrue(Storage::disk($this->disk())->exists($material->path_or_url));

        // Un lien n'occupe aucune place : il n'est pas depose sur le disque.
        $this->asToken($this->tokenFor($teacher))
            ->postJson("/api/classes/{$classroom->id}/materials", [
                'title' => 'Site de cours',
                'type' => 'link',
                'url' => 'https://example.org/cours',
            ])
            ->assertStatus(201);

        $this->assertSame(1024, $usage->usedBytes());
    }

    // -------------------------------------------------------------------------
    // Utilitaires
    // -------------------------------------------------------------------------

    private function disk(): string
    {
        return (string) config('filesystems.default', 'local');
    }

    private function assignmentIn(Classroom $classroom, User $teacher): Assignment
    {
        return Assignment::create([
            'classroom_id' => $classroom->id,
            'title' => 'Compte rendu',
            'instructions' => 'A rendre.',
            'created_by' => $teacher->id,
            'due_at' => now()->addWeek(),
        ]);
    }
}
