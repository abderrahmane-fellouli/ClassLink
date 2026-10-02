<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * F-QUI-06 — « Revue de la correction avec explications ».
 *
 * Appelée UNIQUEMENT après soumission (GET /attempts/{id}), une fois la
 * correction faite côté serveur (RG-13). C'est le seul point où les bonnes
 * réponses quittent le serveur.
 */
class AttemptResultResource extends \Illuminate\Http\Resources\Json\JsonResource
{
    public function toArray(Request $request): array
    {
        $attempt = $this->resource;
        $answers = $attempt->answers->keyBy('question_id');

        $quiz = $attempt->quiz->load('questions.options');

        return [
            'id' => $attempt->id,
            'quiz_id' => $attempt->quiz_id,
            'quiz_title' => $quiz->title,
            'attempt_no' => $attempt->attempt_no,
            'score' => (float) $attempt->score,
            'max_score' => (float) $attempt->max_score,
            'percentage' => $attempt->percentage(),
            'started_at' => $attempt->started_at?->toIso8601String(),
            'submitted_at' => $attempt->submitted_at?->toIso8601String(),

            // RG-12 : soumission automatique après écoulement du temps.
            'expired' => (bool) $attempt->expired,

            'show_answers' => (bool) $quiz->show_answers,

            'answers' => $quiz->questions->map(function ($question) use ($answers, $quiz) {
                /** @var \App\Models\AttemptAnswer|null $answer */
                $answer = $answers->get($question->id);

                return [
                    'question_id' => $question->id,
                    'statement' => $question->statement,
                    'type' => $question->type->value,
                    'is_correct' => (bool) ($answer?->is_correct ?? false),
                    'awarded_score' => (float) ($answer?->awarded_score ?? 0),
                    'selected_option_ids' => array_values($answer?->selected_option_ids ?? []),

                    // F-QUI-06 : explication et bonnes réponses seulement
                    // si l'enseignant a autorisé l'affichage des corrections.
                    'explanation' => $quiz->show_answers ? $question->explanation : null,
                    'options' => $question->options->map(fn ($option) => [
                        'id' => $option->id,
                        'label' => $option->label,
                        'is_correct' => $quiz->show_answers ? (bool) $option->is_correct : null,
                    ])->values(),
                ];
            })->values(),
        ];
    }
}
