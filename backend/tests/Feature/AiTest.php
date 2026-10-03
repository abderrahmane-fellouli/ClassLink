<?php

namespace Tests\Feature;

use App\Contracts\PdfTextExtractor;
use App\Exceptions\AiUnavailableException;
use App\Exceptions\PdfExtractionException;
use App\Jobs\ProcessAiGeneration;
use App\Models\AiJob;
use App\Models\AiProvider;
use App\Models\AppNotification;
use App\Models\Classroom;
use App\Models\Quiz;
use App\Models\User;
use App\Services\AiService;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * T-18, T-19, T-20 — Intelligence artificielle (§15, F-IA-01 a F-IA-08).
 *
 * Les appels réseau sont simulés avec `Http::fake()` : aucun fournisseur réel
 * n'est contacté, aucune donnée d'utilisateur ne sort.
 */
class AiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeStorage();
    }


    /** Un fournisseur minimal, activé, avec une clé et un quota. */
    private function provider(string $name, int $priority, int $limit = 20): AiProvider
    {
        config([
            "services.ai.{$name}.key" => "cle-de-test-{$name}",
            "services.ai.{$name}.base_url" => "https://api.test.local/{$name}",
            "services.ai.{$name}.model" => 'model-test',
        ]);

        return AiProvider::create([
            'name' => $name,
            'priority' => $priority,
            'enabled' => true,
            'daily_limit' => $limit,
            'used_today' => 0,
        ]);
    }

    /** Reponse IA valide, conforme au format attendu. */
    private function validPayload(string $title = 'Quiz du cours'): array
    {
        return [
            'title' => $title,
            'questions' => [[
                'statement' => 'Quelle est la bonne reponse ?',
                'type' => 'single',
                'explanation' => 'Parce que.',
                'options' => [
                    ['label' => 'Bonne reponse', 'is_correct' => true],
                    ['label' => 'Mauvaise reponse', 'is_correct' => false],
                ],
            ]],
        ];
    }

    /**
     * Stub de reponse IA. `Http::fake()` attend une promesse, que
     * `Http::response()` renvoie deja.
     *
     * @return \GuzzleHttp\Promise\PromiseInterface
     */
    /**
     * Cree une tache IA avec un fichier reellement present sur le disque.
     *
     * On passe par le modele plutot que par l'endpoint pour piloter
     * l'execution : `dispatchAfterResponse()` tourne deja pendant la
     * requete, donc le declencher une seconde fois ici fausserait les
     * assertions (cache, quota).
     */
    private function createAiJob(Classroom $classroom, User $teacher, array $overrides = []): AiJob
    {
        // `Storage::put()` renvoie un booleen : le chemin se construit ici.
        $path = 'ai-inputs/cours-'.Str::random(8).'.pdf';
        Storage::disk((string) config('filesystems.default'))->put($path, '%PDF-1.4 contenu de test');

        return AiJob::create(array_merge([
            'teacher_id' => $teacher->id,
            'classroom_id' => $classroom->id,
            'target' => 'quiz',
            'file_hash' => hash('sha256', (string) $path),
            'original_name' => 'cours.pdf',
            'file_path' => $path,
            'status' => 'queued',
        ], $overrides));
    }

    private function aiResponse(array $payload)
    {
        return Http::response([
            'choices' => [[
                'message' => ['content' => json_encode($payload)],
            ]],
        ], 200);
    }

    // -- T-18 : 429 sur le premier, le second repond ---------------------------

    public function test_t18_falls_back_to_the_next_provider_on_429(): void
    {
        $first = $this->provider('premier', 1);
        $second = $this->provider('second', 2);

        Http::fake([
            'https://api.test.local/premier/*' => Http::response(['error' => 'rate limit'], 429),
            'https://api.test.local/second/*' => $this->aiResponse($this->validPayload()),
        ]);

        $result = app(AiService::class)->generate('texte du cours', 'hash-1');

        $this->assertTrue($result['ok']);
        $this->assertSame('second', $result['provider']);
        $this->assertFalse($result['cached']);

        // Le premier a consommé du quota, le second aussi (il a répondu).
        $this->assertSame(0, $first->fresh()->used_today);
        $this->assertSame(1, $second->fresh()->used_today);

        Http::assertSentCount(2);
    }

    public function test_t18_falls_back_on_server_error(): void
    {
        $this->provider('premier', 1);
        $this->provider('second', 2);

        Http::fake([
            'https://api.test.local/premier/*' => Http::response([], 500),
            'https://api.test.local/second/*' => $this->aiResponse($this->validPayload()),
        ]);

        $this->assertSame('second', app(AiService::class)->generate('texte', 'hash-2')['provider']);
    }

    public function test_a_failing_provider_is_skipped_during_the_cooldown(): void
    {
        $this->provider('premier', 1);
        $this->provider('second', 2);

        Http::fake([
            'https://api.test.local/premier/*' => Http::response([], 429),
            'https://api.test.local/second/*' => $this->aiResponse($this->validPayload()),
        ]);

        $service = app(AiService::class);
        $service->generate('texte', 'hash-3');

        // Nouvelle empreinte : le cache ne joue pas. Le premier fournisseur
        // est en refroidissement, il ne doit donc pas etre appele une
        // deuxieme fois (1 appel au total sur les deux generations).
        $service->generate('texte', 'hash-4');

        $premierCalls = 0;

        foreach (Http::recorded() as [$request]) {
            if (str_contains($request->url(), '/premier/')) {
                $premierCalls++;
            }
        }

        $this->assertSame(1, $premierCalls);
    }

    public function test_a_provider_without_quota_is_skipped(): void
    {
        $this->provider('premier', 1, limit: 5)->update(['used_today' => 5]);
        $this->provider('second', 2);

        Http::fake([
            'https://api.test.local/premier/*' => $this->aiResponse($this->validPayload()),
            'https://api.test.local/second/*' => $this->aiResponse($this->validPayload()),
        ]);

        $this->assertSame('second', app(AiService::class)->generate('texte', 'hash-5')['provider']);
    }

    public function test_a_disabled_provider_is_never_called(): void
    {
        $this->provider('premier', 1)->update(['enabled' => false]);
        $this->provider('second', 2);

        Http::fake([
            'https://api.test.local/premier/*' => $this->aiResponse($this->validPayload()),
            'https://api.test.local/second/*' => $this->aiResponse($this->validPayload()),
        ]);

        $this->assertSame('second', app(AiService::class)->generate('texte', 'hash-6')['provider']);

        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/premier/'));
    }

    // -- T-19 : tous les fournisseurs echouent ---------------------------------

    public function test_t19_all_providers_failing_raises_a_clear_error(): void
    {
        $this->provider('premier', 1);
        $this->provider('second', 2);

        Http::fake([
            '*' => Http::response(['error' => 'nope'], 429),
        ]);

        $this->expectException(AiUnavailableException::class);

        app(AiService::class)->generate('texte', 'hash-7');
    }

    public function test_t19_the_error_reports_each_attempt(): void
    {
        $this->provider('premier', 1);
        $this->provider('second', 2);

        Http::fake(['*' => Http::response([], 500)]);

        try {
            app(AiService::class)->generate('texte', 'hash-8');
            $this->fail('Une exception aurait dû être levée.');
        } catch (AiUnavailableException $e) {
            $names = array_column($e->attempts, 'provider');

            $this->assertSame(['premier', 'second'], $names);
            $this->assertNotEmpty($e->getMessage());
        }
    }

    public function test_t19_all_providers_failing_marks_the_job_and_offers_manual_creation(): void
    {
        [$classroom, $teacher] = $this->classWithMember();

        $this->provider('premier', 1);
        $this->provider('second', 2);

        Http::fake(['*' => Http::response([], 500)]);

        $job = $this->createAiJob($classroom, $teacher);
        $this->runJob(new ProcessAiGeneration($job->id));

        $job->refresh();

        $this->assertSame('failed', $job->status->value);
        $this->assertNotEmpty((string) $job->error);

        // L'enseignant reçoit un message clair et peut créer manuellement.
        $notification = AppNotification::where('user_id', $teacher->id)
            ->where('type', NotificationService::AI_JOB_FINISHED)
            ->firstOrFail();

        $payload = (array) ($notification->payload ?? []);

        $this->assertTrue((bool) ($payload['manual_fallback'] ?? false));
        $this->assertNotEmpty((string) ($payload['message'] ?? ''));
    }

    public function test_t19_the_endpoint_queues_the_job_and_returns_202(): void
    {
        [$classroom, $teacher] = $this->classWithMember();

        $this->provider('premier', 1);
        Http::fake(['*' => $this->aiResponse($this->validPayload())]);
        $this->fakePdfText();

        $this->actingAs($teacher)
            ->post("/api/classes/{$classroom->id}/ai/generate", [
                'file' => $this->fakeUpload('cours.pdf', 'application/pdf', 120),
            ])
            ->assertStatus(202)
            ->assertJsonPath('status', 'queued');

        $this->assertDatabaseHas('ai_jobs', [
            'classroom_id' => $classroom->id,
            'teacher_id' => $teacher->id,
        ]);
    }

    public function test_t19_an_unreadable_pdf_fails_with_a_manual_fallback(): void
    {
        [$classroom, $teacher] = $this->classWithMember();

        $this->provider('premier', 1);
        Http::fake(['*' => $this->aiResponse($this->validPayload())]);

        // Aucun extracteur : on simule l'absence du moteur PDF.
        $this->app->instance(PdfTextExtractor::class, new class implements PdfTextExtractor
        {
            public function extract(string $absolutePath): array
            {
                throw new PdfExtractionException(
                    "Le texte du PDF n'a pas pu être extrait. Créez le quiz manuellement."
                );
            }
        });

        $job = $this->createAiJob($classroom, $teacher);
        $this->runJob(new ProcessAiGeneration($job->id));
        $job->refresh();

        $this->assertSame('failed', $job->status->value);
        $this->assertStringContainsString('manuellement', (string) $job->error);

        // Aucun appel fournisseur quand le PDF est illisible.
        Http::assertNothingSent();
    }

    public function test_a_pdf_over_the_page_limit_is_refused_before_any_call(): void
    {
        [$classroom, $teacher] = $this->classWithMember();

        $this->provider('premier', 1);
        Http::fake(['*' => $this->aiResponse($this->validPayload())]);

        config(['classlink.ai.max_pages' => 10]);
        $this->fakePdfText('Texte', pages: 11);

        $job = $this->createAiJob($classroom, $teacher);
        $this->runJob(new ProcessAiGeneration($job->id));
        $job->refresh();

        $this->assertSame('failed', $job->status->value);
        $this->assertStringContainsString('11 pages', (string) $job->error);
        Http::assertNothingSent();
    }

    public function test_t19_manual_quiz_creation_still_works_when_ai_is_down(): void
    {
        [$classroom, $teacher] = $this->classWithMember();

        $this->provider('premier', 1);
        Http::fake(['*' => Http::response([], 500)]);

        // F-IA-06 : la création manuelle ne dépend JAMAIS de l'IA.
        $this->actingAs($teacher)
            ->postJson("/api/classes/{$classroom->id}/quizzes", [
                'title' => 'Quiz manuel de secours',
                'questions' => [[
                    'statement' => 'Question manuelle',
                    'type' => 'single',
                    'options' => [
                        ['label' => 'Oui', 'is_correct' => true],
                        ['label' => 'Non', 'is_correct' => false],
                    ],
                ]],
            ])
            ->assertStatus(201);
    }

    // -- T-20 : le meme PDF deux fois -> cache, quota non consomme ------------

    public function test_t20_the_same_document_is_served_from_cache(): void
    {
        $provider = $this->provider('premier', 1);

        Http::fake(['*' => $this->aiResponse($this->validPayload())]);

        $service = app(AiService::class);

        $first = $service->generate('texte du cours', 'hash-identique');
        $second = $service->generate('texte du cours', 'hash-identique');

        $this->assertFalse($first['cached']);
        $this->assertTrue($second['cached']);
        $this->assertSame('cache', $second['provider']);
        $this->assertSame($first['data'], $second['data']);

        // Le quota du fournisseur n'est consommé qu'une fois.
        $this->assertSame(1, $provider->fresh()->used_today);

        // L'API n'a été appelée qu'une seule fois.
        Http::assertSentCount(1);
    }

    public function test_t20_a_different_document_is_not_served_from_cache(): void
    {
        $provider = $this->provider('premier', 1);

        Http::fake(['*' => $this->aiResponse($this->validPayload())]);

        $service = app(AiService::class);
        $service->generate('texte A', 'hash-a');
        $service->generate('texte B', 'hash-b');

        $this->assertSame(2, $provider->fresh()->used_today);
        Http::assertSentCount(2);
    }

    public function test_t20_a_failed_generation_is_not_cached(): void
    {
        $this->provider('premier', 1);

        // `Http::fake()` EMPILE les doublures : on utilise un compteur pour
        // faire passer la premiere tentative en echec puis la suivante en succes.
        $calls = 0;

        Http::fake(['*' => function () use (&$calls) {
            $calls++;

            return $calls === 1
                ? Http::response([], 500)
                : $this->aiResponse($this->validPayload());
        }]);

        try {
            app(AiService::class)->generate('texte', 'hash-ko');
            $this->fail('Une exception aurait dû être levée.');
        } catch (AiUnavailableException) {
            // attendu
        }

        // On efface le refroidissement pour isoler le comportement du cache.
        Cache::flush();

        $second = app(AiService::class)->generate('texte', 'hash-ko');

        $this->assertFalse($second['cached']);
        $this->assertSame('premier', $second['provider']);
    }

    // -- RG-11 : la sortie IA est un brouillon non relu ------------------------

    public function test_ai_output_is_created_as_an_unreviewed_draft(): void
    {
        $this->provider('premier', 1);
        Http::fake(['*' => $this->aiResponse($this->validPayload())]);
        $this->fakePdfText();

        [$classroom, $teacher] = $this->classWithMember();

        $job = $this->createAiJob($classroom, $teacher);
        $this->runJob(new ProcessAiGeneration($job->id));

        $job->refresh();
        $this->assertSame('done', $job->status->value);
        $this->assertSame('premier', $job->provider);

        $quiz = Quiz::where('source', 'ai')->firstOrFail();

        $this->assertSame('draft', $quiz->status);
        $this->assertFalse((bool) $quiz->reviewed);
        $this->assertNull($quiz->published_at);
        $this->assertSame($quiz->id, $job->quiz_id);
    }

    public function test_an_ai_draft_cannot_be_published_before_review(): void
    {
        $this->provider('premier', 1);
        Http::fake(['*' => $this->aiResponse($this->validPayload())]);
        $this->fakePdfText();

        [$classroom, $teacher] = $this->classWithMember();

        $this->runJob(new ProcessAiGeneration($this->createAiJob($classroom, $teacher)->id));

        $quiz = Quiz::where('source', 'ai')->firstOrFail();

        // RG-11 : publication refusée tant que l'enseignant n'a pas relu.
        $this->actingAs($teacher)
            ->postJson("/api/quizzes/{$quiz->id}/publish")
            ->assertStatus(409)
            ->assertJsonPath('context.requires_review', true);
    }

    public function test_reviewing_the_draft_allows_publication(): void
    {
        $this->provider('premier', 1);
        Http::fake(['*' => $this->aiResponse($this->validPayload())]);
        $this->fakePdfText();

        [$classroom, $teacher] = $this->classWithMember();

        $this->runJob(new ProcessAiGeneration($this->createAiJob($classroom, $teacher)->id));

        $quiz = Quiz::where('source', 'ai')->firstOrFail();

        $this->actingAs($teacher)
            ->postJson("/api/quizzes/{$quiz->id}/review")
            ->assertStatus(200)
            ->assertJsonPath('reviewed', true);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $teacher->id,
            'action' => 'quiz.review',
        ]);

        $this->actingAs($teacher)
            ->postJson("/api/quizzes/{$quiz->id}/publish")
            ->assertStatus(200);
    }

    public function test_a_manual_quiz_cannot_be_marked_as_reviewed(): void
    {
        [$classroom, $teacher] = $this->classWithMember();

        $this->actingAs($teacher)
            ->postJson("/api/classes/{$classroom->id}/quizzes", [
                'title' => 'Quiz manuel',
                'questions' => [[
                    'statement' => 'Question',
                    'type' => 'single',
                    'options' => [
                        ['label' => 'Oui', 'is_correct' => true],
                        ['label' => 'Non', 'is_correct' => false],
                    ],
                ]],
            ])
            ->assertStatus(201);

        $quiz = Quiz::where('source', 'manual')->firstOrFail();

        $this->actingAs($teacher)
            ->postJson("/api/quizzes/{$quiz->id}/review")
            ->assertStatus(422);
    }

    public function test_a_student_cannot_review_a_draft(): void
    {
        $this->provider('premier', 1);
        Http::fake(['*' => $this->aiResponse($this->validPayload())]);
        $this->fakePdfText();

        [$classroom, $teacher, $student] = $this->classWithMember();

        $this->runJob(new ProcessAiGeneration($this->createAiJob($classroom, $teacher)->id));

        $quiz = Quiz::where('source', 'ai')->firstOrFail();

        $this->actingAs($student)
            ->postJson("/api/quizzes/{$quiz->id}/review")
            ->assertStatus(403);
    }

    // -- Validation de la charge utile ----------------------------------------

    public function test_invalid_json_triggers_one_retry_then_the_next_provider(): void
    {
        $this->provider('premier', 1);
        $this->provider('second', 2);

        Http::fake([
            // JSON structurellement valide mais sans question exploitable.
            'https://api.test.local/premier/*' => $this->aiResponse(['title' => 'Vide', 'questions' => []]),
            'https://api.test.local/second/*' => $this->aiResponse($this->validPayload()),
        ]);

        $this->assertSame('second', app(AiService::class)->generate('texte', 'hash-9')['provider']);

        // 2 essais sur le premier (retry) + 1 sur le second.
        Http::assertSentCount(3);
    }

    public function test_a_payload_without_a_correct_option_is_refused(): void
    {
        $this->provider('premier', 1);

        Http::fake(['*' => $this->aiResponse([
            'title' => 'Quiz invalide',
            'questions' => [[
                'statement' => 'Question sans bonne reponse',
                'type' => 'single',
                'options' => [
                    ['label' => 'A', 'is_correct' => false],
                    ['label' => 'B', 'is_correct' => false],
                ],
            ]],
        ])]);

        $this->expectException(AiUnavailableException::class);

        app(AiService::class)->generate('texte', 'hash-10');
    }

    // -- Quota quotidien par enseignant (RG-14) -------------------------------

    public function test_teacher_daily_quota_is_enforced(): void
    {
        [$classroom, $teacher] = $this->classWithMember();
        $this->provider('premier', 1);

        AiJob::create([
            'teacher_id' => $teacher->id,
            'classroom_id' => $classroom->id,
            'target' => 'quiz',
            'file_hash' => str_repeat('a', 64),
            'original_name' => 'x.pdf',
            'file_path' => 'ai-inputs/x.pdf',
            'status' => 'done',
        ]);

        // Le quota est atteint dès la première tentative.
        config(['classlink.ai.daily_quota_per_teacher' => 1]);

        $this->actingAs($teacher)
            ->post("/api/classes/{$classroom->id}/ai/generate", [
                'file' => $this->fakeUpload('cours.pdf', 'application/pdf', 120),
            ])
            ->assertStatus(429);
    }

    public function test_failed_generations_do_not_consume_the_teacher_quota(): void
    {
        $this->fakePdfText();
        [$classroom, $teacher] = $this->classWithMember();

        AiJob::create([
            'teacher_id' => $teacher->id,
            'classroom_id' => $classroom->id,
            'target' => 'quiz',
            'file_hash' => str_repeat('b', 64),
            'original_name' => 'x.pdf',
            'file_path' => 'ai-inputs/x.pdf',
            'status' => 'failed',
        ]);

        $this->provider('premier', 1);
        Http::fake(['*' => $this->aiResponse($this->validPayload())]);

        $this->actingAs($teacher)
            ->post("/api/classes/{$classroom->id}/ai/generate", [
                'file' => $this->fakeUpload('cours.pdf', 'application/pdf', 120),
            ])
            ->assertStatus(202);
    }

    public function test_daily_quota_can_be_reset(): void
    {
        $provider = $this->provider('premier', 1, limit: 5);
        $provider->update(['used_today' => 5]);

        $this->assertFalse($provider->fresh()->hasQuotaLeft());

        app(AiService::class)->resetDailyQuotas();

        $this->assertSame(0, $provider->fresh()->used_today);
        $this->assertNotNull($provider->fresh()->last_reset_at);
    }

    // -- Accès réservé à l'enseignant propriétaire -----------------------------

    public function test_a_student_cannot_trigger_a_generation(): void
    {
        [$classroom, , $student] = $this->classWithMember();

        $this->actingAs($student)
            ->post("/api/classes/{$classroom->id}/ai/generate", [
                'file' => $this->fakeUpload('cours.pdf', 'application/pdf', 120),
            ])
            ->assertStatus(403);
    }

    public function test_another_teacher_cannot_trigger_a_generation(): void
    {
        [$classroom] = $this->classWithMember();

        $this->actingAs($this->teacher())
            ->post("/api/classes/{$classroom->id}/ai/generate", [
                'file' => $this->fakeUpload('cours.pdf', 'application/pdf', 120),
            ])
            ->assertStatus(403);
    }

    public function test_another_teacher_cannot_read_someone_elses_ai_job(): void
    {
        [$classroom, $teacher] = $this->classWithMember();

        $job = AiJob::create([
            'teacher_id' => $teacher->id,
            'classroom_id' => $classroom->id,
            'target' => 'quiz',
            'file_hash' => str_repeat('c', 64),
            'original_name' => 'cours.pdf',
            'file_path' => 'ai-inputs/cours.pdf',
            'status' => 'done',
        ]);

        $this->actingAs($this->teacher())
            ->getJson("/api/ai/jobs/{$job->id}")
            ->assertStatus(403);
    }

    // -- Confidentialité : seules les clés d'API voyagent ----------------------

    public function test_ai_provider_secrets_never_appear_in_a_response(): void
    {
        [$classroom, $teacher] = $this->classWithMember();
        $this->provider('premier', 1);

        $job = AiJob::create([
            'teacher_id' => $teacher->id,
            'classroom_id' => $classroom->id,
            'target' => 'quiz',
            'file_hash' => str_repeat('d', 64),
            'original_name' => 'cours.pdf',
            'file_path' => 'ai-inputs/cours.pdf',
            'status' => 'done',
        ]);

        $body = $this->actingAs($teacher)
            ->getJson("/api/ai/jobs/{$job->id}")
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('cle-de-test', $body);
    }
}
