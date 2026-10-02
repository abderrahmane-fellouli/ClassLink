<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * F-CLS-01, F-CLS-05 — représentation d'une classe.
 * Le code d'invitation n'est renvoyé qu'à son propriétaire (F-CLS-03).
 */
class ClassroomResource extends \Illuminate\Http\Resources\Json\JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $isManager = $viewer && $this->resource->isOwnedBy($viewer);

        return array_filter([
            'id' => $this->id,
            'name' => $this->name,
            'subject' => $this->subject,
            'group_label' => $this->group_label,
            'school_year' => $this->school_year,
            'status' => $this->status,
            'archived_at' => $this->archived_at?->toIso8601String(),
            'is_read_only' => $this->isReadOnly(),

            // Visible par le propriétaire : F-CLS-02 / F-CLS-03.
            'join_code' => $isManager ? $this->join_code : null,
            'join_enabled' => $isManager ? (bool) $this->join_enabled : null,

            'teacher' => $this->whenLoaded('teacher', fn () => [
                'id' => $this->teacher->id,
                'display_name' => $this->teacher->display_name,
            ]),

            'membership' => $this->when(
                isset($this->membership_status),
                fn () => ['status' => $this->membership_status]
            ),

            'members_count' => $this->whenCounted('memberships'),

            'created_at' => $this->created_at?->toIso8601String(),
        ], static fn ($value) => $value !== null);
    }
}
