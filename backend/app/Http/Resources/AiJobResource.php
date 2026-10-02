<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/** §15.3 : suivi d'état de la tâche IA (F-IA-07). */
class AiJobResource extends \Illuminate\Http\Resources\Json\JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'classroom_id' => $this->classroom_id,
            'target' => $this->target->value,
            'status' => $this->status->value,
            'provider' => $this->provider,
            'original_name' => $this->original_name,
            'page_count' => $this->page_count,
            'error' => $this->error,
            'quiz_id' => $this->quiz_id,
            'deck_id' => $this->deck_id,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
