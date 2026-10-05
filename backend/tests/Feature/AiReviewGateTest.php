<?php

namespace Tests\Feature;

use App\Models\Classroom;
use App\Models\FlashcardDeck;
use App\Models\Quiz;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * F-IA-03 / RG-11 / US-32 — porte de relecture fermée par défaut (§15.2).
 *
 * Trois défauts fail-open :
 *  1. `reviewed` avait pour défaut `true` en base ;
 *  2. publier un quiz IA réécrivait `reviewed = true`, donc publier valait
 *     relecture ;
 *  3. rien n'attestait qu'un enseignant avait réellement ouvert le contenu.
 */
class AiReviewGateTest extends TestCase
{
    use RefreshDatabase;

    private $teacher;

    private $student;

    private Classroom $classroom;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->classroom, $this->teacher, $this->student] = $this->classWithMember();
    }

    private function aiQuiz(bool $reviewed = false): Quiz
    {
        $quiz = Quiz::create([
            'classroom_id' => $this->classroom->id,
            'created_by' => $this->teacher->id,
            'title' => 'Quiz IA',
            'status' => 'draft',
            'source' => 'ai',
            'reviewed' => $reviewed,
            'time_limit_min' => 10,
            'max_attempts' => 1,
            'shuffle' => false,
            'show_answers' => true,
        ]);

        $question = $quiz->questions()->create([
            'statement' => 'Question IA',
            'type' => 'single',
            'position' => 0,
        ]);
        $question->options()->create(['label' => 'Bonne', 'is_correct' => true]);
        $question->options()->create(['label' => 'Mauvaise', 'is_correct' => false]);

        return $quiz->fresh('questions.options');
    }

    // -- La valeur par défaut doit être « non relu » ------------------------

    public function test_a_quiz_created_without_writing_reviewed_is_not_reviewed(): void
    {
        /*
         * Contrat fail-closed : la valeur par défaut de la colonne doit etre
         * `false`. Avant la correction, `reviewed` avait pour defaut `true`, si
         * bien que toute creation omissions naissait « relue » et que la
         * publication d'un quiz IA etait possible sans aucune relecture.
         */
        $quiz = Quiz::create([
            'classroom_id' => $this->classroom->id,
            'created_by' => $this->teacher->id,
            'title' => 'Quiz sans reviewed',
            'status' => 'draft',
            'source' => 'ai',
        ]);

        $this->assertFalse((bool) $quiz->fresh()->reviewed, 'La valeur par défaut doit être false.');
        $this->assertNull($quiz->fresh()->reviewed_at);

        $deck = FlashcardDeck::create([
            'classroom_id' => $this->classroom->id,
            'title' => 'Deck sans reviewed',
            'source' => 'ai',
            'status' => 'draft',
        ]);

        $this->assertFalse((bool) $deck->fresh()->reviewed);
    }

    public function test_the_reviewed_at_column_exists_on_quizzes_and_decks(): void
    {
        $this->assertTrue(Schema::hasColumn('quizzes', 'reviewed_at'));
        $this->assertTrue(Schema::hasColumn('flashcard_decks', 'reviewed_at'));
    }

    // -- Création -----------------------------------------------------------

    public function test_a_manual_quiz_is_created_unreviewed(): void
    {
        $quiz = $this->actingAs($this->teacher)
            ->postJson("/api/classes/{$this->classroom->id}/quizzes", [
                'title' => 'Quiz manuel',
                'questions' => [$this->questionPayload()],
            ])
            ->assertStatus(201)
            ->json();

        $stored = Quiz::findOrFail($quiz['id']);

        $this->assertFalse((bool) $stored->reviewed, 'La création ne doit plus poser reviewed.');
        $this->assertNull($stored->reviewed_at);
    }

    public function test_a_manual_deck_is_created_unreviewed(): void
    {
        $deck = $this->actingAs($this->teacher)
            ->postJson("/api/classes/{$this->classroom->id}/flashcards", [
                'title' => 'Deck manuel',
                'cards' => [['front' => 'A', 'back' => 'B']],
            ])
            ->assertStatus(201)
            ->json();

        $this->assertFalse((bool) FlashcardDeck::findOrFail($deck['id'])->reviewed);
    }

    // -- La publication ne vaut pas relecture --------------------------------

    public function test_publishing_a_manual_quiz_does_not_certify_a_review(): void
    {
        $quiz = Quiz::create([
            'classroom_id' => $this->classroom->id,
            'created_by' => $this->teacher->id,
            'title' => 'Quiz manuel',
            'status' => 'draft',
            'source' => 'manual',
        ]);
        $question = $quiz->questions()->create([
            'statement' => 'Question',
            'type' => 'single',
            'position' => 0,
        ]);
        $question->options()->create(['label' => 'Bonne', 'is_correct' => true]);
        $question->options()->create(['label' => 'Mauvaise', 'is_correct' => false]);

        $this->actingAs($this->teacher)
            ->postJson("/api/quizzes/{$quiz->id}/publish")
            ->assertOk();

        $quiz->refresh();

        // Publier ne doit pas fabriquer une relecture.
        $this->assertFalse((bool) $quiz->reviewed);
        $this->assertNull($quiz->reviewed_at);
    }

    public function test_publishing_a_manual_deck_does_not_certify_a_review(): void
    {
        $deck = FlashcardDeck::create([
            'classroom_id' => $this->classroom->id,
            'title' => 'Deck manuel',
            'source' => 'manual',
            'status' => 'draft',
        ]);

        $this->actingAs($this->teacher)
            ->postJson("/api/flashcard-decks/{$deck->id}/publish")
            ->assertOk();

        $this->assertFalse((bool) $deck->fresh()->reviewed);
    }

    // -- Le verrou IA reste fermé sans relecture -----------------------------

    public function test_an_ai_quiz_cannot_be_published_before_review(): void
    {
        $quiz = $this->aiQuiz();

        $this->actingAs($this->teacher)
            ->postJson("/api/quizzes/{$quiz->id}/publish")
            ->assertStatus(409)
            ->assertJsonPath('context.requires_review', true);

        $this->assertSame('draft', $quiz->fresh()->status);
    }

    public function test_an_ai_quiz_publishes_after_an_explicit_review(): void
    {
        $quiz = $this->aiQuiz();
        $this->actingAs($this->teacher)->getJson("/api/quizzes/{$quiz->id}/editor")->assertOk();

        $this->actingAs($this->teacher)
            ->postJson("/api/quizzes/{$quiz->id}/review")
            ->assertOk();

        $quiz->refresh();
        $this->assertTrue((bool) $quiz->reviewed);
        $this->assertNotNull($quiz->reviewed_at, 'La relecture doit être horodatée.');

        $this->actingAs($this->teacher)
            ->postJson("/api/quizzes/{$quiz->id}/publish")
            ->assertOk();

        $this->assertSame('published', $quiz->fresh()->status);
    }

    public function test_an_ai_deck_cannot_be_published_before_review(): void
    {
        $deck = FlashcardDeck::create([
            'classroom_id' => $this->classroom->id,
            'title' => 'Deck IA',
            'source' => 'ai',
            'status' => 'draft',
        ]);

        $this->actingAs($this->teacher)
            ->postJson("/api/flashcard-decks/{$deck->id}/publish")
            ->assertStatus(409);

        $this->assertSame('draft', $deck->fresh()->status);
    }

    public function test_an_ai_deck_publishes_after_marking_it_reviewed(): void
    {
        $deck = FlashcardDeck::create([
            'classroom_id' => $this->classroom->id,
            'title' => 'Deck IA',
            'source' => 'ai',
            'status' => 'draft',
        ]);

        $this->actingAs($this->teacher)
            ->postJson("/api/flashcard-decks/{$deck->id}/reviewed")
            ->assertOk();

        $this->assertNotNull($deck->fresh()->reviewed_at);

        $this->actingAs($this->teacher)
            ->postJson("/api/flashcard-decks/{$deck->id}/publish")
            ->assertOk();

        $this->assertSame('published', $deck->fresh()->status);
    }

    // -- Une modification réelle vaut preuve de relecture -------------------

    public function test_editing_a_question_releases_the_ai_lock(): void
    {
        $quiz = $this->aiQuiz();
        $this->actingAs($this->teacher)->getJson("/api/quizzes/{$quiz->id}/editor")->assertOk();

        // L'enseignant corrige une question : le contenu a bien ete relu.
        $this->actingAs($this->teacher)
            ->patchJson("/api/questions/{$quiz->questions->first()->id}", [
                'statement' => 'Question IA corrigee',
                'type' => 'single',
                'options' => [
                    ['label' => 'Bonne', 'is_correct' => true],
                    ['label' => 'Mauvaise', 'is_correct' => false],
                ],
            ])
            ->assertOk();

        $quiz->refresh();

        $this->assertTrue((bool) $quiz->reviewed);
        $this->assertNotNull($quiz->reviewed_at);

        $this->actingAs($this->teacher)
            ->postJson("/api/quizzes/{$quiz->id}/publish")
            ->assertOk();
    }

    public function test_adding_a_question_to_an_ai_quiz_releases_the_lock(): void
    {
        $quiz = $this->aiQuiz();

        $this->actingAs($this->teacher)
            ->postJson("/api/quizzes/{$quiz->id}/questions", $this->questionPayload())
            ->assertStatus(201);

        $this->assertTrue((bool) $quiz->fresh()->reviewed);
    }

    public function test_deleting_a_question_releases_the_ai_lock(): void
    {
        $quiz = $this->aiQuiz();

        $this->actingAs($this->teacher)
            ->deleteJson("/api/questions/{$quiz->questions->first()->id}")
            ->assertNoContent();

        $this->assertTrue((bool) $quiz->fresh()->reviewed);
    }

    public function test_reordering_questions_releases_the_ai_lock(): void
    {
        $quiz = $this->aiQuiz();

        $this->actingAs($this->teacher)
            ->postJson("/api/quizzes/{$quiz->id}/questions/reorder", [
                'question_ids' => $quiz->questions->pluck('id')->all(),
            ])
            ->assertOk();

        $this->assertTrue((bool) $quiz->fresh()->reviewed);
    }

    public function test_a_failed_edit_does_not_release_the_ai_lock(): void
    {
        // Un appel refuse (validation) ne doit rien prouver du tout.
        $quiz = $this->aiQuiz();

        $this->actingAs($this->teacher)
            ->postJson("/api/quizzes/{$quiz->id}/questions", $this->questionPayload([
                'options' => [['label' => 'Seule', 'is_correct' => true]],
            ]))
            ->assertStatus(422);

        $this->assertFalse((bool) $quiz->fresh()->reviewed);
        $this->assertNull($quiz->fresh()->reviewed_at);
    }

    // -- `reviewed_at` n'est pas réécrit à chaque action ---------------------

    public function test_reviewing_twice_keeps_the_first_timestamp(): void
    {
        $quiz = $this->aiQuiz();
        $this->actingAs($this->teacher)->getJson("/api/quizzes/{$quiz->id}/editor")->assertOk();

        $this->actingAs($this->teacher)
            ->postJson("/api/quizzes/{$quiz->id}/review")
            ->assertOk();

        $first = $quiz->fresh()->reviewed_at;

        $this->travel(5)->minutes();

        $this->actingAs($this->teacher)
            ->postJson("/api/quizzes/{$quiz->id}/review")
            ->assertOk();

        $this->assertEquals(
            $first->toDateTimeString(),
            $quiz->fresh()->reviewed_at->toDateTimeString(),
            'La date de relecture doit rester celle de la premiere relecture effective.'
        );
    }

    public function test_the_review_endpoint_rejects_a_manual_quiz(): void
    {
        $quiz = Quiz::create([
            'classroom_id' => $this->classroom->id,
            'created_by' => $this->teacher->id,
            'title' => 'Quiz manuel',
            'status' => 'draft',
            'source' => 'manual',
        ]);

        $this->actingAs($this->teacher)
            ->postJson("/api/quizzes/{$quiz->id}/review")
            ->assertStatus(422);

        $this->assertNull($quiz->fresh()->reviewed_at);
    }

    public function test_another_teacher_cannot_release_the_ai_lock(): void
    {
        $quiz = $this->aiQuiz();
        $intruder = $this->teacher();

        $this->actingAs($intruder)
            ->postJson("/api/quizzes/{$quiz->id}/review")
            ->assertStatus(403);

        $this->assertFalse((bool) $quiz->fresh()->reviewed);
        $this->assertNull($quiz->fresh()->reviewed_at);
    }

    public function test_a_student_cannot_release_the_ai_lock(): void
    {
        $quiz = $this->aiQuiz();

        $this->actingAs($this->student)
            ->postJson("/api/quizzes/{$quiz->id}/review")
            ->assertStatus(403);

        $this->assertFalse((bool) $quiz->fresh()->reviewed);
    }

    /** @return array<string, mixed> */
    private function questionPayload(array $overrides = []): array
    {
        return array_merge([
            'statement' => 'Question ajoutee',
            'type' => 'single',
            'options' => [
                ['label' => 'Bonne', 'is_correct' => true],
                ['label' => 'Mauvaise', 'is_correct' => false],
            ],
        ], $overrides);
    }
}
