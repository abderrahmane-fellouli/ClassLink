<?php

namespace Tests\Feature;

use App\Models\Attempt;
use App\Models\AttemptAnswer;
use App\Models\Membership;
use App\Models\Quiz;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * T-15 a T-17 — Quiz (F-QUI-01 a F-QUI-09, §7 RG-11 a RG-13).
 */
class QuizTest extends TestCase
{
    use RefreshDatabase;

    private $teacher;

    private $student;

    private $classroom;

    private $quiz;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->classroom, $this->teacher, $this->student] = $this->classWithMember();
        $this->quiz = $this->publishedQuiz($this->classroom, $this->teacher, questions: 2);
    }

    /** Options correctes du quiz, dans l'ordre. */
    private function correctOptionIds(): array
    {
        return $this->quiz->questions->map(
            fn ($q) => $q->options->firstWhere('is_correct', true)->id
        )->all();
    }

    private function wrongOptionIds(): array
    {
        return $this->quiz->questions->map(
            fn ($q) => $q->options->firstWhere('is_correct', false)->id
        )->all();
    }

    // -- T-15 : soumission apres la fin du temps -------------------------------

    public function test_t15_submission_after_the_deadline_is_marked_expired(): void
    {
        $start = $this->actingAs($this->student)
            ->postJson("/api/quizzes/{$this->quiz->id}/attempts")
            ->assertStatus(201)
            ->json();

        $attemptId = $start['attempt_id'];

        // Le temps imparti (10 min) est depasse.
        Attempt::whereKey($attemptId)->update([
            'started_at' => Carbon::now()->subMinutes(11),
        ]);

        // Reponses parfaites envoyees APRÈS l'echeance : elles doivent etre
        // rejetees, donc ni enregistrees ni corrigees (RG-12).
        $answers = $this->quiz->questions->map(fn ($q) => [
            'question_id' => $q->id,
            'option_ids' => [$q->options->firstWhere('is_correct', true)->id],
        ])->all();

        $response = $this->actingAs($this->student)
            ->postJson("/api/attempts/{$attemptId}/submit", ['answers' => $answers])
            ->assertOk();

        $this->assertTrue((bool) $response->json('expired'), 'La tentative doit être marquée expirée.');
        $this->assertNotNull($response->json('submitted_at'));

        // Les reponses tardives ne sont pas corrigees...
        $this->assertScore($response, 0.0);

        // ...et elles ne sont pas persistées.
        $this->assertSame(0, AttemptAnswer::where('attempt_id', $attemptId)->count());
        $this->assertScore($response, 0.0, 'percentage');
    }

    /**
     * RG-12 : le service refuse d'enregistrer une réponse après l'échéance.
     *
     * Ce garde-fou protège les appelants futurs (notamment un futur point
     * de sauvegarde partielle) : la protection ne repose pas uniquement sur
     * le contrôleur.
     */
    public function test_t15_the_service_refuses_to_save_answers_after_the_deadline(): void
    {
        $start = $this->actingAs($this->student)
            ->postJson("/api/quizzes/{$this->quiz->id}/attempts")
            ->assertStatus(201)
            ->json();

        $attempt = Attempt::findOrFail($start['attempt_id']);

        Attempt::whereKey($attempt->id)->update([
            'started_at' => Carbon::now()->subMinutes(11),
        ]);

        $answer = [
            'question_id' => $this->quiz->questions->first()->id,
            'option_ids' => [$this->correctOptionIds()[0]],
        ];

        try {
            app(\App\Services\QuizGradingService::class)->saveAnswers($attempt->fresh(), [$answer]);

            $this->fail('Une réponse envoyée après l\'échéance ne doit jamais être enregistrée.');
        } catch (\App\Exceptions\BusinessRuleException $e) {
            $this->assertSame(409, $e->status());
        }

        $this->assertSame(0, AttemptAnswer::where('attempt_id', $attempt->id)->count());
    }

    /**
     * RG-12 : les réponses enregistrées AVANT l'échéance restent corrigées
     * lors de la soumission automatique. Le correctif ne doit pas mettre à
     * zéro une tentative qui avait déjà legitimately obtenu des points.
     */
    public function test_t15_answers_recorded_before_the_deadline_are_still_graded(): void
    {
        $start = $this->actingAs($this->student)
            ->postJson("/api/quizzes/{$this->quiz->id}/attempts")
            ->assertStatus(201)
            ->json();

        $attempt = Attempt::findOrFail($start['attempt_id']);

        // Réponses sauvegardées alors que le temps imparti restait.
        app(\App\Services\QuizGradingService::class)->saveAnswers(
            $attempt,
            $this->quiz->questions->map(fn ($q) => [
                'question_id' => $q->id,
                'option_ids' => [$q->options->firstWhere('is_correct', true)->id],
            ])->all()
        );

        Attempt::whereKey($attempt->id)->update([
            'started_at' => Carbon::now()->subMinutes(30),
        ]);

        $response = $this->actingAs($this->student)
            ->getJson("/api/attempts/{$attempt->id}")
            ->assertOk();

        $this->assertTrue((bool) $response->json('expired'));
        $this->assertScore($response, 2.0);
    }

    /**
     * Frontière d'autorisation : le chemin « tentative expirée » ne doit pas
     * contourner le contrôle de propriété. Un autre étudiant reste refus.
     */
    public function test_t15_another_student_cannot_submit_an_expired_attempt(): void
    {
        $start = $this->actingAs($this->student)
            ->postJson("/api/quizzes/{$this->quiz->id}/attempts")
            ->assertStatus(201)
            ->json();

        Attempt::whereKey($start['attempt_id'])->update([
            'started_at' => Carbon::now()->subMinutes(11),
        ]);

        $outsider = $this->student();

        $this->actingAs($outsider)
            ->postJson("/api/attempts/{$start['attempt_id']}/submit", [
                'answers' => $this->quiz->questions->map(fn ($q) => [
                    'question_id' => $q->id,
                    'option_ids' => [$q->options->firstWhere('is_correct', true)->id],
                ])->all(),
            ])
            ->assertStatus(403);

        // L'enseignant ne soumet pas à la place de l'étudiant non plus.
        $this->actingAs($this->teacher())
            ->postJson("/api/attempts/{$start['attempt_id']}/submit", ['answers' => []])
            ->assertStatus(403);

        $this->assertNull(Attempt::findOrFail($start['attempt_id'])->submitted_at);
    }

    public function test_t15_answers_saved_before_the_deadline_are_still_graded(): void
    {
        $start = $this->actingAs($this->student)
            ->postJson("/api/quizzes/{$this->quiz->id}/attempts")
            ->assertStatus(201)
            ->json();

        $attemptId = $start['attempt_id'];

        // L'etudiant sauvegarde des bonnes reponses « en cours de route ».
        $response = $this->actingAs($this->student)
            ->postJson("/api/attempts/{$attemptId}/submit", [
                'answers' => $this->quiz->questions->map(fn ($q) => [
                    'question_id' => $q->id,
                    'option_ids' => [$q->options->firstWhere('is_correct', true)->id],
                ])->all(),
            ])
            ->assertOk();

        $this->assertScore($response, 2.0);
    }

    public function test_reading_an_expired_attempt_submits_it_automatically(): void
    {
        $this->quiz->update(['max_attempts' => 2]);

        $start = $this->actingAs($this->student)
            ->postJson("/api/quizzes/{$this->quiz->id}/attempts")
            ->assertStatus(201)
            ->json();

        $attemptId = $start['attempt_id'];

        // Reponse enregistree en cours de tentative, puis abandon.
        $this->actingAs($this->student)
            ->postJson("/api/attempts/{$attemptId}/submit", [
                'answers' => $this->quiz->questions->map(fn ($q) => [
                    'question_id' => $q->id,
                    'option_ids' => [$q->options->firstWhere('is_correct', true)->id],
                ])->all(),
            ])->assertOk();

        $second = $this->actingAs($this->student)
            ->postJson("/api/quizzes/{$this->quiz->id}/attempts")
            ->assertStatus(201)
            ->json();

        // L'etudiant ne soumet pas ; le temps imparti s'ecoule.
        Attempt::whereKey($second['attempt_id'])->update([
            'started_at' => Carbon::now()->subMinutes(30),
        ]);

        // RG-12 : la lecture soumet automatiquement.
        $response = $this->actingAs($this->student)
            ->getJson("/api/attempts/{$second['attempt_id']}")
            ->assertOk();

        $this->assertTrue((bool) $response->json('expired'));
        $this->assertNotNull($response->json('submitted_at'));
    }

    // -- T-16 : nombre maximal de tentatives ------------------------------------

    public function test_t16_attempt_beyond_the_maximum_is_refused_with_409(): void
    {
        // max_attempts = 1 dans publishedQuiz().
        $this->actingAs($this->student)
            ->postJson("/api/quizzes/{$this->quiz->id}/attempts")
            ->assertStatus(201);

        $this->actingAs($this->student)
            ->postJson("/api/quizzes/{$this->quiz->id}/attempts")
            ->assertStatus(409)
            ->assertJsonPath('context.max_attempts', 1);
    }

    public function test_t16_teacher_can_allow_several_attempts(): void
    {
        $this->quiz->update(['max_attempts' => 2]);

        $this->actingAs($this->student)
            ->postJson("/api/quizzes/{$this->quiz->id}/attempts")
            ->assertStatus(201);

        $this->actingAs($this->student)
            ->postJson("/api/quizzes/{$this->quiz->id}/attempts")
            ->assertStatus(201);

        $this->actingAs($this->student)
            ->postJson("/api/quizzes/{$this->quiz->id}/attempts")
            ->assertStatus(409);
    }

    // -- T-17 : correction cote serveur ----------------------------------------

    public function test_t17_all_correct_answers_give_a_full_score(): void
    {
        $start = $this->actingAs($this->student)
            ->postJson("/api/quizzes/{$this->quiz->id}/attempts")
            ->assertStatus(201)
            ->json();

        $response = $this->actingAs($this->student)
            ->postJson("/api/attempts/{$start['attempt_id']}/submit", [
                'answers' => $this->quiz->questions->map(fn ($q) => [
                    'question_id' => $q->id,
                    'option_ids' => [$q->options->firstWhere('is_correct', true)->id],
                ])->all(),
            ])
            ->assertOk();

        $this->assertScore($response, 2.0);
        $this->assertScore($response, 2.0, 'max_score');
        $this->assertScore($response, 100.0, 'percentage');

        foreach ($response->json('answers') as $answer) {
            $this->assertTrue($answer['is_correct']);
        }
    }

    public function test_t17_all_wrong_answers_give_a_zero_score(): void
    {
        $start = $this->actingAs($this->student)
            ->postJson("/api/quizzes/{$this->quiz->id}/attempts")
            ->assertStatus(201)
            ->json();

        $response = $this->actingAs($this->student)
            ->postJson("/api/attempts/{$start['attempt_id']}/submit", [
                'answers' => $this->quiz->questions->map(fn ($q) => [
                    'question_id' => $q->id,
                    'option_ids' => [$q->options->firstWhere('is_correct', false)->id],
                ])->all(),
            ])
            ->assertOk();

        $this->assertScore($response, 0.0);
        $this->assertScore($response, 0.0, 'percentage');
    }

    public function test_t17_partial_score_is_computed_per_question(): void
    {
        $questions = $this->quiz->questions;

        $start = $this->actingAs($this->student)
            ->postJson("/api/quizzes/{$this->quiz->id}/attempts")
            ->assertStatus(201)
            ->json();

        $response = $this->actingAs($this->student)
            ->postJson("/api/attempts/{$start['attempt_id']}/submit", [
                'answers' => [
                    // 1re question : bonne reponse.
                    [
                        'question_id' => $questions[0]->id,
                        'option_ids' => [$questions[0]->options->firstWhere('is_correct', true)->id],
                    ],
                    // 2e question : mauvaise reponse.
                    [
                        'question_id' => $questions[1]->id,
                        'option_ids' => [$questions[1]->options->firstWhere('is_correct', false)->id],
                    ],
                ],
            ])
            ->assertOk();

        $this->assertScore($response, 1.0);
        $this->assertScore($response, 50.0, 'percentage');
    }

    public function test_t17_unanswered_question_counts_as_zero(): void
    {
        $start = $this->actingAs($this->student)
            ->postJson("/api/quizzes/{$this->quiz->id}/attempts")
            ->assertStatus(201)
            ->json();

        $first = $this->quiz->questions[0];

        $response = $this->actingAs($this->student)
            ->postJson("/api/attempts/{$start['attempt_id']}/submit", [
                'answers' => [[
                    'question_id' => $first->id,
                    'option_ids' => [$first->options->firstWhere('is_correct', true)->id],
                ]],
            ])
            ->assertOk();

        $this->assertScore($response, 1.0);
    }

    public function test_option_from_another_question_is_ignored(): void
    {
        $questions = $this->quiz->questions;
        $foreignOptionId = $questions[1]->options->firstWhere('is_correct', true)->id;

        $start = $this->actingAs($this->student)
            ->postJson("/api/quizzes/{$this->quiz->id}/attempts")
            ->assertStatus(201)
            ->json();

        // On tente d'injecter une option n'appartenant pas a la question 1.
        $response = $this->actingAs($this->student)
            ->postJson("/api/attempts/{$start['attempt_id']}/submit", [
                'answers' => [[
                    'question_id' => $questions[0]->id,
                    'option_ids' => [$foreignOptionId],
                ]],
            ])
            ->assertOk();

        $this->assertScore($response, 0.0);
    }

    public function test_resubmitting_the_same_attempt_does_not_change_the_score(): void
    {
        $start = $this->actingAs($this->student)
            ->postJson("/api/quizzes/{$this->quiz->id}/attempts")
            ->assertStatus(201)
            ->json();

        $payload = ['answers' => $this->quiz->questions->map(fn ($q) => [
            'question_id' => $q->id,
            'option_ids' => [$q->options->firstWhere('is_correct', true)->id],
        ])->all()];

        $first = $this->actingAs($this->student)
            ->postJson("/api/attempts/{$start['attempt_id']}/submit", $payload)
            ->assertOk();

        $this->assertScore($first, 2.0);

        // Une tentative deja soumise ne peut pas etre modifiee (RG-12/RG-13).
        $this->actingAs($this->student)
            ->postJson("/api/attempts/{$start['attempt_id']}/submit", [
                'answers' => $this->quiz->questions->map(fn ($q) => [
                    'question_id' => $q->id,
                    'option_ids' => [$q->options->firstWhere('is_correct', false)->id],
                ])->all(),
            ])
            ->assertStatus(409);

        // Le score reste inchange.
        $this->assertScore(
            $this->actingAs($this->student)->getJson("/api/attempts/{$start['attempt_id']}")->assertOk(),
            2.0
        );
    }

    // -- RG-13 : aucune bonne reponse avant la soumission -----------------------

    public function test_rg13_starting_an_attempt_never_leaks_the_answers(): void
    {
        $response = $this->actingAs($this->student)
            ->postJson("/api/quizzes/{$this->quiz->id}/attempts")
            ->assertStatus(201);

        $body = $response->getContent();

        $this->assertStringNotContainsString('is_correct', $body);
        $this->assertStringNotContainsString('explanation', $body);

        foreach ($response->json('questions') as $question) {
            foreach ($question['options'] as $option) {
                $this->assertSame(
                    ['id', 'label'],
                    array_keys($option),
                    'Une option ne doit exposer que id et label.'
                );
            }
        }
    }

    public function test_rg13_student_view_of_a_quiz_never_leaks_the_answers(): void
    {
        $body = $this->actingAs($this->student)
            ->getJson("/api/quizzes/{$this->quiz->id}")
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('is_correct', $body);
    }

    public function test_rg13_draft_quizzes_are_invisible_to_students(): void
    {
        $this->quiz->update(['status' => 'draft']);

        $this->actingAs($this->student)
            ->getJson("/api/quizzes/{$this->quiz->id}")
            ->assertStatus(403);

        $this->actingAs($this->student)
            ->postJson("/api/quizzes/{$this->quiz->id}/attempts")
            ->assertStatus(403);
    }

    public function test_answers_are_revealed_only_after_submission(): void
    {
        $start = $this->actingAs($this->student)
            ->postJson("/api/quizzes/{$this->quiz->id}/attempts")
            ->assertStatus(201)
            ->json();

        $before = $this->actingAs($this->student)
            ->getJson("/api/attempts/{$start['attempt_id']}")
            ->assertOk();
        $before->assertJsonPath('answers.0.explanation', null)
            ->assertJsonPath('answers.0.options.0.is_correct', null);

        $result = $this->postJson("/api/attempts/{$start['attempt_id']}/submit", [
            'answers' => $this->quiz->questions->map(fn ($q) => [
                'question_id' => $q->id,
                'option_ids' => [$q->options->firstWhere('is_correct', true)->id],
            ])->all(),
        ])->assertOk();

        $this->assertTrue($result->json('show_answers'));

        foreach ($result->json('answers') as $answer) {
            $this->assertNotNull($answer['explanation']);
            foreach ($answer['options'] as $option) {
                $this->assertIsBool($option['is_correct']);
            }
        }
    }

    public function test_answers_stay_hidden_when_the_teacher_disables_them(): void
    {
        $this->quiz->update(['show_answers' => false]);

        $start = $this->actingAs($this->student)
            ->postJson("/api/quizzes/{$this->quiz->id}/attempts")
            ->assertStatus(201)
            ->json();

        $result = $this->actingAs($this->student)
            ->postJson("/api/attempts/{$start['attempt_id']}/submit", [
                'answers' => $this->quiz->questions->map(fn ($q) => [
                    'question_id' => $q->id,
                    'option_ids' => [$q->options->firstWhere('is_correct', true)->id],
                ])->all(),
            ])
            ->assertOk();

        // Le score est toujours visible, les bonnes reponses non.
        $this->assertScore($result, 2.0);

        foreach ($result->json('answers') as $answer) {
            $this->assertNull($answer['explanation']);
            foreach ($answer['options'] as $option) {
                $this->assertNull($option['is_correct']);
            }
        }
    }

    // -- RG-13 : un tiers ne peut pas lire la tentative d'autrui ---------------

    public function test_a_student_cannot_read_another_students_attempt(): void
    {
        $other = $this->student();
        Membership::create([
            'classroom_id' => $this->classroom->id,
            'student_id' => $other->id,
            'status' => 'accepted',
            'requested_at' => now(),
            'decided_at' => now(),
            'decided_by' => $this->teacher->id,
        ]);

        $start = $this->actingAs($this->student)
            ->postJson("/api/quizzes/{$this->quiz->id}/attempts")
            ->assertStatus(201)
            ->json();

        $this->actingAs($other)
            ->getJson("/api/attempts/{$start['attempt_id']}")
            ->assertStatus(403);
    }

    // -- F-QUI-03 : publication -------------------------------------------------

    public function test_ai_quiz_cannot_be_published_before_review(): void
    {
        $aiQuiz = $this->quiz;
        $aiQuiz->update(['source' => 'ai', 'reviewed' => false, 'status' => 'draft']);

        $this->actingAs($this->teacher)
            ->postJson("/api/quizzes/{$aiQuiz->id}/publish")
            ->assertStatus(409)
            ->assertJsonPath('context.requires_review', true);

        $this->assertDatabaseHas('quizzes', [
            'id' => $aiQuiz->id,
            'status' => 'draft',
        ]);
    }

    public function test_manual_quiz_publishes_immediately(): void
    {
        $draft = $this->quiz;
        $draft->update(['status' => 'draft', 'source' => 'manual']);

        $this->actingAs($this->teacher)
            ->postJson("/api/quizzes/{$draft->id}/publish")
            ->assertOk()
            ->assertJsonPath('status', 'published');

        $this->assertDatabaseHas('audit_logs', ['action' => 'quiz.publish']);
    }

    public function test_an_empty_quiz_cannot_be_published(): void
    {
        $empty = Quiz::create([
            'classroom_id' => $this->classroom->id,
            'created_by' => $this->teacher->id,
            'title' => 'Quiz vide',
            'status' => 'draft',
            'source' => 'manual',
            'reviewed' => true,
            'max_attempts' => 1,
        ]);

        $this->actingAs($this->teacher)
            ->postJson("/api/quizzes/{$empty->id}/publish")
            ->assertStatus(422);
    }

    // -- F-QUI-01 : la creation manuelle ne depend pas de l'IA -----------------

    public function test_manual_quiz_creation_works_without_any_ai_provider(): void
    {
        $this->assertDatabaseCount('ai_providers', 0);

        $response = $this->actingAs($this->teacher)
            ->postJson("/api/classes/{$this->classroom->id}/quizzes", [
                'title' => 'Quiz manuel',
                'time_limit_min' => 10,
                'max_attempts' => 1,
                'questions' => [[
                    'statement' => 'Quelle est la bonne reponse ?',
                    'type' => 'single',
                    'explanation' => 'Parce que.',
                    'options' => [
                        ['label' => 'Oui', 'is_correct' => true],
                        ['label' => 'Non', 'is_correct' => false],
                    ],
                ]],
            ])
            ->assertStatus(201);

        $this->assertSame('manual', $response->json('source'));
        $this->assertSame('draft', $response->json('status'));
    }

    public function test_manual_quiz_creation_rejects_a_question_without_correct_option(): void
    {
        $this->actingAs($this->teacher)
            ->postJson("/api/classes/{$this->classroom->id}/quizzes", [
                'title' => 'Quiz invalide',
                'questions' => [[
                    'statement' => 'Question sans bonne reponse',
                    'type' => 'single',
                    'options' => [
                        ['label' => 'A', 'is_correct' => false],
                        ['label' => 'B', 'is_correct' => false],
                    ],
                ]],
            ])
            ->assertStatus(422);
    }

    // -- F-QUI-09 : resultats enseignant --------------------------------------

    public function test_teacher_sees_the_class_results(): void
    {
        $start = $this->actingAs($this->student)
            ->postJson("/api/quizzes/{$this->quiz->id}/attempts")
            ->assertStatus(201)
            ->json();

        $this->actingAs($this->student)
            ->postJson("/api/attempts/{$start['attempt_id']}/submit", [
                'answers' => $this->quiz->questions->map(fn ($q) => [
                    'question_id' => $q->id,
                    'option_ids' => [$q->options->firstWhere('is_correct', true)->id],
                ])->all(),
            ]);

        $response = $this->actingAs($this->teacher)
            ->getJson("/api/quizzes/{$this->quiz->id}/results")
            ->assertOk();

        $this->assertSame(1, $response->json('summary.attempts_count'));
        $this->assertScore($response, 100.0, 'summary.average_percentage');

        // Le resultat d'un etudiant est visible, mais sans email (RG-18).
        $this->assertStringNotContainsString('@ofppt-edu.ma', $response->getContent());
    }

    public function test_another_teacher_cannot_see_the_results(): void
    {
        $this->actingAs($this->teacher())
            ->getJson("/api/quizzes/{$this->quiz->id}/results")
            ->assertStatus(403);
    }
}
