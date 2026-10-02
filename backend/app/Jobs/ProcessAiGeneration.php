<?php

namespace App\Jobs;

use App\Contracts\PdfTextExtractor;
use App\Enums\AiJobStatus;
use App\Enums\AiTarget;
use App\Enums\QuestionType;
use App\Exceptions\AiUnavailableException;
use App\Exceptions\PdfExtractionException;
use App\Models\AiJob;
use App\Models\FlashcardDeck;
use App\Models\Quiz;
use App\Services\AiService;
use App\Services\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * §15.3 « Exécution : en arrière-plan, avec suivi de l'état (queued,
 * processing, done, failed) ».
 *
 * §17.11 : « En version 1.0, la génération IA s'exécute avec
 * dispatchAfterResponse() pour éviter un processus séparé. »
 *
 * Règle non négociable — F-IA-03 / RG-11 : le contenu produit reste un
 * BROUILLON (`status = draft`, `reviewed = false`). Aucun chemin de ce
 * service ne publie quoi que ce soit.
 */
class ProcessAiGeneration implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $aiJobId) {}

    public function handle(AiService $ai, NotificationService $notifications, PdfTextExtractor $pdf): void
    {
        $job = AiJob::with('teacher', 'classroom')->find($this->aiJobId);

        if (! $job) {
            return;
        }

        $job->update(['status' => AiJobStatus::Processing, 'started_at' => now()]);

        try {
            $text = $this->extractText($job, $pdf);

            $result = $ai->generate($text, $job->file_hash, $job->target->value);

            DB::transaction(function () use ($job, $result) {
                if ($job->target === AiTarget::Flashcard) {
                    $this->createFlashcardDeck($job, $result['data']);
                } else {
                    $this->createQuizDraft($job, $result['data']);
                }

                $job->update([
                    'status' => AiJobStatus::Done,
                    'provider' => $result['provider'],
                    'finished_at' => now(),
                ]);
            });

            $notifications->notify($job->teacher, NotificationService::AI_JOB_FINISHED, [
                'job_id' => $job->id,
                'status' => AiJobStatus::Done->value,
                'provider' => $result['provider'],
                'cached' => $result['cached'],
                'message' => 'Brouillon généré. Il doit être relu avant publication.',
            ]);
        } catch (PdfExtractionException $e) {
            // §15.3 / F-IA-06 : le cours n'a pas pu etre lu. Message clair,
            // la creation manuelle reste possible.
            $this->fail($job, $e->getMessage());

            $notifications->notify($job->teacher, NotificationService::AI_JOB_FINISHED, [
                'job_id' => $job->id,
                'status' => AiJobStatus::Failed->value,
                'message' => $e->getMessage(),
                'manual_fallback' => true,
            ]);
        } catch (AiUnavailableException $e) {
            // F-IA-06 / T-19 : message clair, la création manuelle reste
            // possible. On n'interrompt pas le reste de l'application.
            $this->fail($job, $e->getMessage());

            $notifications->notify($job->teacher, NotificationService::AI_JOB_FINISHED, [
                'job_id' => $job->id,
                'status' => AiJobStatus::Failed->value,
                'message' => $e->getMessage(),
                'manual_fallback' => true,
            ]);
        } catch (\Throwable $e) {
            Log::error('Génération IA en échec', ['job_id' => $job->id, 'error' => $e->getMessage()]);

            $this->fail($job, 'Erreur inattendue lors de la génération.');

            $notifications->notify($job->teacher, NotificationService::AI_JOB_FINISHED, [
                'job_id' => $job->id,
                'status' => AiJobStatus::Failed->value,
                'message' => 'Erreur inattendue. Vous pouvez créer le contenu manuellement.',
                'manual_fallback' => true,
            ]);
        }
    }

    /**
     * §15.3 « Confidentialité : seul le texte du cours est envoyé, jamais de
     * données sur les utilisateurs. »
     *
     * Le contrat d'extraction est injecte : le moteur PDF est ainsi
     * remplaçable en test et l'absence de la bibliotheque produit un message
     * comprehensible plutot qu'une erreur fatale.
     */
    private function extractText(AiJob $job, PdfTextExtractor $pdf): string
    {
        if (! $job->file_path) {
            throw new PdfExtractionException(__('api.ai.pdf_not_found'));
        }

        $disk = Storage::disk((string) config('filesystems.default', 'local'));

        if (! $disk->exists($job->file_path)) {
            throw new PdfExtractionException(__('api.ai.pdf_not_found'));
        }

        $extracted = $pdf->extract($disk->path($job->file_path));

        // §15.3 : nombre de pages maximum, contrôlé avant tout appel réseau.
        $maxPages = (int) config('classlink.ai.max_pages', 30);
        $pageCount = (int) ($extracted['page_count'] ?? 0);

        if ($pageCount > $maxPages) {
            throw new PdfExtractionException(
                __('api.ai.too_many_pages', ['pages' => $pageCount, 'max' => $maxPages])
            );
        }

        return $extracted['text'];
    }

    /**
     * F-IA-03 : `status = draft` et `reviewed = false`. L'endpoint
     * POST /quizzes/{id}/publish refuse de publier tant que reviewed vaut
     * false (RG-11).
     *
     * @param  array<string, mixed>  $data
     */
    private function createQuizDraft(AiJob $job, array $data): Quiz
    {
        $quiz = Quiz::create([
            'classroom_id' => $job->classroom_id,
            'created_by' => $job->teacher_id,
            'title' => $data['title'] ?? 'Quiz généré par IA',
            'status' => 'draft',
            'source' => 'ai',
            'reviewed' => false, // relecture obligatoire
            'time_limit_min' => null,
            'max_attempts' => 1,
            'shuffle' => false,
            'show_answers' => true,
        ]);

        foreach (array_slice($data['questions'] ?? [], 0, (int) config('classlink.ai.max_questions', 30)) as $i => $payload) {
            $question = $quiz->questions()->create([
                'statement' => $payload['statement'],
                'type' => QuestionType::tryFrom($payload['type'] ?? 'single') ?? QuestionType::Single,
                'explanation' => $payload['explanation'] ?? null,
                'position' => $i,
            ]);

            foreach ($payload['options'] as $option) {
                $question->options()->create([
                    'label' => $option['label'],
                    'is_correct' => (bool) $option['is_correct'],
                ]);
            }
        }

        $job->update(['quiz_id' => $quiz->id]);

        return $quiz;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createFlashcardDeck(AiJob $job, array $data): FlashcardDeck
    {
        $deck = FlashcardDeck::create([
            'classroom_id' => $job->classroom_id,
            'title' => $data['title'] ?? 'Flashcards générées par IA',
            'source' => 'ai',
            'status' => 'draft',
            'reviewed' => false,
        ]);

        foreach (array_slice($data['cards'] ?? [], 0, (int) config('classlink.ai.max_questions', 30)) as $i => $card) {
            $deck->cards()->create([
                'front' => $card['front'],
                'back' => $card['back'],
                'position' => $i,
            ]);
        }

        $job->update(['deck_id' => $deck->id]);

        return $deck;
    }

    private function fail(AiJob $job, string $message): void
    {
        $job->update([
            'status' => AiJobStatus::Failed,
            'error' => Str::limit($message, 1000, ''),
            'finished_at' => now(),
        ]);
    }
}
