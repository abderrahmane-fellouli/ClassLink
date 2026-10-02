<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/** F-QUI-08 — deck de flashcards. */
class FlashcardDeckResource extends \Illuminate\Http\Resources\Json\JsonResource
{
    public function toArray(Request $request): array
    {
        // Etat de revision du demandeur, injecte par le controleur.
        $known = (array) ($this->resource->getAttribute('knownByViewer') ?? []);

        return [
            'id' => $this->id,
            'classroom_id' => $this->classroom_id,
            'title' => $this->title,
            'source' => $this->source,
            'status' => $this->status,
            'reviewed' => (bool) $this->reviewed,
            'cards_count' => $this->cards->count(),
            'cards' => $this->whenLoaded('cards', fn () => $this->cards->map(fn ($card) => [
                'id' => $card->id,
                'front' => $card->front,
                'back' => $card->back,
                // null = jamais revise ; true = « su » ; false = « à revoir ».
                'known' => array_key_exists($card->id, $known) ? (bool) $known[$card->id] : null,
            ])->values()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
