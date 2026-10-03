<?php

namespace App\Http\Controllers;

use App\Enums\AiTarget;
use App\Contracts\PdfTextExtractor;
use App\Exceptions\PdfExtractionException;
use App\Exceptions\BusinessRuleException;
use App\Http\Resources\AiJobResource;
use App\Jobs\ProcessAiGeneration;
use App\Models\AiJob;
use App\Models\Classroom;
use App\Services\MaterialStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * §12.4 — POST /classes/{id}/ai/generate, GET /ai/jobs/{id}.
 *
 * Generation is dispatched to the database queue after commit.
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
        $this->authorize('generate', [AiJob::class, $classroom]);

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

        return DB::transaction(function () use ($request, $classroom, $target, $file) {
        // Serialize quota reservation and repeat uploads for this teacher.
        \App\Models\User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
        $fileHash = hash_file('sha256', $file->getRealPath());
        $existing = AiJob::where('teacher_id', $request->user()->id)
            ->where('classroom_id', $classroom->id)->where('target', $target->value)
            ->where('file_hash', $fileHash)->whereIn('status', ['queued', 'processing', 'done'])
            ->latest()->first();
        if ($existing && ($existing->status->value !== 'done' || $existing->quiz || $existing->deck)) {
            return response()->json((new AiJobResource($existing))->resolve() + ['cached' => true], $existing->isTerminal() ? 200 : 202);
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
        try {
            $extracted = app(PdfTextExtractor::class)->extract($file->getRealPath());
        } catch (PdfExtractionException $e) {
            throw new BusinessRuleException($e->getMessage(), 422, ['manual_fallback' => true]);
        }
        $pages = (int) ($extracted['page_count'] ?? 0);
        $maxPages = (int) config('classlink.ai.max_pages', 30);
        if ($pages < 1 || $pages > $maxPages || trim($extracted['text'] ?? '') === '') {
            throw new BusinessRuleException(__('api.ai.too_many_pages', ['pages' => $pages, 'max' => $maxPages]), 422);
        }
        $stored = $this->storage->storeFile($file, 'ai-inputs');

        $job = AiJob::create([
            'teacher_id' => $request->user()->id,
            'classroom_id' => $classroom->id,
            'target' => $target->value,
            'file_hash' => $fileHash,
            'original_name' => $stored['name'],
            'file_path' => $stored['path'],
            'status' => 'queued',
            'page_count' => $pages,
        ]);

        ProcessAiGeneration::dispatch($job->id, $extracted['text'])
            ->onConnection('database')->afterCommit();

        return response()->json((new AiJobResource($job))->resolve() + ['cached' => false], 202);
        });
    }

    /** F-IA-07 — suivi de l'état de la tâche. */
    public function job(Request $request, AiJob $aiJob): AiJobResource
    {
        $this->authorize('view', $aiJob);

        return new AiJobResource($aiJob);
    }
}
