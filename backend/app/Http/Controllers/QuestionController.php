<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Http\Requests\QuestionRequest;
use App\Http\Resources\QuestionResource;
use App\Models\Question;
use App\Models\Quiz;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * §12.4 / F-QUI-01 / US-25 — questions d'un quiz.
 *
 * Ces routes n'existent que pour un quiz **en brouillon** (`QuizPolicy::
 * manageQuestions`) : modifier l'énoncé d'un quiz publié changerait
 * l'épreuve pour les étudiants qui répondent encore, et invaliderait les
 * résultats déjà calculés.
 *
 * `position` est renumérotée dans la même transaction que l'écriture : une
 * liste d'index non contigus ou dupliqués produirait un ordre d'affichage
 * ambigu.
 */
class QuestionController extends Controller
{
    /** §12.4 — POST /quizzes/{id}/questions. */
    public function store(QuestionRequest $request, Quiz $quiz): JsonResponse
    {
        $question = DB::transaction(function () use ($request, $quiz) {
            $question = $quiz->questions()->create([
                'statement' => $request->validated('statement'),
                'type' => $request->validated('type'),
                'explanation' => $request->validated('explanation'),
                // Ajoutée en fin de questionnaire.
                'position' => $quiz->questions()->count(),
            ]);

            $this->syncOptions($question, $request->validated('options'));

            return $question;
        });

        $quiz->markReviewed();

        return response()->json(
            new QuestionResource($question->load('options')),
            201
        );
    }

    /** §12.4 — PATCH /questions/{id}. */
    public function update(QuestionRequest $request, Question $question): QuestionResource
    {
        DB::transaction(function () use ($request, $question) {
            $question->update([
                'statement' => $request->validated('statement'),
                'type' => $request->validated('type'),
                'explanation' => $request->validated('explanation'),
            ]);

            /*
             * Les options sont remplacées intégralement plutôt que
             * rapprochées : changer `is_correct` sur une option déjà
             * enregistrée fausserait les `attempt_answers` d'une tentative en
             * cours. Il n'existe pas de sauvegarde partielle (RG-12), donc
             * aucune tentative ne référence ces options.
             */
            $question->options()->delete();
            $this->syncOptions($question, $request->validated('options'));
        });

        $question->quiz->markReviewed();

        return new QuestionResource($question->fresh()->load('options'));
    }

    /** §12.4 — DELETE /questions/{id}. */
    public function destroy(Question $question): Response
    {
        $this->authorize('manageQuestions', $question->quiz);

        DB::transaction(function () use ($question) {
            $question->options()->delete();
            $question->delete();

            $this->renumber($question->quiz_id);
        });

        $question->quiz->markReviewed();

        return response()->noContent();
    }

    /**
     * §12.4 — POST /quizzes/{id}/questions/reorder.
     *
     * `question_ids` doit être une permutation exacte des questions du quiz :
     * une liste partielle ou dupliquée laisserait des questions sans
     * position, donc hors de l'ordre d'affichage.
     */
    public function reorder(Request $request, Quiz $quiz): JsonResponse
    {
        $this->authorize('manageQuestions', $quiz);

        $data = $request->validate([
            'question_ids' => ['required', 'array', 'min:1'],
            'question_ids.*' => ['required', 'integer', 'distinct'],
        ]);

        $expected = $quiz->questions()->pluck('id')->all();

        $provided = $data['question_ids'];

        if (count($provided) !== count($expected)
            || collect($expected)->diff($provided)->isNotEmpty()) {
            throw new BusinessRuleException(
                'La liste doit contenir exactement les questions du quiz.',
                422
            );
        }

        DB::transaction(function () use ($provided) {
            foreach (array_values($provided) as $position => $id) {
                Question::whereKey($id)->update(['position' => $position]);
            }
        });

        $quiz->markReviewed();

        return response()->json([
            'data' => QuestionResource::collection(
                $quiz->questions()->with('options')->orderBy('position')->get()
            ),
        ]);
    }

    /**
     * Renumérote `position` en 0..n-1 dans l'ordre courant.
     *
     * Utilisée après une suppression, où les positions suivantes seraient
     * décalées et laisseraient un trou dans la séquence.
     */
    private function renumber(int $quizId): void
    {
        Question::where('quiz_id', $quizId)
            ->orderBy('position')
            ->orderBy('id')
            ->get(['id'])
            ->each(fn (Question $question, int $index) => $question->update(['position' => $index]));
    }

    /**
     * @param  array<int, array{label: string, is_correct: bool}>  $options
     */
    private function syncOptions(Question $question, array $options): void
    {
        foreach ($options as $option) {
            $question->options()->create([
                'label' => $option['label'],
                'is_correct' => (bool) $option['is_correct'],
            ]);
        }
    }
}