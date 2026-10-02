<?php

namespace App\Http\Controllers;

use App\Http\Resources\AnnouncementResource;
use App\Http\Resources\MaterialResource;
use App\Models\Announcement;
use App\Models\AuditLog;
use App\Models\Classroom;
use App\Models\Material;
use App\Services\MaterialStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * §12.3 — Contenus (ressources et annonces).
 */
class ContentController extends Controller
{
    public function __construct(private readonly MaterialStorageService $storage) {}

    // -------------------------------------------------------------------------
    // Ressources — F-CON-01 à F-CON-03
    // -------------------------------------------------------------------------

    /** §12.3 — GET /classes/{id}/materials. */
    public function materials(Request $request, Classroom $classroom): JsonResponse
    {
        $this->authorize('view', $classroom);

        $materials = $classroom->materials()
            ->with('uploader')
            ->orderBy('chapter')
            ->orderBy('title')
            ->get();

        return response()->json(['data' => MaterialResource::collection($materials)]);
    }

    /**
     * §12.3 — POST /classes/{id}/materials.
     * F-CON-01 : ressource fichier ou lien, organisée par chapitre.
     * T-21 : un .exe est refusé avec 422 (voir MaterialStorageService).
     */
    public function storeMaterial(Request $request, Classroom $classroom): JsonResponse
    {
        $this->authorize('view', $classroom);

        // RG-10 : une classe archivée est en lecture seule.
        $this->authorize('modify', $classroom);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'chapter' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', 'string', 'in:file,link'],
            'url' => ['nullable', 'required_if:type,link', 'url', 'max:2048'],
            'file' => ['nullable', 'required_if:type,file', 'file'],
        ], [], ['url' => 'lien', 'file' => 'fichier']);

        $type = $data['type'] ?? ($request->hasFile('file') ? 'file' : 'link');

        if ($type === 'link') {
            $material = Material::create([
                'classroom_id' => $classroom->id,
                'title' => $data['title'],
                'chapter' => $data['chapter'] ?? null,
                'type' => 'link',
                'path_or_url' => $data['url'],
                'uploaded_by' => $request->user()->id,
            ]);
        } else {
            $stored = $this->storage->storeFile($request->file('file'));

            $material = Material::create([
                'classroom_id' => $classroom->id,
                'title' => $data['title'],
                'chapter' => $data['chapter'] ?? null,
                'type' => 'file',
                'path_or_url' => $stored['path'],
                'file_name' => $stored['name'],
                'mime_type' => $stored['mime'],
                'file_size' => $stored['size'],
                'uploaded_by' => $request->user()->id,
            ]);
        }

        return response()->json(new MaterialResource($material->load('uploader')), 201);
    }

    /** §12.3 — PATCH /materials/{id}. F-CON-03. */
    public function updateMaterial(Request $request, Material $material): MaterialResource
    {
        $this->authorize('update', $material);

        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'chapter' => ['nullable', 'string', 'max:255'],
        ]);

        $material->update($data);

        return new MaterialResource($material->fresh());
    }

    /** §12.3 — DELETE /materials/{id}. F-CON-03. */
    public function destroyMaterial(Material $material): Response
    {
        $this->authorize('delete', $material);

        $this->storage->delete($material->path_or_url);
        $material->delete();

        return response()->noContent();
    }

    /**
     * F-CON-02 — URL temporaire signée, émise seulement après contrôle
     * d'accès. Le fichier n'est jamais public.
     */
    public function download(Request $request, Material $material)
    {
        $this->authorize('view', $material);

        if ($material->isLink()) {
            return response()->json(['url' => $material->path_or_url]);
        }

        AuditLog::record($request->user(), 'material.download', [
            'material_id' => $material->id,
        ]);

        /*
         * RG-12 : le disque est privé. En production (S3) on renvoie une URL
         * signée de courte durée ; en local, où le disque ne signe pas, on
         * sert le fichier directement — après le même contrôle d'accès.
         */
        if ($this->storage->supportsSignedUrls()) {
            return response()->json([
                'url' => $this->storage->temporaryUrl($material->path_or_url, 10, $material->mime_type),
                'file_name' => $material->file_name,
            ]);
        }

        return $this->storage->download($material->path_or_url, $material->file_name, $material->mime_type);
    }

    // -------------------------------------------------------------------------
    // Annonces — F-CON-04
    // -------------------------------------------------------------------------

    /** §12.3 — GET /classes/{id}/announcements. */
    public function announcements(Request $request, Classroom $classroom): JsonResponse
    {
        $this->authorize('view', $classroom);

        $announcements = $classroom->announcements()
            ->with('author')
            ->orderByDesc('pinned')
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => AnnouncementResource::collection($announcements)]);
    }

    /** §12.3 — POST /classes/{id}/announcements. F-CON-04. */
    public function storeAnnouncement(Request $request, Classroom $classroom): JsonResponse
    {
        $this->authorize('view', $classroom);
        $this->authorize('modify', $classroom);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:10000'],
            'pinned' => ['nullable', 'boolean'],
        ]);

        $announcement = Announcement::create($data + [
            'classroom_id' => $classroom->id,
            'author_id' => $request->user()->id,
            'pinned' => (bool) ($data['pinned'] ?? false),
        ]);

        return response()->json(new AnnouncementResource($announcement->load('author')), 201);
    }

    /** §12.3 — PATCH /announcements/{id}. */
    public function updateAnnouncement(Request $request, Announcement $announcement): AnnouncementResource
    {
        $this->authorize('update', $announcement);

        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'body' => ['sometimes', 'required', 'string', 'max:10000'],
            'pinned' => ['sometimes', 'boolean'],
        ]);

        $announcement->update($data);

        return new AnnouncementResource($announcement->fresh('author'));
    }

    /** §12.3 — DELETE /announcements/{id}. */
    public function destroyAnnouncement(Announcement $announcement): Response
    {
        $this->authorize('delete', $announcement);

        $announcement->delete();

        return response()->noContent();
    }
}
