<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * RG-13 / T-17 — « Les bonnes réponses ne sont jamais envoyées au navigateur
 * avant la soumission. »
 *
 * Cette ressource est la SEULE utilisée pour alimenter le passage d'un quiz
 * côté étudiant. Elle ne contient ni `is_correct` ni `explanation`, et ne
 *peut structurellement pas les contenir.
 *
 * La correction n'est renvoyée qu'après soumission, par AttemptResultResource.
 */
class StudentQuizResource extends \Illuminate\Http\Resources\Json\JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'classroom_id' => $this->classroom_id,
            'title' => $this->title,
            'status' => $this->status,
            'time_limit_min' => $this->time_limit_min,
            'max_attempts' => $this->max_attempts,
            'shuffle' => (bool) $this->shuffle,
            'show_answers' => (bool) $this->show_answers,
            'published_at' => $this->published_at?->toIso8601String(),

            'classroom' => $this->whenLoaded('classroom', fn () => [
                'id' => $this->classroom->id,
                'name' => $this->classroom->name,
            ]),

            'attempts_used' => $this->whenCounted('attempts'),
        ];
    }
}
