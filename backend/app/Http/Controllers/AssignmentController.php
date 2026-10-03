<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Http\Resources\AssignmentResource;
use App\Http\Resources\SubmissionResource;
use App\Models\Assignment;
use App\Models\AuditLog;
use App\Models\Classroom;
use App\Models\Submission;
use App\Services\MaterialStorageService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * §12.5 — Devoirs (Should : F-DEV-01 à F-DEV-03).
 * RG-16 : un dépôt après la date limite est marqué « en retard ».
 */
class AssignmentController extends Controller
{
    public function __construct(
        private readonly MaterialStorageService $storage,
        private readonly NotificationService $notifications,
    ) {}

    /** §12.5 — GET /classes/{id}/assignments. */
    public function index(Request $request, Classroom $classroom): JsonResponse
    {
        $this->authorize('view', $classroom);

        $assignments = $classroom->assignments()
            ->withCount('submissions')
            ->orderByRaw('due_at is null, due_at asc')
            ->get();

        // F-DEV-02 : l'etudiant voit son propre rendu (et sa note), pas celui des autres.
        // Une seule requete supplementaire, jamais de N+1.
        if ($request->user()->isStudent()) {
            $mine = Submission::whereIn('assignment_id', $assignments->modelKeys())
                ->where('student_id', $request->user()->id)
                ->get()
                ->keyBy('assignment_id');

            $assignments->each(
                fn (Assignment $assignment) => $assignment->setAttribute(
                    'mySubmissionFor',
                    $mine->get($assignment->id),
                ),
            );
        }

        return response()->json(['data' => AssignmentResource::collection($assignments)]);
    }

    /**
     * F-DEV-02 : detail d'un devoir pour l'etudiant connecte.
     * `AssignmentPolicy::view` exige une adhesion acceptee (ou la propriete).
     */
    public function show(Request $request, Assignment $assignment): AssignmentResource
    {
        $this->authorize('view', $assignment);

        $assignment->loadCount('submissions');

        if ($request->user()->isStudent()) {
            $assignment->setAttribute(
                'mySubmissionFor',
                Submission::where('assignment_id', $assignment->id)
                    ->where('student_id', $request->user()->id)
                    ->first(),
            );
        }

        return new AssignmentResource($assignment);
    }

    /** §12.5 — POST /classes/{id}/assignments. F-DEV-01. */
    public function store(Request $request, Classroom $classroom): JsonResponse
    {
        $this->authorize('create', $classroom);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'instructions' => ['nullable', 'string', 'max:10000'],
            'due_at' => ['nullable', 'date'],
        ], [], ['due_at' => 'date limite']);

        $assignment = Assignment::create($data + [
            'classroom_id' => $classroom->id,
            'created_by' => $request->user()->id,
        ]);

        // F-DEV-01 / RG-06 : la creation d'un devoir est une publication —
        // seuls les membres ACCEPTES sont notifies. `members()` charge deja la
        // relation `student`.
        $this->notifications->notifyMany(
            $classroom->members()->get()->pluck('student'),
            NotificationService::ASSIGNMENT_PUBLISHED,
            [
                'assignment_id' => $assignment->id,
                'title' => $assignment->title,
                'classroom_id' => $classroom->id,
                'classroom_name' => $classroom->name,
                'due_at' => $assignment->due_at?->toIso8601String(),
            ]
        );

        return response()->json(new AssignmentResource($assignment), 201);
    }

    /** PATCH /assignments/{id}. */
    public function update(Request $request, Assignment $assignment): AssignmentResource
    {
        $this->authorize('update', $assignment);

        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'instructions' => ['nullable', 'string', 'max:10000'],
            'due_at' => ['nullable', 'date'],
        ]);

        $assignment->update($data);

        return new AssignmentResource($assignment->fresh());
    }

    public function destroy(Assignment $assignment): Response
    {
        $this->authorize('delete', $assignment);

        $assignment->delete();

        return response()->noContent();
    }

    /**
     * §12.5 — POST /assignments/{id}/submissions. F-DEV-02.
     * Un étudiant ne dépose qu'un seul rendu (unique assignment_id+student_id).
     */
    public function submit(Request $request, Assignment $assignment): JsonResponse
    {
        $this->authorize('submit', $assignment);

        $request->validate([
            'file' => ['required', 'file', 'max:'.(int) config('classlink.files.max_kb', 10240)],
        ], [], ['file' => 'fichier']);

        $existing = Submission::where('assignment_id', $assignment->id)
            ->where('student_id', $request->user()->id)
            ->first();

        if ($existing) {
            throw new BusinessRuleException('Vous avez déjà déposé un rendu pour ce devoir.', 409);
        }

        $submission = DB::transaction(function () use ($assignment, $request) {
            $submission = Submission::create([
                'assignment_id' => $assignment->id,
                'student_id' => $request->user()->id,
                'file_path' => '',
                'file_name' => '',
                // RG-16 : calculé par le serveur, jamais par le client.
                'is_late' => $this->storage->isLate($assignment),
            ]);

            $this->storage->storeSubmission($request->file('file'), $submission);

            return $submission;
        });

        return response()->json(new SubmissionResource($submission->load('student')), 201);
    }

    /** §12.5 — GET /assignments/{id}/submissions. F-DEV-03. */
    public function submissions(Request $request, Assignment $assignment): JsonResponse
    {
        $this->authorize('grade', $assignment);

        $submissions = $assignment->submissions()->with('student')->get();

        return response()->json(['data' => SubmissionResource::collection($submissions)]);
    }

    /** §12.5 — PATCH /submissions/{id}. F-DEV-03 : note et commentaire. */
    public function grade(Request $request, Submission $submission): SubmissionResource
    {
        $this->authorize('grade', $submission);

        $data = $request->validate([
            'grade' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'feedback' => ['nullable', 'string', 'max:5000'],
        ]);

        $submission->update($data);

        AuditLog::record($request->user(), 'submission.grade', [
            'submission_id' => $submission->id,
        ]);

        $this->notifications->notify($submission->student, NotificationService::GRADED, [
            'assignment_id' => $submission->assignment_id,
            'grade' => $submission->grade,
        ]);

        return new SubmissionResource($submission->fresh('student'));
    }

    /**
     * §12.5 / RG-12 — GET /submissions/{id}/download.
     *
     * L'autorisation est verifiee ici, puis le fichier est servi depuis le
     * disque prive (jamais en public).
     */
    public function downloadSubmission(Request $request, Submission $submission)
    {
        $this->authorize('view', $submission);

        if (! $submission->file_path) {
            throw new BusinessRuleException(__('api.files.no_file'), 404);
        }

        AuditLog::record($request->user(), 'submission.download', [
            'submission_id' => $submission->id,
        ]);

        return $this->storage->download($submission->file_path, $submission->file_name, $submission->mime_type);
    }
}
