<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * Vue enseignant : include `is_correct` (édition, rélecture, publication).
 */
class QuestionResource extends \Illuminate\Http\Resources\Json\JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'statement' => $this->statement,
            'type' => $this->type->value,
            'explanation' => $this->explanation,
            'position' => $this->position,
            'options' => $this->whenLoaded('options', fn () => $this->options->map(fn ($option) => [
                'id' => $option->id,
                'label' => $option->label,
                'is_correct' => (bool) $option->is_correct,
            ])->values()),
        ];
    }
}
