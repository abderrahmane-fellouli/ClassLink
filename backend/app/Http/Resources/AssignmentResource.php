<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/** F-DEV-01, F-DEV-03 — devoir. */
class AssignmentResource extends \Illuminate\Http\Resources\Json\JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'classroom_id' => $this->classroom_id,
            'title' => $this->title,
            'instructions' => $this->instructions,
            'due_at' => $this->due_at?->toIso8601String(),
            'is_overdue' => $this->hasDeadline() && now()->gt($this->due_at),
            'submissions_count' => $this->whenCounted('submissions'),
            // Rendu propre a l'etudiant connecte : lui seul, jamais celui des autres.
            'my_submission' => $this->when(
                $this->getAttribute('mySubmissionFor') !== null,
                fn () => new SubmissionResource($this->getAttribute('mySubmissionFor')),
            ),
            'author' => $this->whenLoaded('author', fn () => [
                'id' => $this->author->id,
                'display_name' => $this->author->display_name,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
