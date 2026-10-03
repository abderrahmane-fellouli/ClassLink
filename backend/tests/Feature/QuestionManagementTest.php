<?php

namespace Tests\Feature;

use App\Models\Classroom;
use App\Models\Question;
use App\Models\Quiz;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * F-QUI-01 / US-25 — questions d'un quiz en brouillon (§12.4).
 *
 * Ces routes n'existaient pas : les questions ne pouvaient être fournies
 * qu'à la création du quiz. Elles sont réservées au **brouillon** : modifier
 * l'énoncé d'un quiz publié changerait l'épreuve pour les étudiants qui
 * répondent encore et invaliderait les résultats déjà calculés.
 */
class QuestionManagementTest extends TestCase
{
    use RefreshDatabase;

    private Classroom $classroom;

    private $teacher;

    private $student;

    private Quiz $quiz;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->classroom, $this->teacher, $this->student] = $this->classWithMember();

        $this->quiz = Quiz::create([
            'classroom_id' => $this->classroom->id,
            'created_by' => $this->teacher->id,
            'title' => 'Quiz brouillon',
            'status' => 'draft',
            'source' => 'manual',
            'reviewed' => true,
            'time_limit_min' => 10,
            'max_attempts' => 1,
            'shuffle' => false,
            'show_answers' => true,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'statement' => 'Quelle est la capitale du Maroc ?',
            'type' => 'single',
            'explanation' => 'Rabat.',
            'options' => [
                ['label' => 'Rabat', 'is_correct' => true],
                ['label' => 'Casablanca', 'is_correct' => false],
            ],
        ], $overrides);
    }

    private function seedQuestions(int $count = 3): void
    {
        foreach (range(1, $count) as $i) {
            $question = $this->quiz->questions()->create([
                'statement' => "Question {$i}",
                'type' => 'single',
                'explanation' => null,
                'position' => $this->quiz->questions()->count(),
            ]);

            $question->options()->create(['label' => 'Bonne', 'is_correct' => true]);
            $question->options()->create(['label' => 'Mauvaise', 'is_correct' => false]);
        }
    }

    private function ids(): array
    {
        return $this->quiz->questions()->orderBy('position')->pluck('id')->all();
    }

    // -- Création (US-25) ---------------------------------------------------

    public function test_a_teacher_adds_a_question_to_a_draft_quiz(): void
    {
        $this->seedQuestions(2);

        $response = $this->actingAs($this->teacher)
            ->postJson("/api/quizzes/{$this->quiz->id}/questions", $this->payload())
            ->assertStatus(201);

        $this->assertSame('Quelle est la capitale du Maroc ?', $response->json('statement'));
        $this->assertSame('single', $response->json('type'));
        $this->assertSame('Rabat.', $response->json('explanation'));
        $this->assertCount(2, $response->json('options'));

        // La question est ajoutée en fin de questionnaire.
        $this->assertSame(2, (int) $response->json('position'));
        $this->assertSame(3, $this->quiz->questions()->count());
    }

    public function test_a_teacher_adds_a_question_to_an_empty_quiz(): void
    {
        $this->actingAs($this->teacher)
            ->postJson("/api/quizzes/{$this->quiz->id}/questions", $this->payload())
            ->assertStatus(201)
            ->assertJsonPath('position', 0);
    }

    public function test_a_question_with_several_correct_answers_is_accepted_for_multiple_choice(): void
    {
        $this->actingAs($this->teacher)
            ->postJson("/api/quizzes/{$this->quiz->id}/questions", $this->payload([
                'type' => 'multiple',
                'options' => [
                    ['label' => 'A', 'is_correct' => true],
                    ['label' => 'B', 'is_correct' => true],
                    ['label' => 'C', 'is_correct' => false],
                ],
            ]))
            ->assertStatus(201)
            ->assertJsonPath('type', 'multiple');
    }

    // -- Modification -------------------------------------------------------

    public function test_a_teacher_updates_a_question(): void
    {
        $this->seedQuestions(1);
        $id = $this->ids()[0];

        $this->actingAs($this->teacher)
            ->patchJson("/api/questions/{$id}", $this->payload([
                'statement' => 'Énoncé corrigé',
                'type' => 'true_false',
                'options' => [
                    ['label' => 'Vrai', 'is_correct' => true],
                    ['label' => 'Faux', 'is_correct' => false],
                ],
            ]))
            ->assertOk()
            ->assertJsonPath('statement', 'Énoncé corrigé')
            ->assertJsonPath('type', 'true_false');

        $this->assertDatabaseHas('questions', [
            'id' => $id,
            'statement' => 'Énoncé corrigé',
        ]);
    }

    public function test_updating_a_question_replaces_its_options(): void
    {
        $this->seedQuestions(1);
        $id = $this->ids()[0];
        $oldOptions = Question::findOrFail($id)->options->pluck('id')->all();

        $this->actingAs($this->teacher)
            ->patchJson("/api/questions/{$id}", $this->payload([
                'options' => [
                    ['label' => 'Nouvelle A', 'is_correct' => true],
                    ['label' => 'Nouvelle B', 'is_correct' => false],
                    ['label' => 'Nouvelle C', 'is_correct' => false],
                ],
            ]))
            ->assertOk()
            ->assertJsonCount(3, 'options');

        // Les anciennes options ont disparu : `is_correct` ne peut pas avoir
        // été corrigé sur place sur une option déjà utilisée.
        foreach ($oldOptions as $oldId) {
            $this->assertDatabaseMissing('options', ['id' => $oldId]);
        }
    }

    // -- Réordonnancement ---------------------------------------------------

    public function test_a_teacher_reorders_questions(): void
    {
        $this->seedQuestions(3);
        $ids = $this->ids();
        $reversed = array_reverse($ids);

        $this->actingAs($this->teacher)
            ->postJson("/api/quizzes/{$this->quiz->id}/questions/reorder", [
                'question_ids' => $reversed,
            ])
            ->assertOk();

        $this->assertSame($reversed, $this->ids());
        $this->assertSame(
            range(0, 2),
            $this->quiz->questions()->orderBy('position')->pluck('position')->map(fn ($p) => (int) $p)->all(),
            'Les positions doivent être renumérotées sans trou.'
        );
    }

    public function test_reordering_a_partial_list_is_refused(): void
    {
        $this->seedQuestions(3);
        $ids = $this->ids();

        // Une liste partielle laisserait des questions sans position.
        $this->actingAs($this->teacher)
            ->postJson("/api/quizzes/{$this->quiz->id}/questions/reorder", [
                'question_ids' => array_slice($ids, 0, 2),
            ])
            ->assertStatus(422);

        $this->assertSame($ids, $this->ids(), 'Un refus ne doit pas réordonner.');
    }

    public function test_reordering_with_a_foreign_question_is_refused(): void
    {
        $this->seedQuestions(2);

        // Quiz d'un autre enseignant.
        $otherClass = Classroom::factory()->create(['teacher_id' => $this->teacher()->id]);
        $otherQuiz = Quiz::create([
            'classroom_id' => $otherClass->id,
            'created_by' => $this->teacher()->id,
            'title' => 'Autre quiz',
            'status' => 'draft',
            'source' => 'manual',
            'reviewed' => true,
        ]);
        $foreign = $otherQuiz->questions()->create([
            'statement' => 'Intruse',
            'type' => 'single',
            'position' => 0,
        ]);

        $this->actingAs($this->teacher)
            ->postJson("/api/quizzes/{$this->quiz->id}/questions/reorder", [
                'question_ids' => [...$this->ids(), $foreign->id],
            ])
            ->assertStatus(422);
    }

    public function test_reordering_a_duplicated_list_is_refused(): void
    {
        $this->seedQuestions(2);
        $ids = $this->ids();

        $this->actingAs($this->teacher)
            ->postJson("/api/quizzes/{$this->quiz->id}/questions/reorder", [
                'question_ids' => [$ids[0], $ids[0]],
            ])
            ->assertStatus(422);
    }

    public function test_reordering_requires_the_permutation_to_be_a_list(): void
    {
        $this->seedQuestions(2);

        $this->actingAs($this->teacher)
            ->postJson("/api/quizzes/{$this->quiz->id}/questions/reorder", [
                'question_ids' => 'pas-une-liste',
            ])
            ->assertStatus(422);
    }

    // -- Suppression --------------------------------------------------------

    public function test_a_teacher_deletes_a_question(): void
    {
        $this->seedQuestions(3);
        $ids = $this->ids();
        $target = $ids[1];

        $this->actingAs($this->teacher)
            ->deleteJson("/api/questions/{$target}")
            ->assertNoContent();

        $this->assertDatabaseMissing('questions', ['id' => $target]);
        $this->assertSame(
            [$ids[0], $ids[2]],
            $this->ids(),
            'La suppression ne doit pas laisser de trou dans les positions.'
        );
        $this->assertSame(
            [0, 1],
            $this->quiz->questions()->orderBy('position')->pluck('position')->map(fn ($p) => (int) $p)->all(),
        );
    }

    public function test_deleting_a_question_removes_its_options(): void
    {
        $this->seedQuestions(1);
        $question = Question::with('options')->findOrFail($this->ids()[0]);
        $optionIds = $question->options->pluck('id')->all();

        $this->actingAs($this->teacher)
            ->deleteJson("/api/questions/{$question->id}")
            ->assertNoContent();

        foreach ($optionIds as $optionId) {
            $this->assertDatabaseMissing('options', ['id' => $optionId]);
        }
    }

    // -- Validation (§11 : au moins 2 options, au moins 1 bonne réponse) ----

    public function test_a_question_needs_at_least_two_options(): void
    {
        $this->actingAs($this->teacher)
            ->postJson("/api/quizzes/{$this->quiz->id}/questions", $this->payload([
                'options' => [['label' => 'Seule', 'is_correct' => true]],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('options');
    }

    public function test_a_question_needs_at_least_one_correct_answer(): void
    {
        $this->actingAs($this->teacher)
            ->postJson("/api/quizzes/{$this->quiz->id}/questions", $this->payload([
                'options' => [
                    ['label' => 'A', 'is_correct' => false],
                    ['label' => 'B', 'is_correct' => false],
                ],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('options');
    }

    public function test_a_single_choice_question_needs_exactly_one_correct_answer(): void
    {
        $this->actingAs($this->teacher)
            ->postJson("/api/quizzes/{$this->quiz->id}/questions", $this->payload([
                'type' => 'single',
                'options' => [
                    ['label' => 'A', 'is_correct' => true],
                    ['label' => 'B', 'is_correct' => true],
                ],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('options');
    }

    public function test_a_true_false_question_needs_exactly_one_correct_answer(): void
    {
        $this->actingAs($this->teacher)
            ->postJson("/api/quizzes/{$this->quiz->id}/questions", $this->payload([
                'type' => 'true_false',
                'options' => [
                    ['label' => 'Vrai', 'is_correct' => false],
                    ['label' => 'Faux', 'is_correct' => false],
                ],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('options');
    }

    public function test_a_statement_is_required(): void
    {
        $this->actingAs($this->teacher)
            ->postJson("/api/quizzes/{$this->quiz->id}/questions", $this->payload(['statement' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('statement');
    }

    public function test_an_unknown_question_type_is_refused(): void
    {
        $this->actingAs($this->teacher)
            ->postJson("/api/quizzes/{$this->quiz->id}/questions", $this->payload(['type' => 'geographique']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');
    }

    public function test_a_rejected_question_is_not_persisted(): void
    {
        $this->actingAs($this->teacher)
            ->postJson("/api/quizzes/{$this->quiz->id}/questions", $this->payload([
                'options' => [['label' => 'Seule', 'is_correct' => true]],
            ]))
            ->assertStatus(422);

        $this->assertSame(0, $this->quiz->questions()->count());
    }

    // -- Frontières d'autorisation (RG-04, RG-11) ---------------------------

    public function test_another_teacher_cannot_manage_the_questions(): void
    {
        $this->seedQuestions(1);
        $id = $this->ids()[0];
        $intruder = $this->teacher();

        $this->actingAs($intruder)
            ->postJson("/api/quizzes/{$this->quiz->id}/questions", $this->payload())
            ->assertStatus(403);

        $this->actingAs($intruder)
            ->patchJson("/api/questions/{$id}", $this->payload())
            ->assertStatus(403);

        $this->actingAs($intruder)
            ->postJson("/api/quizzes/{$this->quiz->id}/questions/reorder", ['question_ids' => [$id]])
            ->assertStatus(403);

        $this->actingAs($intruder)
            ->deleteJson("/api/questions/{$id}")
            ->assertStatus(403);

        $this->assertSame(1, $this->quiz->questions()->count());
    }

    public function test_a_student_cannot_manage_the_questions(): void
    {
        $this->seedQuestions(1);
        $id = $this->ids()[0];

        $this->actingAs($this->student)
            ->postJson("/api/quizzes/{$this->quiz->id}/questions", $this->payload())
            ->assertStatus(403);

        $this->actingAs($this->student)
            ->deleteJson("/api/questions/{$id}")
            ->assertStatus(403);

        $this->assertSame(1, $this->quiz->questions()->count());
    }

    public function test_a_published_quiz_is_immutable(): void
    {
        // Modifier l'énoncé d'un quiz publié fausserait les tentatives en
        // cours et les résultats déjà calculés.
        $this->quiz->update(['status' => 'published', 'published_at' => now()]);
        $this->seedQuestions(1);
        $id = $this->ids()[0];

        $this->actingAs($this->teacher)
            ->postJson("/api/quizzes/{$this->quiz->id}/questions", $this->payload())
            ->assertStatus(403);

        $this->actingAs($this->teacher)
            ->patchJson("/api/questions/{$id}", $this->payload(['statement' => 'Piraté']))
            ->assertStatus(403);

        $this->actingAs($this->teacher)
            ->deleteJson("/api/questions/{$id}")
            ->assertStatus(403);

        $this->actingAs($this->teacher)
            ->postJson("/api/quizzes/{$this->quiz->id}/questions/reorder", ['question_ids' => [$id]])
            ->assertStatus(403);

        $this->assertDatabaseHas('questions', ['id' => $id, 'statement' => 'Question 1']);
    }

    public function test_the_draft_check_follows_the_question_own_quiz(): void
    {
        // Même classe, même enseignant : seul le statut du quiz **de la
        // question** doit décider. L'URL ne porte pas le quiz pour
        // PATCH/DELETE, l'autorisation ne peut donc pas reposer dessus.
        $published = Quiz::create([
            'classroom_id' => $this->classroom->id,
            'created_by' => $this->teacher->id,
            'title' => 'Quiz publie',
            'status' => 'published',
            'source' => 'manual',
            'reviewed' => true,
            'published_at' => now(),
        ]);

        $locked = $published->questions()->create([
            'statement' => 'Question publiee',
            'type' => 'single',
            'position' => 0,
        ]);
        $locked->options()->create(['label' => 'A', 'is_correct' => true]);

        $this->actingAs($this->teacher)
            ->patchJson("/api/questions/{$locked->id}", $this->payload())
            ->assertStatus(403);

        $this->assertDatabaseHas('questions', [
            'id' => $locked->id,
            'statement' => 'Question publiee',
        ]);
    }
}