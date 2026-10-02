<?php

namespace App\Services;

use App\Enums\QuestionType;
use App\Exceptions\AiUnavailableException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * §15.4 — « Format de sortie : JSON strict validé par Laravel, une nouvelle
 * tentative si invalide ».
 *
 * Le schéma attendu est celui de la spécification :
 *   { "title": "...", "questions": [ { "statement", "type", "explanation",
 *     "options": [ { "label", "is_correct" } ] } ] }
 */
class AiPayloadValidator
{
    /**
     * @return array{ok: true, data: array<string, mixed>}|array{ok: false, errors: array<int, string>}
     */
    public function validate(mixed $payload, string $type = 'quiz'): array
    {
        if (! is_array($payload)) {
            return ['ok' => false, 'errors' => ['La réponse du fournisseur n\'est pas un objet JSON.']];
        }

        $schema = $type === 'flashcard'
            ? $this->flashcardSchema()
            : $this->quizSchema();

        $validator = Validator::make($payload, $schema);

        if ($validator->fails()) {
            return [
                'ok' => false,
                'errors' => $validator->errors()->all(),
            ];
        }

        return ['ok' => true, 'data' => $validator->validated()];
    }

    /**
     * @return array<string, mixed>
     */
    private function quizSchema(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'questions' => ['required', 'array', 'min:1', 'max:'.(int) config('classlink.ai.max_questions', 30)],
            'questions.*' => ['required', 'array'],
            'questions.*.statement' => ['required', 'string'],
            // On tolère les noms de types courants et on les ramène à
            // l'énumération du modèle plutôt que de rejeter la réponse.
            'questions.*.type' => ['required', 'string', 'in:'.implode(',', QuestionType::values())],
            'questions.*.explanation' => ['nullable', 'string'],
            'questions.*.options' => ['required', 'array', 'min:2'],
            'questions.*.options.*' => ['required', 'array'],
            'questions.*.options.*.label' => ['required', 'string', 'max:500'],
            'questions.*.options.*.is_correct' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function flashcardSchema(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'cards' => ['required', 'array', 'min:1', 'max:'.(int) config('classlink.ai.max_questions', 30)],
            'cards.*' => ['required', 'array'],
            'cards.*.front' => ['required', 'string'],
            'cards.*.back' => ['required', 'string'],
        ];
    }

    /**
     * Règle applicative §11 : « au moins 2 options par question ». La
     * validation Laravel couvre déjà `min:2` ; on vérifie en plus qu'il
     * existe bien une bonne réponse par question, sinon la correction serait
     * impossible.
     *
     * @param  array<string, mixed>  $data
     */
    public function hasAnswerableQuestions(array $data): bool
    {
        if (! isset($data['questions']) || ! is_array($data['questions'])) {
            return true;
        }

        foreach ($data['questions'] as $question) {
            $correct = 0;
            foreach ((array) ($question['options'] ?? []) as $option) {
                if (! empty($option['is_correct'])) {
                    $correct++;
                }
            }

            if ($correct < 1) {
                Log::warning('Payload IA : question sans bonne réponse');
                return false;
            }
        }

        return true;
    }
}
