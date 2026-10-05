<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/** F-CON-04 — annonce. */
class AnnouncementResource extends \Illuminate\Http\Resources\Json\JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'classroom_id' => $this->classroom_id,
            'classroom' => $this->whenLoaded('classroom', fn () => ['id' => $this->classroom->id, 'name' => $this->classroom->name]),
            'title' => $this->title,
            'body' => $this->body,
            'pinned' => (bool) $this->pinned,
            'author' => $this->whenLoaded('author', fn () => [
                'id' => $this->author->id,
                'display_name' => $this->author->display_name,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
