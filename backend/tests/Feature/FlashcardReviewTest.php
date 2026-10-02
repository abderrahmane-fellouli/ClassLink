<?php

namespace Tests\Feature;

use App\Models\Flashcard;
use App\Models\FlashcardDeck;
use App\Models\FlashcardReview;
use App\Models\Membership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * §11 (F-QUI-08) — révision par flashcards.
 *
 * Deux défauts corrigés ici :
 *  - l'écran d'étude levait une TypeError avant le chargement des données ;
 *  - l'état « su » / « à revoir » n'existait que dans `useState`, donc perdu
 *    au rechargement. Il est désormais persisté par carte et par étudiant.
 *
 * Les règles existantes sont vérifiées explicitement : deck publié visible
 * des étudiants, brouillon masqué, aucun accès hors classe.
 */
class FlashcardReviewTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Deck publié avec deux cartes, dans une classe dont l'étudiant est
     * membre accepté.
     *
     * @return array{0: \App\Models\Classroom, 1: \App\Models\User, 2: \App\Models\User, 3: \App\Models\FlashcardDeck, 4: \App\Models\Classroom, 5: \App\Models\User}
     */
    private function publishedDeck(string $status = 'published'): array
    {
        [$classroom, $teacher, $student] = $this->classWithMember();

        $deck = FlashcardDeck::create([
            'classroom_id' => $classroom->id,
            'title' => 'Révision — notions clés',
            'source' => 'manual',
            'status' => $status,
            'reviewed' => true,
        ]);

        foreach ([['Question A', 'Réponse A'], ['Question B', 'Réponse B']] as $i => [$front, $back]) {
            $deck->cards()->create([
                'front' => $front,
                'back' => $back,
                'position' => $i,
            ]);
        }

        // Une seconde classe sert au contrôle d'accès inter-classes.
        [$otherClass, $otherTeacher] = $this->classWithMember();

        return [$classroom, $teacher, $student, $deck, $otherClass, $otherTeacher];
    }

    // -- Persistance de l'état de révision -----------------------------------

    public function test_a_student_can_mark_a_card_as_known(): void
    {
        [, , $student, $deck] = $this->publishedDeck();
        $card = $deck->cards()->firstOrFail();

        $this->actingAs($student)
            ->postJson("/api/flashcard-decks/{$deck->id}/cards/{$card->id}/review", ['known' => true])
            ->assertOk()
            ->assertJsonPath('data.known', true);

        $this->assertDatabaseHas('flashcard_reviews', [
            'user_id' => $student->id,
            'flashcard_id' => $card->id,
            'known' => true,
        ]);
    }

    public function test_a_student_can_mark_a_card_for_review(): void
    {
        [, , $student, $deck] = $this->publishedDeck();
        $card = $deck->cards()->firstOrFail();

        $this->actingAs($student)
            ->postJson("/api/flashcard-decks/{$deck->id}/cards/{$card->id}/review", ['known' => false])
            ->assertOk()
            ->assertJsonPath('data.known', false);

        $this->assertDatabaseHas('flashcard_reviews', [
            'user_id' => $student->id,
            'flashcard_id' => $card->id,
            'known' => false,
        ]);
    }

    /** Reprendre une carte remplace l'état précédent (une seule ligne). */
    public function test_marking_a_card_twice_updates_the_same_row(): void
    {
        [, , $student, $deck] = $this->publishedDeck();
        $card = $deck->cards()->firstOrFail();

        $this->actingAs($student)
            ->postJson("/api/flashcard-decks/{$deck->id}/cards/{$card->id}/review", ['known' => false])
            ->assertOk();

        $this->actingAs($student)
            ->postJson("/api/flashcard-decks/{$deck->id}/cards/{$card->id}/review", ['known' => true])
            ->assertOk()
            ->assertJsonPath('data.known', true);

        $this->assertSame(1, FlashcardReview::where('user_id', $student->id)->count());
    }

    /** Le rechargement relit l'état depuis la base, pas depuis le poste. */
    public function test_the_state_survives_a_reload(): void
    {
        [, , $student, $deck] = $this->publishedDeck();
        $cards = $deck->cards()->get();
        $first = $cards->first();
        $second = $cards->last();

        $this->actingAs($student)
            ->postJson("/api/flashcard-decks/{$deck->id}/cards/{$first->id}/review", ['known' => true]);
        $this->actingAs($student)
            ->postJson("/api/flashcard-decks/{$deck->id}/cards/{$second->id}/review", ['known' => false]);

        // Nouvelle lecture, comme après un rechargement de page.
        $payload = $this->actingAs($student)
            ->getJson("/api/flashcard-decks/{$deck->id}")
            ->assertOk()
            ->json('cards');

        $this->assertTrue($payload[0]['known']);
        $this->assertFalse($payload[1]['known']);
    }

    public function test_a_card_never_reviewed_is_returned_as_null(): void
    {
        [, , $student, $deck] = $this->publishedDeck();

        $cards = $this->actingAs($student)
            ->getJson("/api/flashcard-decks/{$deck->id}")
            ->assertOk()
            ->json('cards');

        $this->assertCount(2, $cards);
        $this->assertNull($cards[0]['known']);
    }

    public function test_the_review_state_is_private_to_each_student(): void
    {
        [, , $student, $deck] = $this->publishedDeck();
        $card = $deck->cards()->firstOrFail();
        $other = $this->student();

        $this->actingAs($student)
            ->postJson("/api/flashcard-decks/{$deck->id}/cards/{$card->id}/review", ['known' => true]);

        $this->assertNull(
            $this->actingAs($other)
                ->getJson("/api/flashcard-decks/{$deck->id}")
                ->json('cards.0.known')
        );
    }

    public function test_known_is_required(): void
    {
        [, , $student, $deck] = $this->publishedDeck();
        $card = $deck->cards()->firstOrFail();

        $this->actingAs($student)
            ->postJson("/api/flashcard-decks/{$deck->id}/cards/{$card->id}/review", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('known');
    }

    // -- Contrôle d'accès inchangé -------------------------------------------

    public function test_a_student_from_another_class_cannot_review_a_card(): void
    {
        [, , , $deck] = $this->publishedDeck();
        $card = $deck->cards()->firstOrFail();
        $outsider = $this->student();

        $this->actingAs($outsider)
            ->postJson("/api/flashcard-decks/{$deck->id}/cards/{$card->id}/review", ['known' => true])
            ->assertForbidden();

        $this->assertDatabaseMissing('flashcard_reviews', ['flashcard_id' => $card->id]);
    }

    public function test_a_pending_student_cannot_review_a_card(): void
    {
        [, , , $deck] = $this->publishedDeck();
        $card = $deck->cards()->firstOrFail();
        $pending = $this->student();

        Membership::create([
            'classroom_id' => $deck->classroom_id,
            'student_id' => $pending->id,
            'status' => 'pending',
            'requested_at' => now(),
        ]);

        $this->actingAs($pending)
            ->postJson("/api/flashcard-decks/{$deck->id}/cards/{$card->id}/review", ['known' => true])
            ->assertForbidden();
    }

    /** Les règles de publication restent inchangées : pas de révision sur un brouillon. */
    public function test_a_draft_deck_is_not_reviewable_by_a_student(): void
    {
        [, , $student, $deck] = $this->publishedDeck('draft');
        $card = $deck->cards()->firstOrFail();

        $this->actingAs($student)
            ->postJson("/api/flashcard-decks/{$deck->id}/cards/{$card->id}/review", ['known' => true])
            ->assertForbidden();

        $this->assertDatabaseMissing('flashcard_reviews', ['flashcard_id' => $card->id]);
    }

    /** La carte doit appartenir au deck de l'URL (pas d'écriture croisée). */
    public function test_a_card_from_another_deck_is_refused(): void
    {
        [, , $student, $deck] = $this->publishedDeck();

        $foreignDeck = FlashcardDeck::create([
            'classroom_id' => $deck->classroom_id,
            'title' => 'Autre deck',
            'source' => 'manual',
            'status' => 'published',
            'reviewed' => true,
        ]);

        $foreignCard = $foreignDeck->cards()->create([
            'front' => 'Front',
            'back' => 'Back',
            'position' => 0,
        ]);

        $this->actingAs($student)
            ->postJson("/api/flashcard-decks/{$deck->id}/cards/{$foreignCard->id}/review", ['known' => true])
            ->assertNotFound();

        $this->assertDatabaseMissing('flashcard_reviews', ['flashcard_id' => $foreignCard->id]);
    }

    public function test_an_anonymous_student_cannot_review_a_card(): void
    {
        [, , , $deck] = $this->publishedDeck();
        $card = $deck->cards()->firstOrFail();

        $this->postJson("/api/flashcard-decks/{$deck->id}/cards/{$card->id}/review", ['known' => true])
            ->assertUnauthorized();

        $this->assertDatabaseMissing('flashcard_reviews', ['flashcard_id' => $card->id]);
    }

    /** L'effacement du deck emporte l'état de révision (cascade). */
    public function test_deleting_a_deck_removes_its_reviews(): void
    {
        [, , $student, $deck] = $this->publishedDeck();
        $card = $deck->cards()->firstOrFail();

        $this->actingAs($student)
            ->postJson("/api/flashcard-decks/{$deck->id}/cards/{$card->id}/review", ['known' => true]);

        $this->assertDatabaseHas('flashcard_reviews', ['flashcard_id' => $card->id]);

        $this->actingAs($this->admin())->deleteJson("/api/flashcard-decks/{$deck->id}")->assertNoContent();

        $this->assertDatabaseMissing('flashcard_reviews', ['flashcard_id' => $card->id]);
    }

    public function test_a_teacher_can_still_review_their_own_draft(): void
    {
        [, $teacher, , $deck] = $this->publishedDeck('draft');
        $card = $deck->cards()->firstOrFail();

        $this->actingAs($teacher)
            ->postJson("/api/flashcard-decks/{$deck->id}/cards/{$card->id}/review", ['known' => true])
            ->assertOk();
    }
}