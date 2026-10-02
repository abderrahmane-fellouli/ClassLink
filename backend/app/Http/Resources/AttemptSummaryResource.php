<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/** F-QUI-07 : ligne de résultat côté enseignant. */
class AttemptSummaryResource extends \Illuminate\Http\Resources\Json\JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'attempt_no' => $this->attempt_no,
            'score' => (float) $this->score,
            'max_score' => (float) $this->max_score,
            'percentage' => $this->percentage(),
            'expired' => (bool) $this->expired,
            'started_at' => $this->started_at?->toIso8601String(),
            'submitted_at' => $this->submitted_at?->toIso8601String(),

            'student' => $this->whenLoaded('student', fn () => [
                'id' => $this->student->id,
                'display_name' => $this->student->display_name,
            ]),
        ];
    }
}
