<?php

namespace App\Http\Requests;

use App\Enums\QuestionType;
use App\Services\JoinCodeService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * F-QUI-01 — création manuelle d'un quiz.
 *
 * Règle applicative §11 : « au moins 2 options par question ».
 */
class StoreQuizRequest extends FormRequest
{
    public function authorize(): bool
    {
        $classroom = $this->route('classroom');

        return $classroom && $this->user()->can('modify', $classroom);
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'time_limit_min' => ['nullable', 'integer', 'min:1', 'max:600'],
            'max_attempts' => ['nullable', 'integer', 'min:1', 'max:20'],
            'shuffle' => ['nullable', 'boolean'],
            'show_answers' => ['nullable', 'boolean'],

            'questions' => ['required', 'array', 'min:1', 'max:100'],
            'questions.*.statement' => ['required', 'string', 'max:5000'],
            'questions.*.type' => ['required', 'string', 'in:'.implode(',', QuestionType::values())],
            'questions.*.explanation' => ['nullable', 'string', 'max:5000'],
            'questions.*.options' => ['required', 'array', 'min:2', 'max:10'],
            'questions.*.options.*.label' => ['required', 'string', 'max:500'],
            'questions.*.options.*.is_correct' => ['required', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            foreach ((array) $this->input('questions', []) as $i => $question) {
                $correct = collect((array) ($question['options'] ?? []))
                    ->filter(fn ($o) => (bool) ($o['is_correct'] ?? false))
                    ->count();

                // Choix unique et vrai/faux : exactement une bonne réponse.
                if (in_array($question['type'] ?? '', [QuestionType::Single->value, QuestionType::TrueFalse->value], true)
                    && $correct !== 1) {
                    $validator->errors()->add(
                        "questions.$i.options",
                        'Une question à choix unique doit avoir exactement une bonne réponse.'
                    );
                }

                if ($correct < 1) {
                    $validator->errors()->add(
                        "questions.$i.options",
                        'Au moins une bonne réponse est requise.'
                    );
                }
            }
        });
    }

    public function attributes(): array
    {
        return [
            'time_limit_min' => 'durée',
            'max_attempts' => 'nombre de tentatives',
            'questions' => 'questions',
            'questions.*.statement' => 'énoncé',
            'questions.*.options' => 'options',
        ];
    }
}
