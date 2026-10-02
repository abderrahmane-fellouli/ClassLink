<?php

namespace Tests\Feature;

use App\Models\Material;
use App\Models\Membership;
use App\Models\Submission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * T-21 — Fichiers (§16 « Fichiers dangereux »).
 *
 * Liste blanche de types, taille maximale, nom de fichier nettoyé, disque
 * privé non exécutable, téléchargement après contrôle d'accès.
 */
class FileUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeStorage();
    }

    // -- T-21 : un .exe est refusé avec 422 -----------------------------------

    public function test_t21_an_executable_material_is_refused(): void
    {
        [$classroom, $teacher] = $this->classWithMember();

        $this->actingAs($teacher)
            ->post("/api/classes/{$classroom->id}/materials", [
                'title' => 'Fichier piégé',
                'file' => UploadedFile::fake()->create('virus.exe', 40),
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Type de fichier non autorisé.');

        $this->assertSame(0, Material::count());
    }

    public function test_t21_an_executable_submission_is_refused(): void
    {
        [$classroom, $teacher, $student] = $this->classWithMember();
        $assignment = $this->assignment($classroom, $teacher);

        $this->actingAs($student)
            ->post("/api/assignments/{$assignment->id}/submissions", [
                'file' => UploadedFile::fake()->create('virus.exe', 40),
            ])
            ->assertStatus(422);

        $this->assertSame(0, Submission::count());
    }

    public function test_t21_a_pdf_is_accepted(): void
    {
        [$classroom, $teacher] = $this->classWithMember();

        $this->actingAs($teacher)
            ->post("/api/classes/{$classroom->id}/materials", [
                'title' => 'Cours chapitre 1',
                'file' => $this->fakeUpload('cours.pdf', 'application/pdf', 120),
            ])
            ->assertStatus(201)
            ->assertJsonPath('type', 'file')
            ->assertJsonPath('file_name', 'cours.pdf');

        $this->assertSame(1, Material::count());
    }

    public function test_t21_an_ai_generation_rejects_a_non_pdf(): void
    {
        [$classroom, $teacher] = $this->classWithMember();

        $this->actingAs($teacher)
            ->post("/api/classes/{$classroom->id}/ai/generate", [
                'file' => $this->fakeUpload('notes.docx', 'application/msword', 50),
            ])
            ->assertStatus(422);

        $this->assertSame(0, \App\Models\AiJob::count());
    }

    /**
     * @dataProvider dangerousUploads
     */
    public function test_t21_dangerous_extensions_are_all_refused(string $name, string $mime): void
    {
        [$classroom, $teacher] = $this->classWithMember();

        $this->actingAs($teacher)
            ->post("/api/classes/{$classroom->id}/materials", [
                'title' => 'Test',
                'file' => UploadedFile::fake()->create($name, 10, $mime),
            ])
            ->assertStatus(422);

        $this->assertSame(0, Material::count());
    }

    public static function dangerousUploads(): array
    {
        return [
            'windows executable' => ['setup.exe', 'application/octet-stream'],
            'script' => ['run.sh', 'application/x-sh'],
            'html with script' => ['page.html', 'text/html'],
            'php' => ['shell.php', 'application/x-httpd-php'],
            'javascript' => ['app.js', 'text/javascript'],
        ];
    }

    // -- Taille maximale ------------------------------------------------------

    public function test_a_file_over_the_size_limit_is_refused(): void
    {
        [$classroom, $teacher] = $this->classWithMember();

        config(['classlink.files.max_kb' => 100]);

        $this->actingAs($teacher)
            ->post("/api/classes/{$classroom->id}/materials", [
                'title' => 'Trop gros',
                'file' => $this->fakeUpload('cours.pdf', 'application/pdf', 500),
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Fichier trop volumineux (maximum 100 Ko).');
    }

    public function test_a_file_at_the_size_limit_is_accepted(): void
    {
        [$classroom, $teacher] = $this->classWithMember();

        config(['classlink.files.max_kb' => 100]);

        $this->actingAs($teacher)
            ->post("/api/classes/{$classroom->id}/materials", [
                'title' => 'Juste assez gros',
                'file' => $this->fakeUpload('cours.pdf', 'application/pdf', 100),
            ])
            ->assertStatus(201);
    }

    // -- Nom de fichier nettoyé (§16) -----------------------------------------

    public function test_the_original_name_is_never_used_as_a_path(): void
    {
        [$classroom, $teacher] = $this->classWithMember();

        $this->actingAs($teacher)
            ->post("/api/classes/{$classroom->id}/materials", [
                'title' => 'Traversal',
                'file' => $this->fakeUpload('../../evil.pdf', 'application/pdf', 10),
            ])
            ->assertStatus(201);

        $material = Material::firstOrFail();

        // Le nom d'origine est conservé pour l'affichage, jamais comme chemin.
        $this->assertStringNotContainsString('..', $material->path_or_url);
        $this->assertStringStartsWith('materials/', $material->path_or_url);
        $this->assertMatchesRegularExpression(
            '/^materials\/[0-9a-f-]{36}\.pdf$/',
            $material->path_or_url
        );
    }

    public function test_a_material_without_extension_is_refused(): void
    {
        [$classroom, $teacher] = $this->classWithMember();

        // Extension absente de la liste blanche -> refus.
        $this->actingAs($teacher)
            ->post("/api/classes/{$classroom->id}/materials", [
                'title' => 'Sans extension',
                'file' => UploadedFile::fake()->create('fichier', 10, 'application/pdf'),
            ])
            ->assertStatus(422);
    }

    // -- Disque privé (RG-12) -------------------------------------------------

    public function test_the_file_is_stored_on_the_private_disk(): void
    {
        [$classroom, $teacher] = $this->classWithMember();

        $this->actingAs($teacher)
            ->post("/api/classes/{$classroom->id}/materials", [
                'title' => 'Cours',
                'file' => $this->fakeUpload('cours.pdf', 'application/pdf', 10),
            ])
            ->assertStatus(201);

        $material = Material::firstOrFail();

        Storage::disk((string) config('filesystems.default'))
            ->assertExists($material->path_or_url);
    }

    public function test_the_material_list_never_exposes_a_signed_url(): void
    {
        [$classroom, $teacher] = $this->classWithMember();

        $this->actingAs($teacher)
            ->post("/api/classes/{$classroom->id}/materials", [
                'title' => 'Cours',
                'file' => $this->fakeUpload('cours.pdf', 'application/pdf', 10),
            ]);

        $material = Material::firstOrFail();

        $body = $this->actingAs($teacher)
            ->getJson("/api/classes/{$classroom->id}/materials")
            ->assertOk()
            ->json('data');

        $this->assertTrue($body[0]['has_file']);
        $this->assertNull($body[0]['url']);
        $this->assertStringEndsWith(
            "/api/materials/{$material->id}/download",
            $body[0]['download_endpoint']
        );
    }

    // -- Téléchargement après contrôle d'accès --------------------------------

    public function test_an_accepted_member_can_download_the_file(): void
    {
        [$classroom, $teacher, $student] = $this->classWithMember();

        $this->actingAs($teacher)
            ->post("/api/classes/{$classroom->id}/materials", [
                'title' => 'Cours',
                'file' => $this->fakeUpload('cours.pdf', 'application/pdf', 10),
            ]);

        $material = Material::firstOrFail();

        $this->actingAs($student)
            ->get("/api/materials/{$material->id}/download")
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_a_student_outside_the_class_cannot_download(): void
    {
        [$classroom, $teacher] = $this->classWithMember();
        $outsider = $this->student();

        $this->actingAs($teacher)
            ->post("/api/classes/{$classroom->id}/materials", [
                'title' => 'Cours',
                'file' => $this->fakeUpload('cours.pdf', 'application/pdf', 10),
            ]);

        $material = Material::firstOrFail();

        $this->actingAs($outsider)
            ->getJson("/api/materials/{$material->id}/download")
            ->assertStatus(403);
    }

    public function test_a_pending_member_cannot_download(): void
    {
        [$classroom, $teacher] = $this->classWithMember();
        $pending = $this->student();

        Membership::create([
            'classroom_id' => $classroom->id,
            'student_id' => $pending->id,
            'status' => 'pending',
            'requested_at' => now(),
        ]);

        $this->actingAs($teacher)
            ->post("/api/classes/{$classroom->id}/materials", [
                'title' => 'Cours',
                'file' => $this->fakeUpload('cours.pdf', 'application/pdf', 10),
            ]);

        $material = Material::firstOrFail();

        $this->actingAs($pending)
            ->getJson("/api/materials/{$material->id}/download")
            ->assertStatus(403);
    }

    public function test_a_link_material_returns_its_url(): void
    {
        [$classroom, $teacher] = $this->classWithMember();

        $this->actingAs($teacher)
            ->post("/api/classes/{$classroom->id}/materials", [
                'title' => 'Ressource externe',
                'type' => 'link',
                'url' => 'https://example.org/cours',
            ])
            ->assertStatus(201);

        $material = Material::firstOrFail();

        $this->actingAs($teacher)
            ->getJson("/api/materials/{$material->id}/download")
            ->assertOk()
            ->assertJsonPath('url', 'https://example.org/cours');
    }

    public function test_downloading_a_missing_file_returns_404(): void
    {
        [$classroom, $teacher] = $this->classWithMember();

        $this->actingAs($teacher)
            ->post("/api/classes/{$classroom->id}/materials", [
                'title' => 'Cours',
                'file' => $this->fakeUpload('cours.pdf', 'application/pdf', 10),
            ]);

        $material = Material::firstOrFail();

        Storage::disk((string) config('filesystems.default'))->delete($material->path_or_url);

        $this->actingAs($teacher)
            ->getJson("/api/materials/{$material->id}/download")
            ->assertStatus(404);
    }

    public function test_downloading_a_material_is_audited(): void
    {
        [$classroom, $teacher] = $this->classWithMember();

        $this->actingAs($teacher)
            ->post("/api/classes/{$classroom->id}/materials", [
                'title' => 'Cours',
                'file' => $this->fakeUpload('cours.pdf', 'application/pdf', 10),
            ]);

        $material = Material::firstOrFail();

        $this->actingAs($teacher)->get("/api/materials/{$material->id}/download")->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $teacher->id,
            'action' => 'material.download',
        ]);
    }

    // -- Remises de devoirs ----------------------------------------------------

    public function test_a_teacher_can_download_a_submission(): void
    {
        [$classroom, $teacher, $student] = $this->classWithMember();
        $assignment = $this->assignment($classroom, $teacher);

        $this->actingAs($student)
            ->post("/api/assignments/{$assignment->id}/submissions", [
                'file' => $this->fakeUpload('reponse.pdf', 'application/pdf', 10),
            ])
            ->assertStatus(201);

        $submission = Submission::firstOrFail();

        $this->actingAs($teacher)
            ->get("/api/submissions/{$submission->id}/download")
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $teacher->id,
            'action' => 'submission.download',
        ]);
    }

    public function test_another_student_cannot_download_someone_elses_submission(): void
    {
        [$classroom, $teacher, $student] = $this->classWithMember();
        $assignment = $this->assignment($classroom, $teacher);
        $other = $this->student();

        $this->actingAs($student)
            ->post("/api/assignments/{$assignment->id}/submissions", [
                'file' => $this->fakeUpload('reponse.pdf', 'application/pdf', 10),
            ]);

        $submission = Submission::firstOrFail();

        $this->actingAs($other)
            ->getJson("/api/submissions/{$submission->id}/download")
            ->assertStatus(403);
    }

    public function test_a_submission_without_a_file_is_rejected(): void
    {
        [$classroom, $teacher, $student] = $this->classWithMember();
        $assignment = $this->assignment($classroom, $teacher);

        $this->actingAs($student)
            ->postJson("/api/assignments/{$assignment->id}/submissions", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->assertSame(0, Submission::count());
    }

    public function test_downloading_a_submission_whose_file_is_gone_returns_404(): void
    {
        [$classroom, $teacher, $student] = $this->classWithMember();
        $assignment = $this->assignment($classroom, $teacher);

        $this->actingAs($student)
            ->post("/api/assignments/{$assignment->id}/submissions", [
                'file' => $this->fakeUpload('reponse.pdf', 'application/pdf', 10),
            ]);

        $submission = Submission::firstOrFail();
        $submission->update(['file_path' => '']);

        $this->actingAs($teacher)
            ->getJson("/api/submissions/{$submission->id}/download")
            ->assertStatus(404);
    }

    // -- Contenu réellement contrôlé (RG-12 / §16) ---------------------------
    //
    // L'extension et le type MIME déclarés par le client ne suffisent pas :
    // un document HTML renommé « .pdf » était stocké puis servi en
    // text/html, donc exécuté par le navigateur (XSS stocké).

    public function test_html_content_named_pdf_is_refused(): void
    {
        [$classroom, $teacher] = $this->classWithMember();

        $this->actingAs($teacher)
            ->post("/api/classes/{$classroom->id}/materials", [
                'title' => 'Faux PDF',
                'file' => $this->uploadWithContent(
                    'polyglot.pdf',
                    '<!DOCTYPE html><html><body><script>alert(document.domain)</script></body></html>',
                    'application/pdf',
                ),
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Contenu du fichier invalide : il ne correspond pas au type annoncé.');

        $this->assertSame(0, Material::count());
    }

    public function test_php_content_named_pdf_is_refused(): void
    {
        [$classroom, $teacher] = $this->classWithMember();

        $this->actingAs($teacher)
            ->post("/api/classes/{$classroom->id}/materials", [
                'title' => 'Webshell',
                'file' => $this->uploadWithContent(
                    'shell.pdf',
                    "<?php system(\$_GET['c']); ?>",
                    'application/pdf',
                ),
            ])
            ->assertStatus(422);

        $this->assertSame(0, Material::count());
    }

    public function test_html_content_named_txt_is_refused(): void
    {
        [$classroom, $teacher] = $this->classWithMember();

        $this->actingAs($teacher)
            ->post("/api/classes/{$classroom->id}/materials", [
                'title' => 'Faux TXT',
                'file' => $this->uploadWithContent(
                    'notes.txt',
                    '<!DOCTYPE html><html><body>onclick="alert(1)"</body></html>',
                    'text/plain',
                ),
            ])
            ->assertStatus(422);

        $this->assertSame(0, Material::count());
    }

    public function test_svg_content_named_pdf_is_refused(): void
    {
        [$classroom, $teacher] = $this->classWithMember();

        $this->actingAs($teacher)
            ->post("/api/classes/{$classroom->id}/materials", [
                'title' => 'SVG',
                'file' => $this->uploadWithContent(
                    'logo.pdf',
                    '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
                    'application/pdf',
                ),
            ])
            ->assertStatus(422);

        $this->assertSame(0, Material::count());
    }

    /**
     * @dataProvider allowedDocuments
     */
    public function test_a_valid_allowed_document_is_accepted(string $name, string $mime): void
    {
        [$classroom, $teacher] = $this->classWithMember();

        $this->actingAs($teacher)
            ->post("/api/classes/{$classroom->id}/materials", [
                'title' => 'Document valide',
                'file' => $this->fakeUpload($name, $mime),
            ])
            ->assertStatus(201)
            ->assertJsonPath('file_name', $name);

        $this->assertSame(1, Material::count());
    }

    public static function allowedDocuments(): array
    {
        return [
            'pdf' => ['cours.pdf', 'application/pdf'],
            'docx' => ['notes.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            'doc' => ['notes.doc', 'application/msword'],
            'odt' => ['notes.odt', 'application/vnd.oasis.opendocument.text'],
            'txt' => ['notes.txt', 'text/plain'],
            'csv' => ['notes.csv', 'text/csv'],
        ];
    }

    public function test_html_content_named_pdf_is_refused_for_a_submission(): void
    {
        [$classroom, $teacher, $student] = $this->classWithMember();
        $assignment = $this->assignment($classroom, $teacher);

        $this->actingAs($student)
            ->post("/api/assignments/{$assignment->id}/submissions", [
                'file' => $this->uploadWithContent(
                    'rendu.pdf',
                    '<html><body><script>alert(1)</script></body></html>',
                    'application/pdf',
                ),
            ])
            ->assertStatus(422);

        $this->assertSame(0, Submission::count());
    }

    // -- Réponse de téléchargement non exécutable ----------------------------

    public function test_a_download_is_served_with_safe_headers(): void
    {
        [$classroom, $teacher, $student] = $this->classWithMember();

        $this->actingAs($teacher)
            ->post("/api/classes/{$classroom->id}/materials", [
                'title' => 'Cours',
                'file' => $this->fakeUpload('cours.pdf', 'application/pdf'),
            ]);

        $material = Material::firstOrFail();

        $response = $this->actingAs($student)->get("/api/materials/{$material->id}/download")->assertOk();

        $response->assertHeader('Content-Type', 'application/pdf');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringContainsString(
            'attachment',
            (string) $response->headers->get('Content-Disposition'),
        );
        $this->assertStringContainsString(
            "sandbox",
            (string) $response->headers->get('Content-Security-Policy'),
        );
    }

    /**
     * Le-poignet du correctif : même un fichier placed directement sur le
     * disque (donc jamais validé) ne peut pas être servi comme page active.
     * C'est le sink qui est protégé, pas seulement l'entrée.
     */
    public function test_html_on_disk_is_never_served_as_an_active_document(): void
    {
        [$classroom, $teacher, $student] = $this->classWithMember();

        $path = 'materials/leaked.pdf';
        Storage::disk((string) config('filesystems.default'))->put(
            $path,
            '<!DOCTYPE html><html><body><script>alert(document.domain)</script></body></html>',
        );

        $material = Material::create([
            'classroom_id' => $classroom->id,
            'title' => 'Fichier plante sur le disque',
            'type' => 'file',
            'path_or_url' => $path,
            'file_name' => 'leaked.pdf',
            // Type falsifié : c'est bien ce scénario qu'on veut couvrir.
            'mime_type' => 'application/pdf',
            'file_size' => 80,
            'uploaded_by' => $teacher->id,
        ]);

        $response = $this->actingAs($student)->get("/api/materials/{$material->id}/download")->assertOk();

        $response->assertHeader('Content-Type', 'application/pdf');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString(
            'attachment',
            (string) $response->headers->get('Content-Disposition'),
        );
        $this->assertStringNotContainsString('text/html', (string) $response->headers->get('Content-Type'));
    }

    public function test_a_stored_type_outside_the_whitelist_falls_back_to_octet_stream(): void
    {
        [$classroom, $teacher, $student] = $this->classWithMember();

        $material = Material::create([
            'classroom_id' => $classroom->id,
            'title' => 'Type hors liste blanche',
            'type' => 'file',
            'path_or_url' => 'materials/legacy.pdf',
            'file_name' => 'legacy.pdf',
            'mime_type' => 'text/html',
            'file_size' => 10,
            'uploaded_by' => $teacher->id,
        ]);

        Storage::disk((string) config('filesystems.default'))->put($material->path_or_url, '%PDF-1.7 ok');

        $this->actingAs($student)
            ->get("/api/materials/{$material->id}/download")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/octet-stream');
    }

    public function test_an_anonymous_download_is_refused_and_leaks_no_content(): void
    {
        [$classroom, $teacher] = $this->classWithMember();

        // La ressource est creee directement : `actingAs()` persiste sur le
        // test, la requete ci-dessous doit donc etre reellement anonyme.
        $material = Material::create([
            'classroom_id' => $classroom->id,
            'title' => 'Confidentiel',
            'type' => 'file',
            'path_or_url' => 'materials/confidentiel.pdf',
            'file_name' => 'confidentiel.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 10,
            'uploaded_by' => $teacher->id,
        ]);

        Storage::disk((string) config('filesystems.default'))
            ->put($material->path_or_url, '%PDF-1.7 confidentiel');

        $response = $this->getJson("/api/materials/{$material->id}/download")->assertUnauthorized();

        $this->assertStringNotContainsString('%PDF', $response->getContent());
    }

    // -- Erreurs de recharge ---------------------------------------------------

    private function uploadWithContent(string $name, string $content, string $mime): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content, $mime);
    }

    private function assignment(\App\Models\Classroom $classroom, \App\Models\User $teacher)
    {
        return \App\Models\Assignment::create([
            'classroom_id' => $classroom->id,
            'created_by' => $teacher->id,
            'title' => 'Devoir sur le cours',
            'instructions' => 'Rendre un PDF.',
            'due_at' => now()->addWeek(),
            'max_score' => 100,
        ]);
    }
}
