<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/** F-CON-01, F-CON-02 — une ressource de classe. */
class MaterialResource extends \Illuminate\Http\Resources\Json\JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'classroom_id' => $this->classroom_id,
            'title' => $this->title,
            'offering_id' => $this->offering_id,
            'chapter' => $this->chapter,
            'category' => $this->category,
            'type' => $this->type->value,

            /*
             * RG-12 : le disque est prive. Aucune URL signee n'est posee
             * dans une liste (elles sont courte duree et le client en
             * demanderait N a la fois) : le client appelle
             * GET /materials/{id}/download, qui verifie l'autorisation puis
             * renvoie une URL temporaire.
             */
            'url' => $this->isLink() ? $this->path_or_url : null,
            'has_file' => ! $this->isLink(),
            'download_endpoint' => $this->isLink()
                ? null
                : url("/api/materials/{$this->id}/download"),

            'file_name' => $this->file_name,
            'mime_type' => $this->mime_type,
            'file_size' => $this->file_size,

            'uploaded_by' => $this->whenLoaded('uploader', fn () => [
                'id' => $this->uploader->id,
                'display_name' => $this->uploader->display_name,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
