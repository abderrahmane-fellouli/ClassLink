<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Classroom;
use App\Models\FlashcardDeck;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * §12.5 — F-DEV-02 (depot d'un rendu) et F-QUI-08 (revision par flashcards).
 *
 * Ces deux flux etaient couverts cote gestionnaire mais pas cote etudiant :
 * il fallait un moyen de lire son propre rendu (et sa note) et de charger
 * les cartes d'un deck publie.
 */
class AssignmentSubmissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeStorage();
    }

    // -- F-DEV-02 : depot unique puis lecture de son propre rendu -------------

    public function test_a_student_depos_a_file_and_reads_back_its_own_submission(): void
    {
        [$classroom, $teacher, $student] = $this->classWithMember();
        $assignment = $this->assignment($classroom, $teacher);

        $this->actingAs($student)
            ->post("/api/assignments/{$assignment->id}/submissions", [
                'file' => $this->fakeUpload('rendu.pdf', null, 120),
            ])
            ->assertStatus(201);

        $this->assertSame(1, Submission::where('assignment_id', $assignment->id)->count());

        // Les ressources ne sont pas enveloppees (`withoutWrapping`) : la
        // reponse est l'objet lui-meme, contrairement a `index` qui renvoie `data`.
        $this->actingAs($student)
            ->getJson("/api/assignments/{$assignment->id}")
            ->assertOk()
            ->assertJsonPath('title', 'Devoir sur le cours')
            ->assertJsonPath('my_submission.file_name', 'rendu.pdf')
            ->assertJsonPath('my_submission.grade', null)
            ->assertJsonPath('my_submission.is_late', false);
    }

    public function test_a_second_submission_is_refused(): void
    {
        [$classroom, $teacher, $student] = $this->classWithMember();
        $assignment = $this->assignment($classroom, $teacher);

        $this->actingAs($student)
            ->post("/api/assignments/{$assignment->id}/submissions", [
                'file' => $this->fakeUpload('premier.pdf', null, 10),
            ])
            ->assertStatus(201);

        $this->actingAs($student)
            ->post("/api/assignments/{$assignment->id}/submissions", [
                'file' => $this->fakeUpload('second.pdf', null, 10),
            ])
            ->assertStatus(409);
    }

    public function test_a_late_submission_is_flagged_by_the_server(): void
    {
        [$classroom, $teacher, $student] = $this->classWithMember();
        $assignment = $this->assignment($classroom, $teacher);
        $assignment->update(['due_at' => now()->subMinute()]);

        $this->actingAs($student)
            ->post("/api/assignments/{$assignment->id}/submissions", [
                'file' => $this->fakeUpload('rendu.pdf', null, 10),
            ])
            ->assertStatus(201);

        $this->assertTrue((bool) Submission::where('assignment_id', $assignment->id)->first()->is_late);
    }

    public function test_a_submission_carries_the_grade_and_the_feedback_back_to_the_student(): void
    {
        [$classroom, $teacher, $student] = $this->classWithMember();
        $assignment = $this->assignment($classroom, $teacher);

        $submissionId = $this->actingAs($student)
            ->post("/api/assignments/{$assignment->id}/submissions", [
                'file' => $this->fakeUpload('rendu.pdf', null, 10),
            ])
            ->assertStatus(201)
            ->json('id');

        $this->actingAs($teacher)
            ->patchJson("/api/submissions/{$submissionId}", ['grade' => 17.5, 'feedback' => 'Bon travail.'])
            ->assertOk();

        $this->actingAs($student)
            ->getJson("/api/assignments/{$assignment->id}")
            ->assertOk()
            ->assertJsonPath('my_submission.grade', 17.5)
            ->assertJsonPath('my_submission.feedback', 'Bon travail.');
    }

    // -- Confidentialite : aucun rendu d'un autre etudiant n'est expose -------

    public function test_a_student_never_sees_another_students_submission(): void
    {
        [$classroom, $teacher, $student] = $this->classWithMember();
        $other = $this->joinAsMember($classroom);
        $assignment = $this->assignment($classroom, $teacher);

        $this->actingAs($student)
            ->post("/api/assignments/{$assignment->id}/submissions", [
                'file' => $this->fakeUpload('rendu.pdf', null, 10),
            ])
            ->assertStatus(201);

        // L'etudiant B ne voit que son propre rendu : celui de A est absent.
        $this->actingAs($other)
            ->getJson("/api/assignments/{$assignment->id}")
            ->assertOk()
            ->assertJsonPath('my_submission', null);
    }

    public function test_the_assignment_list_exposes_my_submission_only_to_its_owner(): void
    {
        [$classroom, $teacher, $student] = $this->classWithMember();
        $other = $this->joinAsMember($classroom);
        $assignment = $this->assignment($classroom, $teacher);

        $this->actingAs($student)
            ->post("/api/assignments/{$assignment->id}/submissions", [
                'file' => $this->fakeUpload('rendu.pdf', null, 10),
            ])
            ->assertStatus(201);

        $this->actingAs($student)
            ->getJson("/api/classes/{$classroom->id}/assignments")
            ->assertOk()
            ->assertJsonPath('data.0.my_submission.file_name', 'rendu.pdf');

        $theirs = $this->actingAs($other)
            ->getJson("/api/classes/{$classroom->id}/assignments")
            ->assertOk();
        $this->assertArrayNotHasKey('my_submission', $theirs->json('data.0'));
    }

    public function test_a_non_member_cannot_read_an_assignment(): void
    {
        [$classroom, $teacher] = $this->classWithMember();
        $assignment = $this->assignment($classroom, $teacher);

        $this->actingAs($this->student())
            ->getJson("/api/assignments/{$assignment->id}")
            ->assertForbidden();
    }

    // -- F-QUI-08 : consultation d'un deck publie ---------------------------

    public function test_a_student_reads_the_cards_of_a_published_deck(): void
    {
        [$classroom, $teacher, $student] = $this->classWithMember();

        $deck = $this->deck($classroom, $teacher, 'published');
        $deck->cards()->create(['front' => 'Que vaut 2+2 ?', 'back' => '4', 'position' => 0]);
        $deck->cards()->create(['front' => 'Capitale du Maroc ?', 'back' => 'Rabat', 'position' => 1]);

        // Le controleur renvoie la ressource directement (pas enveloppee dans `data`).
        $this->actingAs($student)
            ->getJson("/api/flashcard-decks/{$deck->id}")
            ->assertOk()
            ->assertJsonPath('title', 'Deck de test')
            ->assertJsonCount(2, 'cards')
            ->assertJsonPath('cards.0.front', 'Que vaut 2+2 ?');
    }

    public function test_a_draft_deck_is_invisible_to_a_student(): void
    {
        [$classroom, $teacher, $student] = $this->classWithMember();
        $draft = $this->deck($classroom, $teacher, 'draft');
        $draft->cards()->create(['front' => 'Secret', 'back' => 'Reponse', 'position' => 0]);

        $this->actingAs($student)
            ->getJson("/api/flashcard-decks/{$draft->id}")
            ->assertForbidden();

        // Le brouillon n'apparait pas non plus dans la liste de la classe.
        $this->actingAs($student)
            ->getJson("/api/classes/{$classroom->id}/flashcards")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_a_non_member_cannot_read_a_published_deck(): void
    {
        [$classroom, $teacher] = $this->classWithMember();
        $deck = $this->deck($classroom, $teacher, 'published');

        $this->actingAs($this->student())
            ->getJson("/api/flashcard-decks/{$deck->id}")
            ->assertForbidden();
    }

    public function test_a_teacher_keeps_access_to_their_own_draft_deck(): void
    {
        [$classroom, $teacher] = $this->classWithMember();
        $draft = $this->deck($classroom, $teacher, 'draft');
        $draft->cards()->create(['front' => 'Brouillon', 'back' => 'Contenu', 'position' => 0]);

        $this->actingAs($teacher)
            ->getJson("/api/flashcard-decks/{$draft->id}")
            ->assertOk()
            ->assertJsonCount(1, 'cards');
    }

    // -- Outils -------------------------------------------------------------

    private function assignment(Classroom $classroom, User $teacher): Assignment
    {
        return Assignment::create([
            'classroom_id' => $classroom->id,
            'created_by' => $teacher->id,
            'title' => 'Devoir sur le cours',
            'instructions' => 'Rendre un PDF.',
            'due_at' => now()->addWeek(),
        ]);
    }

    private function deck(Classroom $classroom, User $teacher, string $status): FlashcardDeck
    {
        return FlashcardDeck::create([
            'classroom_id' => $classroom->id,
            'title' => 'Deck de test',
            'source' => 'manual',
            'status' => $status,
            'reviewed' => true,
        ]);
    }

    /** Second etudiant accepte dans la classe, pour tester l'isolation. */
    private function joinAsMember(Classroom $classroom): User
    {
        $student = $this->student();

        $this->actingAs($student)
            ->post('/api/join-requests', ['code' => $classroom->join_code])
            ->assertStatus(201);

        $membershipId = $this->actingAs($student)
            ->getJson('/api/join-requests/mine')
            ->json('data.0.id');

        $this->actingAs(User::find($classroom->teacher_id))
            ->postJson("/api/join-requests/{$membershipId}/accept")
            ->assertStatus(200);

        return $student->fresh();
    }
}
