<?php

namespace App\Http\Controllers;

use App\Enums\ClassStatus;
use App\Enums\MembershipStatus;
use App\Enums\Role;
use App\Models\AiJob;
use App\Models\AiProvider;
use App\Models\AuditLog;
use App\Models\Classroom;
use App\Models\Membership;
use App\Models\Quiz;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * §12.6 — Administration. F-ADM-01 à F-ADM-06.
 */
class AdminController extends Controller
{
    /** F-ADM-01 — liste et recherche des utilisateurs. */
    public function users(Request $request): JsonResponse
    {
        $query = User::query();

        if ($search = $request->string('q')->toString()) {
            // L'administrateur a le droit de chercher par email (F-ADM-01).
            $query->where(fn ($q) => $q
                ->where('display_name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%"));
        }

        if ($role = $request->string('role')->toString()) {
            $query->where('role', $role);
        }

        $users = $query->orderBy('created_at')->paginate(25);

        return response()->json([
            'data' => $users->getCollection()->map(fn (User $user) => [
                'id' => $user->id,
                'email' => $user->email, // l'admin a le droit (RG-18)
                'display_name' => $user->display_name,
                'role' => $user->role,
                'role_locked' => (bool) $user->role_locked,
                'role_candidate' => $user->role_candidate,
                'verification_source' => $user->microsoft_verified_at ? 'microsoft' : null,
                'is_active' => (bool) $user->is_active,
                'last_login_at' => $user->last_login_at?->toIso8601String(),
                'created_at' => $user->created_at?->toIso8601String(),
            ]),
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'total' => $users->total(),
            ],
        ]);
    }

    /**
     * F-ADM-02 — « Change le rôle, active ou désactive ».
     * RG-03 : un rôle modifié par l'admin est VERROUILLÉ et n'est plus
     * recalculé par le détecteur automatique.
     * RG-20 : tracé dans le journal d'audit.
     */
    public function updateUser(Request $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);
        $data = $request->validate([
            'role' => ['sometimes', 'required', 'string', 'in:'.implode(',', Role::assignable())],
            'is_active' => ['sometimes', 'boolean'],
        ], [], ['role' => 'rôle', 'is_active' => 'activation']);

        $before = ['role' => $user->role, 'is_active' => (bool) $user->is_active];

        if (array_key_exists('role', $data)) {
            $data['role_locked'] = true; // RG-03
        }

        $user->update($data);

        // RG-20 : une désactivation est aussi sensible qu'un changement de
        // rôle ; elle est tracée sous son propre libellé pour être
        // recherchable dans le journal.
        AuditLog::record($request->user(), 'user.role_change', [
            'target_user_id' => $user->id,
            'before' => $before,
            'after' => ['role' => $user->role, 'is_active' => (bool) $user->is_active],
        ]);

        if (array_key_exists('is_active', $data)) {
            AuditLog::record($request->user(), 'user.activation_change', [
                'target_user_id' => $user->id,
                'is_active' => (bool) $user->is_active,
            ]);
        }

        return response()->json([
            'id' => $user->id,
            'email' => $user->email,
            'display_name' => $user->display_name,
            'role' => $user->role,
            'role_locked' => (bool) $user->role_locked,
            'is_active' => (bool) $user->is_active,
        ]);
    }

    /** F-ADM-03 — liste de toutes les classes. */
    public function classes(Request $request): JsonResponse
    {
        $classes = Classroom::with('teacher:id,display_name')
            ->withCount(['memberships' => fn ($query) => $query->where('status', MembershipStatus::Accepted->value)])
            ->orderByDesc('created_at')
            ->limit(1000)
            ->get();

        return response()->json([
            'data' => $classes->map(fn (Classroom $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'subject' => $c->subject,
                'group_label' => $c->group_label,
                'school_year' => $c->school_year,
                'status' => $c->status,
                'join_code' => $c->join_code,
                'join_enabled' => (bool) $c->join_enabled,
                'members_count' => $c->memberships_count,
                'teacher' => ['id' => $c->teacher?->id, 'display_name' => $c->teacher?->display_name],
            ]),
        ]);
    }

    /** F-ADM-03 — transfert de propriété vers un enseignant. */
    public function transferClass(Request $request, Classroom $classroom): JsonResponse
    {
        $this->authorize('transfer', $classroom);
        $data = $request->validate([
            'teacher_id' => ['required', 'integer', 'exists:users,id'],
        ], [], ['teacher_id' => 'nouvel enseignant']);

        $newOwner = User::findOrFail($data['teacher_id']);

        if (! $newOwner->isTeacher() && ! $newOwner->isAdmin()) {
            return response()->json([
                'message' => 'Le compte cible doit être enseignant ou administrateur.',
            ], 422);
        }

        $previous = $classroom->teacher_id;
        $classroom->update(['teacher_id' => $newOwner->id]);

        AuditLog::record($request->user(), 'class.transfer', [
            'classroom_id' => $classroom->id,
            'from' => $previous,
            'to' => $newOwner->id,
        ]);

        return response()->json(['message' => 'Classe transférée.', 'teacher_id' => $newOwner->id]);
    }

    /** F-ADM-03 — archivage par l'administrateur. */
    public function archiveClass(Request $request, Classroom $classroom): JsonResponse
    {
        $this->authorize('archive', $classroom);
        $classroom->update([
            'status' => ClassStatus::Archived->value,
            'archived_at' => now(),
            'join_enabled' => false,
        ]);

        AuditLog::record($request->user(), 'class.archive_admin', [
            'classroom_id' => $classroom->id,
        ]);

        return response()->json(['status' => $classroom->fresh()->status]);
    }

    /** F-ADM-04 — configuration des fournisseurs IA. */
    public function aiProviders(Request $request): JsonResponse
    {
        $providers = AiProvider::orderBy('priority')->get();

        return response()->json([
            'data' => $providers->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'priority' => $p->priority,
                'enabled' => (bool) $p->enabled,
                'daily_limit' => $p->daily_limit,
                'used_today' => $p->used_today,
                'has_key' => (bool) $p->secretKey(),
            ]),
        ]);
    }

    /**
     * F-ADM-04 — ordre, activation et quota. RG-20 : action sensible
     * journalisée. Les clés ne transitent jamais (§15.2).
     */
    public function updateAiProvider(Request $request, AiProvider $provider): JsonResponse
    {
        $data = $request->validate([
            'priority' => ['sometimes', 'required', 'integer', 'min:0', 'max:100'],
            'enabled' => ['sometimes', 'required', 'boolean'],
            'daily_limit' => ['sometimes', 'required', 'integer', 'min:0', 'max:1000'],
        ], [], ['priority' => 'priorité', 'daily_limit' => 'quota quotidien']);

        $provider->update($data);

        AuditLog::record($request->user(), 'ai.provider_update', [
            'provider' => $provider->name,
            'after' => $data,
        ]);

        return response()->json([
            'id' => $provider->id,
            'name' => $provider->name,
            'priority' => $provider->priority,
            'enabled' => (bool) $provider->fresh()->enabled,
            'daily_limit' => $provider->daily_limit,
            'used_today' => $provider->used_today,
        ]);
    }

    /** F-ADM-04 — remise à zéro du quota quotidien. */
    public function resetAiQuota(Request $request, AiProvider $provider): JsonResponse
    {
        $provider->update(['used_today' => 0, 'last_reset_at' => now()]);

        AuditLog::record($request->user(), 'ai.quota_reset', ['provider' => $provider->name]);

        return response()->json(['used_today' => 0]);
    }

    /** F-ADM-05 — journal d'audit, filtrable. */
    public function auditLogs(Request $request): JsonResponse
    {
        $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'user_id' => ['sometimes', 'integer'],
            'action' => ['sometimes', 'string', 'max:80', 'regex:/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)*$/D'],
        ]);
        $query = AuditLog::with('user:id,display_name,email');

        if ($action = $request->string('action')->toString()) {
            $query->where(fn ($q) => $q->where('action', $action)->orWhere('action', 'like', $action.'.%'));
        }

        if ($userId = $request->integer('user_id')) {
            $query->where('user_id', $userId);
        }

        if ($from = $request->string('from')->toString()) {
            $query->where('created_at', '>=', \Illuminate\Support\Carbon::parse($from));
        }

        if ($to = $request->string('to')->toString()) {
            $end = \Illuminate\Support\Carbon::parse($to);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $to)) $end->endOfDay();
            $query->where('created_at', '<=', $end);
        }

        $logs = $query->orderByDesc('created_at')->paginate(50);

        return response()->json([
            'data' => collect($logs->items())->map(fn ($log) => [
                'id' => $log->id,
                'action' => $log->action,
                'user' => $log->user ? [
                    'id' => $log->user->id,
                    'display_name' => $log->user->display_name,
                ] : null,
                'context' => $log->context,
                'ip' => $log->ip,
                'created_at' => $log->created_at?->toIso8601String(),
            ]),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'total' => $logs->total(),
            ],
        ]);
    }

    /** F-ADM-06 — statistiques globales. */
    public function stats(): JsonResponse
    {
        $byRole = User::selectRaw('role, count(*) as total')
            ->groupBy('role')
            ->pluck('total', 'role');

        return response()->json([
            'users' => [
                'total' => User::count(),
                'by_role' => $byRole,
                'active' => User::where('is_active', true)->count(),
                'pending' => User::where('role', Role::Pending->value)->count(),
            ],
            'classes' => [
                'total' => Classroom::count(),
                'active' => Classroom::where('status', ClassStatus::Active->value)->count(),
                'archived' => Classroom::where('status', ClassStatus::Archived->value)->count(),
            ],
            'memberships' => [
                'pending' => Membership::where('status', MembershipStatus::Pending->value)->count(),
                'accepted' => Membership::where('status', MembershipStatus::Accepted->value)->count(),
            ],
            'quizzes' => [
                'total' => Quiz::count(),
                'published' => Quiz::where('status', 'published')->count(),
                'draft' => Quiz::where('status', 'draft')->count(),
            ],
            'ai_jobs' => [
                'total' => AiJob::count(),
                'failed' => AiJob::where('status', 'failed')->count(),
            ],
        ]);
    }

    /** Comptes « en attente » à valider — F-AUTH-05. */
    public function pendingUsers(): JsonResponse
    {
        $users = User::where('role', Role::Pending->value)->orderBy('created_at')->limit(500)->get();

        return response()->json([
            'data' => $users->map(fn (User $u) => [
                'id' => $u->id,
                'email' => $u->email,
                'display_name' => $u->display_name,
                'role' => $u->role,
                'role_locked' => (bool) $u->role_locked,
                'role_candidate' => $u->role_candidate,
                'verification_source' => $u->microsoft_verified_at ? 'microsoft' : null,
                'is_active' => (bool) $u->is_active,
                'created_at' => $u->created_at?->toIso8601String(),
            ]),
        ]);
    }
}
