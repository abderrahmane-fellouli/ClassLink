<?php

namespace App\Http\Requests;

use App\Enums\QuestionType;
use App\Models\Question;
use App\Models\Quiz;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * F-QUI-01 — validation d'une question isolée (US-25).
 *
 * Les règles sont celles déjà appliquées par `StoreQuizRequest` pour une
 * question fournie à la création, afin qu'un quiz assemblé question par
 * question soit strictement équivalent à un quiz créé en un bloc.
 *
 * Règle applicative §11 : « au moins 2 options par question ».
 */
class QuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $quiz = $this->quiz();

        return $quiz !== null && $this->user()->can('manageQuestions', $quiz);
    }

    /**
     * Quiz concerné : variable selon la route.
     *
     * - `POST /quizzes/{quiz}/questions` → route('quiz')
     * - `PATCH|DELETE /questions/{question}` → route('question')->quiz
     */
    private function quiz(): ?Quiz
    {
        if ($quiz = $this->route('quiz')) {
            return $quiz instanceof Quiz ? $quiz : null;
        }

        $question = $this->route('question');

        return $question instanceof Question ? $question->quiz : null;
    }

    public function rules(): array
    {
        return [
            'statement' => ['required', 'string', 'max:5000'],
            'type' => ['required', 'string', 'in:'.implode(',', QuestionType::values())],
            'explanation' => ['nullable', 'string', 'max:5000'],
            'options' => ['required', 'array', 'min:2', 'max:10'],
            'options.*.label' => ['required', 'string', 'max:500'],
            'options.*.is_correct' => ['required', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function (Validator $validator) {
            $question = $this->input();
            $type = $question['type'] ?? null;
            $options = (array) ($question['options'] ?? []);

            $correct = collect($options)
                ->filter(fn ($option) => (bool) ($option['is_correct'] ?? false))
                ->count();

            // Choix unique et vrai/faux : exactement une bonne réponse.
            if (in_array($type, [QuestionType::Single->value, QuestionType::TrueFalse->value], true)
                && $correct !== 1) {
                $validator->errors()->add(
                    'options',
                    'Une question à choix unique doit avoir exactement une bonne réponse.'
                );
            }

            if ($correct < 1) {
                $validator->errors()->add(
                    'options',
                    'Au moins une bonne réponse est requise.'
                );
            }
        });
    }

    public function attributes(): array
    {
        return [
            'statement' => 'énoncé',
            'options' => 'options',
        ];
    }
}