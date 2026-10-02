<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * F-QUI-01 / F-QUI-02 — vue enseignant / propriétaire.
 *
 * Contient `is_correct` : réservé au propriétaire de la classe. Ne JAMAIS
 * être renvoyé à un étudiant (voir StudentQuizResource).
 */
class QuizResource extends \Illuminate\Http\Resources\Json\JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'classroom_id' => $this->classroom_id,
            'title' => $this->title,
            'status' => $this->status,
            'source' => $this->source,
            'reviewed' => (bool) $this->reviewed,
            'can_publish' => $this->canBePublished(),
            'time_limit_min' => $this->time_limit_min,
            'max_attempts' => $this->max_attempts,
            'shuffle' => (bool) $this->shuffle,
            'show_answers' => (bool) $this->show_answers,
            'published_at' => $this->published_at?->toIso8601String(),
            'questions_count' => $this->maxScore(),
            'created_at' => $this->created_at?->toIso8601String(),

            'classroom' => $this->whenLoaded('classroom', fn () => [
                'id' => $this->classroom->id,
                'name' => $this->classroom->name,
                'subject' => $this->classroom->subject,
            ]),

            'questions' => QuestionResource::collection($this->whenLoaded('questions')),
            'attempts' => AttemptSummaryResource::collection($this->whenLoaded('attempts')),
        ];
    }
}
