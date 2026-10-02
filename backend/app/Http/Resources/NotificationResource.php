<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/** F-NOT-01 — notification applicative. */
class NotificationResource extends \Illuminate\Http\Resources\Json\JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'payload' => $this->payload,
            'read_at' => $this->read_at?->toIso8601String(),
            'is_read' => $this->isRead(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
