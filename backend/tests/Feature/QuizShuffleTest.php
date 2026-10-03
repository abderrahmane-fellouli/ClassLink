<?php

namespace Tests\Feature;

use App\Models\Attempt;
use App\Models\Question;
use App\Models\Quiz;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * F-QUI-02 / US-26 — mélange des questions côté serveur (§12.4).
 *
 * `shuffle` était stocké sur le quiz mais jamais appliqué : l'ordre venait
 * entièrement du client, donc contournable. L'ordre servi est désormais tiré
 * par le serveur et figé sur la tentative.
 */
class QuizShuffleTest extends TestCase
{
    use RefreshDatabase;

    private $teacher;

    private $student;

    private $classroom;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->classroom, $this->teacher, $this->student] = $this->classWithMember();
    }

    /** Quiz publie de {$count} questions, avec `shuffle` a l'etat demande. */
    private function quizWith(int $count, bool $shuffle): Quiz
    {
        $quiz = Quiz::create([
            'classroom_id' => $this->classroom->id,
            'created_by' => $this->teacher->id,
            'title' => 'Quiz ' . ($shuffle ? 'melange' : 'ordonne'),
            'status' => 'published',
            'source' => 'manual',
            'reviewed' => true,
            'time_limit_min' => 10,
            'max_attempts' => 1,
            'shuffle' => $shuffle,
            'show_answers' => true,
            'published_at' => now(),
        ]);

        foreach (range(1, $count) as $i) {
            $question = $quiz->questions()->create([
                'statement' => "Question {$i}",
                'type' => 'single',
                'explanation' => null,
                'position' => $i - 1,
            ]);

            $question->options()->create(['label' => 'Bonne', 'is_correct' => true]);
            $question->options()->create(['label' => 'Mauvaise', 'is_correct' => false]);
        }

        return $quiz->fresh('questions.options');
    }

    private function start(Quiz $quiz): Attempt
    {
        $response = $this->actingAs($this->student)
            ->postJson("/api/quizzes/{$quiz->id}/attempts")
            ->assertStatus(201);

        return Attempt::findOrFail($response->json('attempt_id'));
    }

    private function servedIds(Quiz $quiz): array
    {
        $response = $this->actingAs($this->student)
            ->postJson("/api/quizzes/{$quiz->id}/attempts")
            ->assertStatus(201);

        return collect($response->json('questions'))->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    // -- Comportement nominal -----------------------------------------------

    public function test_a_shuffled_quiz_does_not_always_serve_the_declared_order(): void
    {
        $quiz = $this->quizWith(8, shuffle: true);
        $declared = $quiz->questions->pluck('id')->map(fn ($id) => (int) $id)->all();
        $served = [];

        // Le tirage est aleatoire : sur plusieurs tentatives, l'ordre servi ne
        // peut pas rester celui du quiz. On force des etudiants distincts.
        $quiz->update(['max_attempts' => 50]);

        for ($i = 0; $i < 25; $i++) {
            $student = $this->student();
            \App\Models\Membership::create([
                'classroom_id' => $this->classroom->id,
                'student_id' => $student->id,
                'status' => 'accepted',
                'requested_at' => now(),
                'decided_at' => now(),
                'decided_by' => $this->teacher->id,
            ]);

            $response = $this->actingAs($student)
                ->postJson("/api/quizzes/{$quiz->id}/attempts")
                ->assertStatus(201);

            $served[] = collect($response->json('questions'))
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->join(',');
        }

        $this->assertNotEmpty($declared);
        $this->assertGreaterThan(
            1,
            count(array_unique($served)),
            'Le serveur doit tirer des ordres variables, pas toujours celui du quiz.'
        );
        $this->assertNotContains(implode(',', $declared), $served);
    }

    public function test_the_served_order_keeps_every_question_exactly_once(): void
    {
        $quiz = $this->quizWith(6, shuffle: true);
        $declared = $quiz->questions->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();

        $response = $this->actingAs($this->student)
            ->postJson("/api/quizzes/{$quiz->id}/attempts")
            ->assertStatus(201);

        $served = collect($response->json('questions'))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->sort()
            ->values()
            ->all();

        // Melanger ne doit ni perdre ni dupliquer une question.
        $this->assertSame($declared, $served);
        $this->assertCount(6, $response->json('questions'));
    }

    public function test_an_unshuffled_quiz_keeps_the_declared_order(): void
    {
        $quiz = $this->quizWith(5, shuffle: false);

        $this->assertSame(
            $quiz->questions->pluck('id')->map(fn ($id) => (int) $id)->all(),
            $this->servedIds($quiz)
        );
    }

    public function test_the_attempt_persists_the_order_it_was_served(): void
    {
        $quiz = $this->quizWith(6, shuffle: true);

        $response = $this->actingAs($this->student)
            ->postJson("/api/quizzes/{$quiz->id}/attempts")
            ->assertStatus(201);

        $served = collect($response->json('questions'))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $attempt = Attempt::findOrFail($response->json('attempt_id'));

        $this->assertSame($served, $attempt->question_order, 'L\'ordre servi doit etre fige sur la tentative.');
    }

    public function test_the_persisted_order_is_stable_across_reads(): void
    {
        $quiz = $this->quizWith(6, shuffle: true);
        $attempt = $this->start($quiz);
        $frozen = $attempt->question_order;

        // Recharger la tentative ne doit jamais redistribuer les questions.
        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($this->student)
                ->getJson("/api/attempts/{$attempt->id}")
                ->assertOk();

            $this->assertSame($frozen, $attempt->fresh()->question_order);
        }
    }

    public function test_the_response_declares_whether_the_quiz_is_shuffled(): void
    {
        $this->assertTrue($this->actingAs($this->student)
            ->postJson("/api/quizzes/{$this->quizWith(3, true)->id}/attempts")
            ->assertStatus(201)
            ->json('shuffled'));

        $this->assertFalse($this->actingAs($this->student)
            ->postJson("/api/quizzes/{$this->quizWith(3, false)->id}/attempts")
            ->assertStatus(201)
            ->json('shuffled'));
    }

    // -- Robustesse ---------------------------------------------------------

    public function test_an_attempt_without_a_frozen_order_falls_back_to_the_quiz_order(): void
    {
        // Tentative anterieure a la colonne : `question_order` est NULL.
        $quiz = $this->quizWith(4, shuffle: true);

        $attempt = Attempt::create([
            'quiz_id' => $quiz->id,
            'student_id' => $this->student->id,
            'attempt_no' => 1,
            'max_score' => 4,
            'started_at' => now(),
        ]);

        $this->assertNull($attempt->fresh()->question_order);

        $this->actingAs($this->student)
            ->getJson("/api/attempts/{$attempt->id}")
            ->assertOk();

        $this->assertSame(
            $quiz->questions->pluck('id')->map(fn ($id) => (int) $id)->all(),
            app(\App\Services\QuizGradingService::class)->questionsFor($attempt->fresh())->pluck('id')->all()
        );
    }

    public function test_a_question_added_after_the_attempt_is_not_hidden(): void
    {
        $quiz = $this->quizWith(3, shuffle: true);
        $attempt = $this->start($quiz);

        // Cas limite : l'ordre fige ne mentionne pas la nouvelle question.
        $extra = $quiz->questions()->create([
            'statement' => 'Question ajoutée entre-temps',
            'type' => 'single',
            'position' => 3,
        ]);
        $extra->options()->create(['label' => 'Bonne', 'is_correct' => true]);

        $ids = app(\App\Services\QuizGradingService::class)
            ->questionsFor($attempt->fresh())
            ->pluck('id')
            ->all();

        $this->assertContains($extra->id, $ids, 'Une question absente de l\'ordre figé ne doit pas disparaître.');
    }

    public function test_grading_is_unaffected_by_the_shuffle(): void
    {
        // Le barème reste 1 point par question, quel que soit l'ordre.
        $quiz = $this->quizWith(5, shuffle: true);
        $attempt = $this->start($quiz);

        $answers = $quiz->questions->map(fn (Question $q) => [
            'question_id' => $q->id,
            'option_ids' => [$q->options->firstWhere('is_correct', true)->id],
        ])->all();

        $result = $this->actingAs($this->student)
            ->postJson("/api/attempts/{$attempt->id}/submit", ['answers' => $answers])
            ->assertOk();

        $this->assertEqualsWithDelta(5.0, (float) $result->json('score'), 0.0001);
        $this->assertEqualsWithDelta(5.0, (float) $result->json('max_score'), 0.0001);
    }

    public function test_shuffle_does_not_leak_the_correct_answers(): void
    {
        // RG-13 : le mélange ne doit rienchanger a la confidentialite.
        $quiz = $this->quizWith(4, shuffle: true);

        $body = $this->actingAs($this->student)
            ->postJson("/api/quizzes/{$quiz->id}/attempts")
            ->assertStatus(201)
            ->getContent();

        $this->assertStringNotContainsString('is_correct', $body);
    }

    public function test_two_attempts_of_the_same_student_can_differ(): void
    {
        $quiz = $this->quizWith(8, shuffle: true);
        $quiz->update(['max_attempts' => 5]);

        $first = $this->start($quiz);
        $first->update(['submitted_at' => now(), 'score' => 0]);

        $second = $this->start($quiz);

        // Deux tentatives du meme etudiant sur le meme quiz : melanger doit
        // produire un autre tirage, sinon le contournement reste possible en
        // recommençant.
        $this->assertNotSame(
            $first->question_order,
            $second->question_order,
            'Deux tentatives doivent être mélangées indépendamment.'
        );
        $this->assertSame(
            $first->question_order,
            $first->fresh()->question_order,
            'Une tentative ne doit jamais changer d\'ordre.'
        );
    }

    public function test_the_column_exists_and_is_nullable(): void
    {
        $this->assertTrue(
            \Illuminate\Support\Facades\Schema::hasColumn('attempts', 'question_order')
        );

        $this->assertNull(
            DB::table('attempts')->where('quiz_id', $this->quizWith(1, false)->id)->value('question_order')
        );
    }
}