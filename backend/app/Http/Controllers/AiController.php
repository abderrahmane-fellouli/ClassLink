<?php

namespace App\Http\Controllers;

use App\Enums\AiTarget;
use App\Exceptions\BusinessRuleException;
use App\Http\Resources\AiJobResource;
use App\Jobs\ProcessAiGeneration;
use App\Models\AiJob;
use App\Models\Classroom;
use App\Services\MaterialStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * §12.4 — POST /classes/{id}/ai/generate, GET /ai/jobs/{id}.
 *
 * §17.11 : la génération s'exécute avec dispatchAfterResponse() — pas de
 * processus séparé en version 1.0.
 */
class AiController extends Controller
{
    public function __construct(private readonly MaterialStorageService $storage) {}

    /**
     * §15.3 contrôles appliqués avant toute génération :
     *  - rôle autorisé : enseignant propriétaire de la classe (policy) ;
     *  - PDF uniquement ;
     *  - taille et nombre de pages maximum ;
     *  - quota quotidien.
     */
    public function generate(Request $request, Classroom $classroom): JsonResponse
    {
        $this->authorize('generate', $classroom);

        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:pdf', 'max:'.(int) config('classlink.ai.max_kb', 10240)],
            'target' => ['nullable', 'string', 'in:quiz,flashcard'],
        ], [], ['file' => 'PDF du cours']);

        $target = AiTarget::from($data['target'] ?? 'quiz');
        $file = $request->file('file');

        $mimes = (array) config('classlink.ai.accepted_mimes');
        if (! in_array($file->getClientMimeType(), $mimes, true)) {
            throw new BusinessRuleException(__('api.ai.pdf_only'), 422);
        }

        // RG-14 : quota quotidien par enseignant.
        $quota = (int) config('classlink.ai.daily_quota_per_teacher', 10);
        $usedToday = AiJob::where('teacher_id', $request->user()->id)
            ->where('status', '!=', 'failed')
            ->whereDate('created_at', now()->toDateString())
            ->count();

        if ($usedToday >= $quota) {
            throw new BusinessRuleException(
                __('api.ai.quota_reached', ['quota' => $quota]),
                429,
                ['quota' => $quota, 'used' => $usedToday]
            );
        }

        // RG-14 : empreinte du fichier -> un même PDF n'est pas retraité.
        $fileHash = hash_file('sha256', $file->getRealPath());
        $stored = $this->storage->storeFile($file, 'ai-inputs');

        $job = AiJob::create([
            'teacher_id' => $request->user()->id,
            'classroom_id' => $classroom->id,
            'target' => $target->value,
            'file_hash' => $fileHash,
            'original_name' => $stored['name'],
            'file_path' => $stored['path'],
            'status' => 'queued',
        ]);

        // §17.11 : dispatchAfterResponse, pas de worker en v1.0.
        ProcessAiGeneration::dispatch($job->id)->afterResponse();

        return response()->json(new AiJobResource($job), 202);
    }

    /** F-IA-07 — suivi de l'état de la tâche. */
    public function job(Request $request, AiJob $aiJob): AiJobResource
    {
        $this->authorize('view', $aiJob);

        return new AiJobResource($aiJob);
    }
}
