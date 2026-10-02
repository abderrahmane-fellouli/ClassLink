<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * RG-13 — jeu de questions envoyé à l'étudiant pendant une tentative.
 *
 * Ne contient QUE `id` et `label` pour chaque option. `is_correct` et
 * `explanation` n'apparaissent nulle part dans cette classe.
 */
class AttemptQuestionResource extends \Illuminate\Http\Resources\Json\JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'statement' => $this->statement,
            'type' => $this->type->value,
            'position' => $this->position,
            'options' => $this->options->map(fn ($option) => [
                'id' => $option->id,
                'label' => $option->label,
            ])->values(),
        ];
    }
}
