<?php

namespace App\Http\Resources;

use App\Models\Quiz;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeadlineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'kind' => $this->resource instanceof Quiz ? 'quiz' : 'assignment',
            'id' => $this->id,
            'url' => $this->resource instanceof Quiz
                ? '/app/classes/'.$this->classroom_id.'/quizzes/'.$this->id.($this->offering_id ? '?offering='.$this->offering_id : '')
                : '/app/assignments/'.$this->id,
            'title' => $this->title,
            'classroom' => $this->classroom?->name,
            'due_at' => $this->due_at->toIso8601String(),
            'is_overdue' => now()->gt($this->due_at),
        ];
    }
}
