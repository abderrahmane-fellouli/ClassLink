<?php

namespace App\Http\Controllers;

use App\Enums\MembershipStatus;
use App\Exceptions\BusinessRuleException;
use App\Http\Resources\ClassroomResource;
use App\Http\Resources\MemberResource;
use App\Http\Resources\MembershipResource;
use App\Models\AuditLog;
use App\Models\Classroom;
use App\Models\Membership;
use App\Models\User;
use App\Services\JoinCodeService;
use App\Services\MembershipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * §12.2 — Classes et adhésions.
 */
class ClassroomController extends Controller
{
    public function __construct(
        private readonly MembershipService $memberships,
        private readonly JoinCodeService $joinCodes,
    ) {}

    /** §12.2 — GET /classes. F-CLS-05 : « Mes classes » selon le rôle. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate(['status' => ['sometimes', 'in:active,archived,all']]);
        $status = $data['status'] ?? 'active';

        if ($user->isTeacher()) {
            $classes = Classroom::where('teacher_id', $user->id)
                ->withCount(['memberships' => fn ($query) => $query->where('status', MembershipStatus::Accepted->value)])
                ->latest()
                ->get();
        } elseif ($user->isAdmin()) {
            $classes = Classroom::withCount(['memberships' => fn ($query) => $query->where('status', MembershipStatus::Accepted->value)])->latest()->get();
        } else {
            // RG-05 : un étudiant ne voit que ses classes acceptées,
            // plus celles où sa demande est en attente.
            $classes = Classroom::whereIn('id', $user->memberships()
                ->whereIn('status', [MembershipStatus::Accepted->value, MembershipStatus::Pending->value])
                ->select('classroom_id'))
                ->with('teacher')
                ->withCount(['memberships' => fn ($query) => $query->where('status', MembershipStatus::Accepted->value)])
                ->get();
            $membershipStatuses = $user->memberships()->pluck('status', 'classroom_id');
            foreach ($classes as $classroom) {
                $classroom->setAttribute('membership_status', $membershipStatuses->get($classroom->id));
            }
        }

        if ($status !== 'all') {
            $classes = $classes->where('status', $status)->values();
        }

        return response()->json(['data' => ClassroomResource::collection($classes)]);
    }

    /** §12.2 — POST /classes. F-CLS-01. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'group_label' => ['required', 'string', 'max:255'],
            'school_year' => ['required', 'string', 'max:32'],
        ]);

        $classroom = Classroom::create($data + [
            'teacher_id' => $request->user()->id,
            'join_code' => $this->joinCodes->generate(), // F-CLS-02
            'join_enabled' => true,
            'status' => 'active',
        ]);

        AuditLog::record($request->user(), 'class.create', ['classroom_id' => $classroom->id]);

        return response()->json(new ClassroomResource($classroom), 201);
    }

    /** §12.2 — GET /classes/{id}. */
    public function show(Request $request, Classroom $classroom): ClassroomResource
    {
        $this->authorize('view', $classroom);

        $classroom->loadCount('memberships');

        return new ClassroomResource($classroom);
    }

    /** §12.2 — PATCH /classes/{id}. F-CLS-04. */
    public function update(Request $request, Classroom $classroom): ClassroomResource
    {
        // RG-04 : d'abord la propriété (403 si ce n'est pas ma classe),
        // ensuite l'état de la classe (409 si elle est archivée). Confondre
        // les deux donnerait au propriétaire un « accès refusé » trompeur.
        $this->authorize('manage', $classroom);
        $this->assertWritable($classroom);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'subject' => ['sometimes', 'required', 'string', 'max:255'],
            'group_label' => ['sometimes', 'required', 'string', 'max:255'],
            'school_year' => ['sometimes', 'required', 'string', 'max:32'],
        ]);

        $classroom->update($data);

        return new ClassroomResource($classroom->fresh());
    }

    /**
     * RG-10 : une classe archivée est en lecture seule. 409 Conflict
     * explique la situation au client, qui peut l'afficher telle quelle.
     */
    private function assertWritable(Classroom $classroom): void
    {
        if ($classroom->isReadOnly()) {
            throw new BusinessRuleException(
                'Cette classe est archivée : son contenu ne peut plus être modifié.',
                409,
                ['status' => $classroom->status]
            );
        }
    }

    /** §12.2 — POST /classes/{id}/archive. RG-10. */
    public function archive(Request $request, Classroom $classroom): JsonResponse
    {
        $this->authorize('manage', $classroom);

        $classroom->update(['status' => 'archived', 'archived_at' => now(), 'join_enabled' => false]);

        AuditLog::record($request->user(), 'class.archive', ['classroom_id' => $classroom->id]);

        return response()->json(new ClassroomResource($classroom->fresh()));
    }

    /** §12.2 — POST /classes/{id}/code/regenerate. F-CLS-03. */
    public function regenerateCode(Request $request, Classroom $classroom): JsonResponse
    {
        $this->authorize('manage', $classroom);
        $this->assertWritable($classroom);

        $code = $this->joinCodes->regenerate($classroom);

        AuditLog::record($request->user(), 'class.code_regenerate', [
            'classroom_id' => $classroom->id,
        ]);

        return response()->json(['join_code' => $code]);
    }

    /** §12.2 — POST /classes/{id}/code/toggle. F-CLS-03 / RG-08. */
    public function toggleCode(Request $request, Classroom $classroom): JsonResponse
    {
        $this->authorize('manage', $classroom);
        $this->assertWritable($classroom);

        $classroom->update(['join_enabled' => ! $classroom->join_enabled]);

        AuditLog::record($request->user(), 'class.code_toggle', [
            'classroom_id' => $classroom->id,
            'join_enabled' => $classroom->join_enabled,
        ]);

        return response()->json(['join_enabled' => (bool) $classroom->fresh()->join_enabled]);
    }

    /**
     * §12.2 — GET /classes/{id}/members. F-CLS-06.
     * T-22 : aucun email dans la réponse pour un étudiant (voir MemberResource).
     */
    public function members(Request $request, Classroom $classroom): JsonResponse
    {
        $this->authorize('view', $classroom);

        $members = $classroom->memberships()
            ->whereIn('status', [MembershipStatus::Accepted->value, MembershipStatus::Pending->value])
            ->with('student')
            ->orderBy('requested_at')
            ->get();

        return response()->json(['data' => MemberResource::collection($members)]);
    }

    /** §12.2 — DELETE /classes/{id}/members/{studentId}. F-REQ-08 / RG-09. */
    public function removeMember(Request $request, Classroom $classroom, int $studentId): Response
    {
        $this->authorize('removeMember', $classroom);

        $student = User::findOrFail($studentId);

        $this->memberships->remove($request->user(), $classroom, $student);

        return response()->noContent();
    }

    /** §12.2 — GET /classes/{id}/join-requests. F-REQ-03. */
    public function joinRequests(Request $request, Classroom $classroom): JsonResponse
    {
        $this->authorize('decideJoinRequest', $classroom);

        $requests = $classroom->memberships()
            ->where('status', MembershipStatus::Pending->value)
            ->with('student')
            ->orderBy('requested_at')
            ->get();

        return response()->json(['data' => MemberResource::collection($requests)]);
    }

    /** §12.2 — POST /classes/{id}/join-requests/accept-all. F-REQ-05. */
    public function acceptAll(Request $request, Classroom $classroom): JsonResponse
    {
        $this->authorize('modify', $classroom);

        $count = $this->memberships->acceptAll($request->user(), $classroom);

        return response()->json(['accepted' => $count]);
    }

    /** §12.2 — POST /join-requests/{id}/accept. F-REQ-04. */
    public function accept(Request $request, Membership $membership): MembershipResource
    {
        $this->authorize('decide', $membership);

        return new MembershipResource(
            $this->memberships->accept($request->user(), $membership)
        );
    }

    /** §12.2 — POST /join-requests/{id}/reject. F-REQ-04 / RG-07. */
    public function reject(Request $request, Membership $membership): MembershipResource
    {
        $this->authorize('decide', $membership);

        return new MembershipResource(
            $this->memberships->reject($request->user(), $membership)
        );
    }

    /**
     * §12.2 — POST /classes/{id}/members/import (Could, F-REQ-09).
     * Import CSV de la liste officielle avec approbation automatique.
     */
    public function importMembers(Request $request, Classroom $classroom): JsonResponse
    {
        $this->authorize('modify', $classroom);

        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
        ]);

        $rows = [];
        $errors = [];
        $handle = fopen($request->file('file')->getRealPath(), 'rb');
        try {
            $header = fgetcsv($handle);
            $header = array_map(fn ($value) => strtolower(trim((string) $value, "\xEF\xBB\xBF \t\r\n")), $header ?: []);
            if (! in_array('email', $header, true) || count($header) !== count(array_unique($header))) {
                throw new BusinessRuleException('CSV must contain a unique email header.', 422);
            }
            $number = 1;
            while (($line = fgetcsv($handle)) !== false) {
                $number++;
                if (count($line) !== count($header)) {
                    $errors[] = ['row' => $number, 'reason' => 'column_count'];

                    continue;
                }
                $row = array_combine($header, $line);
                $row['_row'] = $number;
                $rows[] = $row;
            }
        } finally {
            fclose($handle);
        }

        $result = $this->memberships->importAccepted($request->user(), $classroom, $rows);
        $result['errors'] = array_merge($errors, $result['errors']);

        return response()->json($result, 201);
    }
}
