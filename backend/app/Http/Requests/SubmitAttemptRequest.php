<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** §12.4 : POST /attempts/{id}/submit. */
class SubmitAttemptRequest extends FormRequest
{
    public function authorize(): bool
    {
        $attempt = $this->route('attempt');

        return $attempt && $this->user()->can('submit', $attempt);
    }

    public function rules(): array
    {
        return [
            'answers' => ['present', 'array', 'max:100'],
            'answers.*.question_id' => ['required', 'integer', 'distinct'],
            'answers.*.option_ids' => ['present', 'array', 'max:10'],
            'answers.*.option_ids.*' => ['integer'],
        ];
    }

    public function attributes(): array
    {
        return [
            'answers' => 'réponses',
            'answers.*.question_id' => 'question',
            'answers.*.option_ids' => 'options sélectionnées',
        ];
    }
}
