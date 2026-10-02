<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/** F-REQ-06 : suivi du statut des demandes par l'étudiant. */
class MembershipResource extends \Illuminate\Http\Resources\Json\JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'classroom_id' => $this->classroom_id,
            'status' => $this->status,
            'requested_at' => $this->requested_at?->toIso8601String(),
            'decided_at' => $this->decided_at?->toIso8601String(),

            'classroom' => $this->whenLoaded('classroom', fn () => [
                'id' => $this->classroom->id,
                'name' => $this->classroom->name,
                'subject' => $this->classroom->subject,
                'group_label' => $this->classroom->group_label,
                'teacher_name' => $this->classroom->teacher?->display_name,
            ]),
        ];
    }
}
