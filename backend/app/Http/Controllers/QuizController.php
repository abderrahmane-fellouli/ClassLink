<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Http\Requests\StoreQuizRequest;
use App\Http\Requests\SubmitAttemptRequest;
use App\Http\Resources\AttemptQuestionResource;
use App\Http\Resources\AttemptResultResource;
use App\Http\Resources\QuizResource;
use App\Http\Resources\QuizResultsResource;
use App\Http\Resources\StudentQuizResource;
use App\Models\Attempt;
use App\Models\AuditLog;
use App\Models\Classroom;
use App\Models\Quiz;
use App\Services\NotificationService;
use App\Services\QuizGradingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * §12.4 — Quiz.
 *
 * RG-11 : seuls les quiz publiés sont visibles des étudiants.
 * RG-13 : aucune bonne réponse n'est renvoyée avant la soumission.
 */
class QuizController extends Controller
{
    public function __construct(
        private readonly QuizGradingService $grading,
        private readonly NotificationService $notifications,
    ) {}

    /** §12.4 — GET /classes/{id}/quizzes. */
    public function index(Request $request, Classroom $classroom): JsonResponse
    {
        $this->authorize('view', $classroom);

        $isManager = $classroom->isOwnedBy($request->user());

        $quizzes = $classroom->quizzes()
            ->when(! $isManager, fn ($q) => $q->published()) // RG-11
            ->withCount('questions')
            ->withCount('attempts')
            ->orderByDesc('created_at')
            ->get();

        $resource = $isManager ? QuizResource::class : StudentQuizResource::class;

        return response()->json([
            'data' => $resource::collection($quizzes),
        ]);
    }

    /**
     * §12.4 — POST /classes/{id}/quizzes. F-QUI-01 (création manuelle).
     *
     * F-IA-06 : ce chemin ne dépend JAMAIS de l'IA. Si l'IA est
     * indisponible, la création manuelle reste possible.
     */
    public function store(StoreQuizRequest $request, Classroom $classroom): JsonResponse
    {
        $data = $request->validated();

        $quiz = DB::transaction(function () use ($data, $classroom, $request) {
            $quiz = Quiz::create([
                'classroom_id' => $classroom->id,
                'created_by' => $request->user()->id,
                'title' => $data['title'],
                'status' => 'draft', // RG-11 : jamais publié automatiquement
                'source' => 'manual',
                'reviewed' => true,
                'time_limit_min' => $data['time_limit_min'] ?? null,
                'max_attempts' => $data['max_attempts'] ?? 1,
                'shuffle' => (bool) ($data['shuffle'] ?? false),
                'show_answers' => (bool) ($data['show_answers'] ?? true),
            ]);

            foreach ($data['questions'] as $i => $payload) {
                $question = $quiz->questions()->create([
                    'statement' => $payload['statement'],
                    'type' => $payload['type'],
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

            return $quiz;
        });

        return response()->json(new QuizResource($quiz->load('questions.options')), 201);
    }

    /** §12.4 — GET /quizzes/{id}. */
    public function show(Request $request, Quiz $quiz): JsonResponse
    {
        $this->authorize('view', $quiz);

        $isManager = $quiz->classroom->isOwnedBy($request->user());

        if (! $isManager) {
            // RG-13 : vue étudiante, sans is_correct ni explanation.
            return response()->json(new StudentQuizResource($quiz->load('classroom')));
        }

        return response()->json(
            new QuizResource($quiz->load('questions.options', 'classroom'))
        );
    }

    /** §12.4 — PATCH /quizzes/{id}. */
    public function update(Request $request, Quiz $quiz): QuizResource
    {
        $this->authorize('update', $quiz);

        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'time_limit_min' => ['nullable', 'integer', 'min:1', 'max:600'],
            'max_attempts' => ['nullable', 'integer', 'min:1', 'max:20'],
            'shuffle' => ['nullable', 'boolean'],
            'show_answers' => ['nullable', 'boolean'],
        ]);

        $quiz->update($data);

        return new QuizResource($quiz->fresh('questions.options'));
    }

    /** §12.4 — DELETE /quizzes/{id}. */
    public function destroy(Quiz $quiz): Response
    {
        $this->authorize('delete', $quiz);

        $quiz->delete();

        return response()->noContent();
    }

    /**
     * §12.4 — POST /quizzes/{id}/publish. F-QUI-03.
     *
     * RG-11 / F-IA-03 : « Un quiz généré par l'IA ne peut être publié
     * qu'après relecture par l'enseignant. » Un quiz IA jamais relu est
     * refusé avec 409.
     */
    public function publish(Request $request, Quiz $quiz): JsonResponse
    {
        $this->authorize('publish', $quiz);

        if ($quiz->isPublished()) {
            return response()->json(new QuizResource($quiz->load('questions.options')));
        }

        if ($quiz->questions()->count() === 0) {
            throw new BusinessRuleException('Un quiz doit contenir au moins une question.', 422);
        }

        if (! $quiz->canBePublished()) {
            throw new BusinessRuleException(
                'Ce quiz a été généré par l\'IA. Relisez-le et validez la relecture avant de publier.',
                409,
                ['requires_review' => true]
            );
        }

        $quiz->update([
            'status' => 'published',
            'reviewed' => true,
            'published_at' => now(),
        ]);

        AuditLog::record($request->user(), 'quiz.publish', ['quiz_id' => $quiz->id]);

        // Seuls les membres ACCEPTE sont notifiés (RG-06). `memberships`
        // n'a pas de colonne `student` : il faut charger la relation.
        $recipients = $quiz->classroom->members()->get()->pluck('student');

        $this->notifications->notifyMany(
            $recipients,
            NotificationService::QUIZ_PUBLISHED,
            [
                'quiz_id' => $quiz->id,
                'title' => $quiz->title,
                'classroom_id' => $quiz->classroom_id,
                'classroom_name' => $quiz->classroom->name,
            ]
        );

        return response()->json(new QuizResource($quiz->fresh('questions.options')));
    }

    /**
     * §15.2 / F-IA-04 / RG-11 — POST /quizzes/{id}/review.
     *
     * L'enseignant confirme avoir relu le contenu généré par l'IA. C'est
     * l'etape qui rend la publication possible : `publish()` refuse un quiz
     * IA non relu avec 409.
     */
    public function review(Request $request, Quiz $quiz): QuizResource
    {
        $this->authorize('update', $quiz);

        if ($quiz->source !== 'ai') {
            throw new BusinessRuleException(
                'Seul un quiz généré par l\'IA doit être validé manuellement.',
                422
            );
        }

        if ((bool) $quiz->reviewed) {
            return new QuizResource($quiz->fresh('questions.options'));
        }

        $quiz->update(['reviewed' => true]);

        AuditLog::record($request->user(), 'quiz.review', [
            'quiz_id' => $quiz->id,
            'classroom_id' => $quiz->classroom_id,
        ]);

        return new QuizResource($quiz->fresh('questions.options'));
    }

    /**
     * §12.4 — POST /quizzes/{id}/attempts. F-QUI-04.
     * T-16 : refus au-delà du maximum de tentatives.
     */
    public function startAttempt(Request $request, Quiz $quiz): JsonResponse
    {
        $this->authorize('attempt', $quiz);

        $attempt = $this->grading->start($request->user(), $quiz);

        // RG-13 : AttemptQuestionResource ne contient ni is_correct ni
        // explanation. Les bonnes réponses restent sur le serveur.
        return response()->json([
            'attempt_id' => $attempt->id,
            'attempt_no' => $attempt->attempt_no,
            'started_at' => $attempt->started_at->toIso8601String(),
            'time_limit_min' => $quiz->time_limit_min,
            'remaining_seconds' => $attempt->remainingSeconds(),
            'questions' => AttemptQuestionResource::collection(
                $quiz->questions()->with('options')->get()
            ),
        ], 201);
    }

    /**
     * §12.4 — POST /attempts/{id}/submit. RG-12 / RG-13.
     * T-15 : soumission après la fin du temps -> traitée comme expirée.
     */
    public function submitAttempt(SubmitAttemptRequest $request, Attempt $attempt): JsonResponse
    {
        $this->grading->saveAnswers($attempt, $request->validated()['answers']);

        $graded = $this->grading->submit($attempt);

        return response()->json(new AttemptResultResource($graded));
    }

    /**
     * §12.4 — GET /attempts/{id}. F-QUI-06.
     * RG-13 : c'est ici, et seulement ici, que les bonnes réponses sortent.
     */
    public function showAttempt(Request $request, Attempt $attempt): AttemptResultResource
    {
        $this->authorize('view', $attempt);

        // RG-12 : une tentative dont le temps est écoulé est soumise
        // automatiquement avec les réponses déjà enregistrées.
        $attempt = $this->grading->autoSubmitIfExpired($attempt);

        return new AttemptResultResource($attempt->load('answers', 'quiz.questions.options'));
    }
}
