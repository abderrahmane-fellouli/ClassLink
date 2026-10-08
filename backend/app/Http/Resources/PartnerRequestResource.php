<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/** F-PAR-03 — demande de contact partenaire. Jamais d'email. */
class PartnerRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'classroom_id' => $this->classroom_id,
            'is_official' => (bool) $this->classroom?->is_official,
            'can_respond' => $this->status === 'pending' && $request->user()
                && Gate::forUser($request->user())->allows('respond', $this->resource),
            'classroom_name' => $this->classroom?->name,
            'direction' => $this->from_user_id === $request->user()?->id ? 'outgoing' : 'incoming',
            'counterpart' => $this->from_user_id === $request->user()?->id
                ? ['id' => $this->to_user_id, 'display_name' => $this->toUser?->display_name]
                : ['id' => $this->from_user_id, 'display_name' => $this->fromUser?->display_name],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
