<?php

namespace Tests\Feature;

use App\Models\Attempt;
use App\Models\FlashcardDeck;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * F-QUI-04 (reprise de tentative) et F-QUI-08 (edition du contenu d'un deck).
 *
 * Deux manques de la version initiale :
 *  - sans recherche de tentative en cours, un rechargement de page ou un
 *    changement d'appareil faisait perdre la tentative et consommait un quota
 *    de `max_attempts` sans que l'etudiant ait repondu ;
 *  - les decks etaient creation / relecture / publication / suppression, mais
 *    pas editables, donc le contenu genere par IA ne pouvait etre corrige que
 *    par recreation.
 */
class QuizResumeAndFlashcardEditTest extends TestCase
{
    use RefreshDatabase;

    public function test_noop_card_edit_does_not_certify_ai_review(): void
    {
        $deck = $this->aiDeck();
        $card = $deck->cards()->create(['front' => 'Front', 'back' => 'Back', 'position' => 0]);
        $this->actingAs($this->teacher)
            ->patchJson("/api/flashcard-decks/{$deck->id}/cards/{$card->id}", ['front' => 'Front', 'back' => 'Back'])
            ->assertOk();
        $this->assertFalse($deck->fresh()->reviewed);
        $this->postJson("/api/flashcard-decks/{$deck->id}/publish")->assertStatus(409);
    }

    private $teacher;

    private $student;

    private $classroom;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->classroom, $this->teacher, $this->student] = $this->classWithMember();
    }

    private function quizWithShuffle(array $overrides = []): Quiz
    {
        $quiz = Quiz::create(array_merge([
            'classroom_id' => $this->classroom->id,
            'created_by' => $this->teacher->id,
            'title' => 'Quiz de reprise',
            'status' => 'published',
            'source' => 'manual',
            'reviewed' => true,
            'reviewed_at' => now(),
            'shuffle' => true,
            'time_limit_min' => 30,
            'max_attempts' => 3,
        ], $overrides));

        foreach (['A', 'B', 'C'] as $i => $label) {
            $question = Question::create([
                'quiz_id' => $quiz->id,
                'type' => 'single',
                'statement' => 'Question '.$label,
                'position' => $i,
            ]);
            $question->options()->createMany([
                ['label' => 'Vrai', 'is_correct' => true, 'position' => 0],
                ['label' => 'Faux', 'is_correct' => false, 'position' => 1],
            ]);
        }

        return $quiz->fresh();
    }

    private function activeAttempt(Quiz $quiz): Attempt
    {
        $response = $this->actingAs($this->student)
            ->postJson("/api/quizzes/{$quiz->id}/attempts");

        $response->assertCreated();

        return Attempt::findOrFail($response->json('attempt_id'));
    }

    /** Reprise : la tentative en cours est rendue avec l'ordre fige. */
    public function test_active_attempt_returns_frozen_order_and_saved_answers(): void
    {
        $quiz = $this->quizWithShuffle();
        $attempt = $this->activeAttempt($quiz);

        $frozenOrder = $attempt->question_order;

        $firstQuestion = Question::where('quiz_id', $quiz->id)->orderBy('position')->first();

        $this->actingAs($this->student)
            ->patchJson("/api/attempts/{$attempt->id}/answers", [
                'answers' => [
                    ['question_id' => $firstQuestion->id, 'option_ids' => [$firstQuestion->options()->first()->id]],
                ],
            ])
            ->assertOk();

        $response = $this->actingAs($this->student)
            ->getJson("/api/quizzes/{$quiz->id}/attempts/active");

        $response->assertOk()
            ->assertJsonPath('attempt.attempt_id', $attempt->id)
            ->assertJsonPath('attempt.shuffled', true)
            ->assertJsonPath('attempt.time_limit_min', 30);

        // Ordre fige identique a celui du demarrage, meme si l'ordre `position`
        // du quiz est different.
        $servedOrder = collect($response->json('attempt.questions'))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $this->assertSame($frozenOrder, $servedOrder);

        // La réponse enregistrée revient : pas de perte au rechargement.
        // `answers` est un objet indexé par question_id.
        $saved = $response->json('attempt.answers');

        $this->assertArrayHasKey(
            (string) $firstQuestion->id,
            $saved,
            'La réponse enregistrée doit revenir.'
        );

        $this->assertSame(
            [$firstQuestion->options()->first()->id],
            collect($saved[(string) $firstQuestion->id]['option_ids'])->map(fn ($id) => (int) $id)->all()
        );

        // RG-13 : jamais les bonnes reponses pendant la reprise.
        $this->assertStringNotContainsString('is_correct', $response->getContent());
        $this->assertStringNotContainsString('explanation', $response->getContent());
    }

    /** Aucune tentative en cours : `attempt` est null, pas une erreur. */
    public function test_active_attempt_is_null_before_starting(): void
    {
        $quiz = $this->quizWithShuffle();

        $this->actingAs($this->student)
            ->getJson("/api/quizzes/{$quiz->id}/attempts/active")
            ->assertOk()
            ->assertJsonPath('attempt', null);
    }

    /** Une tentative soumise n'est pas reprenable. */
    public function test_submitted_attempt_is_not_resumable(): void
    {
        $quiz = $this->quizWithShuffle();
        $attempt = $this->activeAttempt($quiz);

        $question = Question::where('quiz_id', $quiz->id)->orderBy('position')->first();

        $this->actingAs($this->student)
            ->postJson("/api/attempts/{$attempt->id}/submit", [
                'answers' => [
                    ['question_id' => $question->id, 'option_ids' => [$question->options()->first()->id]],
                ],
            ])
            ->assertOk();

        $this->actingAs($this->student)
            ->getJson("/api/quizzes/{$quiz->id}/attempts/active")
            ->assertOk()
            ->assertJsonPath('attempt', null);
    }

    /** RG-12 : une tentative dont le temps est ecoule est finalisee, pas rendue. */
    public function test_expired_attempt_is_finalised_not_resumable(): void
    {
        $quiz = $this->quizWithShuffle(['time_limit_min' => 10]);
        $attempt = $this->activeAttempt($quiz);

        $this->travel(11)->minutes();

        $this->actingAs($this->student)
            ->getJson("/api/quizzes/{$quiz->id}/attempts/active")
            ->assertOk()
            ->assertJsonPath('attempt', null);

        $this->assertNotNull($attempt->fresh()->submitted_at);
    }

    /** Un autre etudiant ne voit ni ne reprend la tentative d'autrui. */
    public function test_other_student_cannot_resume_someone_elses_attempt(): void
    {
        $quiz = $this->quizWithShuffle();
        $this->activeAttempt($quiz);

        $stranger = User::factory()->create(['role' => 'student']);

        $this->actingAs($stranger)
            ->getJson("/api/quizzes/{$quiz->id}/attempts/active")
            ->assertForbidden();
    }

    /** Un enseignant n'utilise pas la reprise d'un etudiant. */
    public function test_teacher_cannot_use_student_resume_route(): void
    {
        $quiz = $this->quizWithShuffle();

        $this->actingAs($this->teacher)
            ->getJson("/api/quizzes/{$quiz->id}/attempts/active")
            ->assertForbidden();
    }

    /** Un brouillon reste invisible a la reprise (RG-11). */
    public function test_draft_quiz_cannot_be_resumed(): void
    {
        $quiz = $this->quizWithShuffle(['status' => 'draft']);

        $this->actingAs($this->student)
            ->getJson("/api/quizzes/{$quiz->id}/attempts/active")
            ->assertForbidden();
    }

    /** La reprise ne consomme pas de tentative supplementaire. */
    public function test_resume_does_not_consume_an_extra_attempt(): void
    {
        $quiz = $this->quizWithShuffle(['max_attempts' => 1]);
        $this->activeAttempt($quiz);

        $this->actingAs($this->student)
            ->getJson("/api/quizzes/{$quiz->id}/attempts/active")
            ->assertOk();

        // Le quota reste atteint mais la tentative existante est la seule :
        // demarrer une nouvelle tentative reste refuse.
        $this->actingAs($this->student)
            ->postJson("/api/quizzes/{$quiz->id}/attempts")
            ->assertStatus(409);

        $this->assertSame(1, Attempt::where('quiz_id', $quiz->id)->count());
    }

    private function aiDeck(array $overrides = []): FlashcardDeck
    {
        return FlashcardDeck::create(array_merge([
            'classroom_id' => $this->classroom->id,
            'title' => 'Deck IA',
            'source' => 'ai',
            'status' => 'draft',
            'reviewed' => false,
            'reviewed_at' => null,
        ], $overrides));
    }

    /** F-QUI-08 : le titre d'un deck se modifie par son proprietaire. */
    public function test_owner_can_rename_deck(): void
    {
        $deck = $this->aiDeck();

        $this->actingAs($this->teacher)
            ->patchJson("/api/flashcard-decks/{$deck->id}", ['title' => 'Titre corrige'])
            ->assertOk()
            ->assertJsonPath('title', 'Titre corrige');

        $this->assertSame('Titre corrige', $deck->fresh()->title);
    }

    /** F-QUI-08 : recto/verso d'une carte modifiables. */
    public function test_owner_can_edit_card_content(): void
    {
        $deck = $this->aiDeck();
        $card = $deck->cards()->create(['front' => 'Recto', 'back' => 'Verso', 'position' => 0]);

        $this->actingAs($this->teacher)
            ->patchJson("/api/flashcard-decks/{$deck->id}/cards/{$card->id}", [
                'front' => 'Recto corrige',
                'back' => 'Verso corrige',
            ])
            ->assertOk()
            ->assertJsonPath('data.front', 'Recto corrige')
            ->assertJsonPath('data.back', 'Verso corrige');
    }

    /** Une edition reelle vaut relecture : le verrou IA peut etre leve. */
    public function test_editing_card_content_marks_deck_reviewed(): void
    {
        $deck = $this->aiDeck();
        $card = $deck->cards()->create(['front' => 'Recto', 'back' => 'Verso', 'position' => 0]);

        $this->assertSame(409, $this->actingAs($this->teacher)
            ->postJson("/api/flashcard-decks/{$deck->id}/publish")->status());

        $this->actingAs($this->teacher)
            ->patchJson("/api/flashcard-decks/{$deck->id}/cards/{$card->id}", [
                'front' => 'Corrige',
                'back' => 'Corrige',
            ])
            ->assertOk();

        $deck->refresh();
        $this->assertTrue($deck->reviewed);
        $this->assertNotNull($deck->reviewed_at);

        $this->actingAs($this->teacher)
            ->postJson("/api/flashcard-decks/{$deck->id}/publish")
            ->assertOk()
            ->assertJsonPath('status', 'published');
    }

    /** Suppression d'une carte. */
    public function test_owner_can_delete_card(): void
    {
        $deck = $this->aiDeck();
        $keep = $deck->cards()->create(['front' => 'A', 'back' => 'A', 'position' => 0]);
        $card = $deck->cards()->create(['front' => 'B', 'back' => 'B', 'position' => 1]);

        $this->actingAs($this->teacher)
            ->deleteJson("/api/flashcard-decks/{$deck->id}/cards/{$card->id}")
            ->assertNoContent();

        $this->assertNull(FlashcardDeck::find($deck->id)->cards()->find($card->id));
        $this->assertNotNull(FlashcardDeck::find($deck->id)->cards()->find($keep->id));
    }

    /** Un eleve ne modifie ni le titre ni le contenu d'un deck publie. */
    public function test_student_cannot_edit_published_deck(): void
    {
        $deck = $this->aiDeck(['status' => 'published', 'reviewed' => true, 'reviewed_at' => now()]);
        $card = $deck->cards()->create(['front' => 'A', 'back' => 'A', 'position' => 0]);

        $this->actingAs($this->student)
            ->patchJson("/api/flashcard-decks/{$deck->id}", ['title' => 'Piratage'])
            ->assertForbidden();

        $this->actingAs($this->student)
            ->patchJson("/api/flashcard-decks/{$deck->id}/cards/{$card->id}", [
                'front' => 'X', 'back' => 'X',
            ])
            ->assertForbidden();

        $this->actingAs($this->student)
            ->deleteJson("/api/flashcard-decks/{$deck->id}/cards/{$card->id}")
            ->assertForbidden();

        $this->assertSame('Deck IA', $deck->fresh()->title);
        $this->assertSame('A', $card->fresh()->front);
    }

    /** Le proprietaire d'une autre classe ne touche pas ce deck. */
    public function test_other_teacher_cannot_edit_deck(): void
    {
        $deck = $this->aiDeck();
        $card = $deck->cards()->create(['front' => 'A', 'back' => 'A', 'position' => 0]);

        $otherTeacher = User::factory()->create(['role' => 'teacher']);

        $this->actingAs($otherTeacher)
            ->patchJson("/api/flashcard-decks/{$deck->id}", ['title' => 'Piratage'])
            ->assertForbidden();

        $this->actingAs($otherTeacher)
            ->patchJson("/api/flashcard-decks/{$deck->id}/cards/{$card->id}", [
                'front' => 'X', 'back' => 'X',
            ])
            ->assertForbidden();

        $this->actingAs($otherTeacher)
            ->deleteJson("/api/flashcard-decks/{$deck->id}/cards/{$card->id}")
            ->assertForbidden();
    }

    /** Une carte d'un autre deck n'est pas modifiable via ce deck (404). */
    public function test_card_from_another_deck_is_rejected(): void
    {
        $deck = $this->aiDeck();
        $otherDeck = $this->aiDeck(['title' => 'Autre deck']);
        $foreignCard = $otherDeck->cards()->create(['front' => 'A', 'back' => 'A', 'position' => 0]);

        $this->actingAs($this->teacher)
            ->patchJson("/api/flashcard-decks/{$deck->id}/cards/{$foreignCard->id}", [
                'front' => 'X', 'back' => 'X',
            ])
            ->assertNotFound();

        $this->actingAs($this->teacher)
            ->deleteJson("/api/flashcard-decks/{$deck->id}/cards/{$foreignCard->id}")
            ->assertNotFound();

        $this->assertSame('A', $foreignCard->fresh()->front);
    }

    /** Une classe archivee est en lecture seule : plus aucune edition. */
    public function test_archived_class_blocks_deck_edits(): void
    {
        $deck = $this->aiDeck();
        $card = $deck->cards()->create(['front' => 'A', 'back' => 'A', 'position' => 0]);

        $this->classroom->update(['status' => 'archived', 'archived_at' => now()]);

        $this->actingAs($this->teacher)
            ->patchJson("/api/flashcard-decks/{$deck->id}", ['title' => 'Apres archivage'])
            ->assertForbidden();

        $this->actingAs($this->teacher)
            ->patchJson("/api/flashcard-decks/{$deck->id}/cards/{$card->id}", [
                'front' => 'X', 'back' => 'X',
            ])
            ->assertForbidden();
    }

    /** Validation : champs obligatoires et longueurs. */
    public function test_edit_validation_rejects_invalid_payloads(): void
    {
        $deck = $this->aiDeck();
        $card = $deck->cards()->create(['front' => 'A', 'back' => 'A', 'position' => 0]);

        $this->actingAs($this->teacher)
            ->patchJson("/api/flashcard-decks/{$deck->id}", [])
            ->assertJsonValidationErrors(['title']);

        $this->actingAs($this->teacher)
            ->patchJson("/api/flashcard-decks/{$deck->id}", ['title' => str_repeat('a', 256)])
            ->assertJsonValidationErrors(['title']);

        $this->actingAs($this->teacher)
            ->patchJson("/api/flashcard-decks/{$deck->id}/cards/{$card->id}", ['front' => 'seul'])
            ->assertJsonValidationErrors(['back']);

        $this->actingAs($this->teacher)
            ->patchJson("/api/flashcard-decks/{$deck->id}/cards/{$card->id}", [
                'front' => str_repeat('a', 2001),
                'back' => 'ok',
            ])
            ->assertJsonValidationErrors(['front']);
    }
}
