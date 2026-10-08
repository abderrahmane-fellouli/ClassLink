<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Classroom;
use App\Models\Membership;
use App\Models\PartnerRequest;
use App\Models\User;
use App\Services\JoinCodeService;
use App\Services\NotificationService;
use App\Services\RosterImportService;
use App\Services\SchoolAccess;
use App\Services\SchoolSetupService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SchoolController extends Controller
{
    public function __construct(private SchoolAccess $access, private SchoolSetupService $setup) {}

    public function index(Request $r)
    {
        $user = $r->user();
        $groups = $this->setup->groups($user)->get();
        $years = DB::table('academic_years')->orderByDesc('starts_on')->get();
        $yearsById = $years->keyBy('id');
        // Un seul marqueur d'inscription pour tout le tableau de bord (pas de
        // requete EXISTS par groupe).
        $enrolledIds = $user->isStudent()
            ? DB::table('school_enrollments')->where('student_id', $user->id)->whereNotNull('active_student_id')->whereNull('ends_at')->pluck('classroom_id')->flip()
            : collect();
        foreach ($groups as $group) {
            $year = $yearsById->get($group->academic_year_id);
            $group->setAttribute('is_read_only', $group->isReadOnly() || ! $year || $year->status === 'archived' || $year->ends_on < now('Africa/Casablanca')->toDateString());
            if ($user->isStudent()) {
                $group->setAttribute('module_only_access', ! ($user->is_active && $enrolledIds->has($group->id)));
            }
        }
        $query = DB::table('module_offerings as o')->join('school_modules as m', 'm.id', '=', 'o.module_id')
            ->whereIn('o.classroom_id', $groups->pluck('id'))->select('o.*', 'm.name as module_name', 'm.code as module_code');
        if ($user->isTeacher()) {
            $query->whereIn('o.id', DB::table('teaching_assignments')->where('teacher_id', $user->id)->whereNotNull('active_teacher_id')->whereNull('ends_at')->select('offering_id'));
        } elseif ($user->isStudent()) {
            $query->where(function ($q) use ($user) {
                $q->whereIn('o.classroom_id', DB::table('school_enrollments')->where('student_id', $user->id)->whereNotNull('active_student_id')->select('classroom_id'))
                    ->orWhereIn('o.id', DB::table('module_access_grants')->where('student_id', $user->id)->whereNull('revoked_at')->where('expires_at', '>', now())->select('offering_id'));
            });
        }
        $offerings = $query->get();
        $staff = DB::table('teaching_assignments as a')->join('users as u', 'u.id', '=', 'a.teacher_id')->whereIn('a.offering_id', $offerings->pluck('id'))
            ->whereNotNull('a.active_teacher_id')->whereNull('a.ends_at')->where('u.is_active', true)->where('u.role', 'teacher')->select('a.id', 'a.offering_id', 'u.id as teacher_id', 'u.display_name')->get();
        $delegates = DB::table('class_delegates as d')->join('users as u', 'u.id', '=', 'd.student_id')->whereIn('d.classroom_id', $groups->pluck('id'))
            ->whereNotNull('d.active_slot')->whereNull('d.revoked_at')->where('d.ends_at', '>', now())->where('u.is_active', true)->select('d.id', 'd.classroom_id', 'd.student_id', 'u.display_name', 'd.ends_at')->get();

        return response()->json(['groups' => $groups, 'offerings' => $offerings, 'teachers' => $staff, 'delegates' => $delegates,
            'years' => $years,
            'available_offerings' => $user->isTeacher() ? DB::table('module_offerings as o')->join('school_modules as m', 'm.id', '=', 'o.module_id')->join('classrooms as c', 'c.id', '=', 'o.classroom_id')->where('c.status', 'active')->where('o.status', 'active')->select('o.id', 'm.name as module_name', 'c.name as classroom_name', 'c.school_year')->limit(200)->get() : [],
            'modules' => $user->isAdmin() ? DB::table('school_modules')->orderBy('name')->get() : [],
            'timezone' => 'Africa/Casablanca']);
    }

    public function createYear(Request $r)
    {
        $this->access->admin($r->user());
        $data = $r->validate(['name' => ['required', 'string', 'max:32', 'unique:academic_years'], 'starts_on' => ['required', 'date'], 'ends_on' => ['required', 'date', 'after:starts_on']]);
        $id = DB::table('academic_years')->insertGetId($data + ['status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        AuditLog::record($r->user(), 'school.year.create', ['year_id' => $id]);

        return response()->json(['id' => $id], 201);
    }

    /**
     * Editing an academic year: a closed (archived) year keeps only its label
     * editable so historical grade/group contexts never silently shift.
     */
    public function updateYear(Request $r, int $year)
    {
        $this->access->admin($r->user());

        return DB::transaction(function () use ($r, $year) {
            $record = DB::table('academic_years')->where('id', $year)->lockForUpdate()->first();
            abort_unless($record, 404);
            $rules = ['name' => ['sometimes', 'required', 'string', 'max:32', Rule::unique('academic_years', 'name')->ignore($year)]];
            if ($record->status !== 'archived') {
                $rules += ['starts_on' => ['sometimes', 'required', 'date'], 'ends_on' => ['sometimes', 'required', 'date']];
            } else {
                abort_if($r->hasAny(['starts_on', 'ends_on']), 422, __('api.school.year_frozen'));
            }
            $data = $r->validate($rules);
            $final = ['starts_on' => $data['starts_on'] ?? $record->starts_on, 'ends_on' => $data['ends_on'] ?? $record->ends_on];
            validator($final + ['name' => $data['name'] ?? $record->name], ['ends_on' => ['date', 'after:starts_on']])->validate();
            DB::table('academic_years')->where('id', $year)->update($data + ['updated_at' => now()]);
            AuditLog::record($r->user(), 'school.year.update', ['year_id' => $year, 'fields' => array_keys($data)]);

            return response()->noContent();
        });
    }

    public function createGroup(Request $r)
    {
        $this->access->admin($r->user());
        $data = $r->validate(['academic_year_id' => ['required', 'integer', 'exists:academic_years,id'], 'official_code' => ['required', 'string', 'max:64', Rule::unique('classrooms')->where('academic_year_id', $r->input('academic_year_id'))], 'name' => ['required', 'string', 'max:255'], 'filiere' => ['required', 'string', 'max:191'], 'level' => ['required', 'string', 'max:64']]);

        return response()->json($this->setup->createGroup($r->user(), $data)->only(['id', 'name', 'official_code']), 201);
    }

    public function createModule(Request $r)
    {
        $this->access->admin($r->user());
        $data = $r->validate(['code' => ['required', 'string', 'max:64', 'unique:school_modules'], 'name' => ['required', 'string', 'max:255']]);
        $id = DB::table('school_modules')->insertGetId($data + ['status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        AuditLog::record($r->user(), 'school.module.create', ['module_id' => $id]);

        return response()->json(['id' => $id], 201);
    }

    /** Rename/recode/archive a module definition. Existing offerings keep their history. */
    public function updateModule(Request $r, int $module)
    {
        $this->access->admin($r->user());
        $record = DB::table('school_modules')->find($module);
        abort_unless($record, 404);
        $data = $r->validate(['name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => ['sometimes', 'required', 'string', 'max:64', Rule::unique('school_modules', 'code')->ignore($module)],
            'status' => ['sometimes', 'required', 'in:active,archived']]);
        DB::table('school_modules')->where('id', $module)->update($data + ['updated_at' => now()]);
        AuditLog::record($r->user(), 'school.module.update', ['module_id' => $module, 'fields' => array_keys($data)]);

        return response()->noContent();
    }

    /** Archive or restore one module offering without touching other groups. */
    public function updateOffering(Request $r, int $offering)
    {
        $this->access->admin($r->user());
        $o = DB::table('module_offerings')->find($offering);
        abort_unless($o, 404);
        $group = $this->access->group($o->classroom_id);
        $this->access->writable($group);
        $data = $r->validate(['status' => ['required', 'in:active,archived']]);
        if ($data['status'] === 'active' && $group->status === 'archived') {
            abort(409, __('api.school.archived'));
        }
        DB::table('module_offerings')->where('id', $offering)->update($data + ['updated_at' => now()]);
        AuditLog::record($r->user(), 'school.offering.update', ['offering_id' => $offering, 'status' => $data['status']]);

        return response()->noContent();
    }

    public function createOffering(Request $r, int $group)
    {
        $this->access->admin($r->user());
        $class = $this->access->group($group);
        $this->access->writable($class);
        $data = $r->validate(['module_id' => ['required', 'integer', Rule::exists('school_modules', 'id')->where('status', 'active'), Rule::unique('module_offerings')->where('classroom_id', $group)]]);
        $id = DB::table('module_offerings')->insertGetId($data + ['classroom_id' => $group, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['id' => $id], 201);
    }

    public function assign(Request $r, int $offering)
    {
        $this->access->admin($r->user());
        $r->validate(['teacher_id' => ['required', 'integer', 'exists:users,id']]);

        return response()->json(['id' => $this->setup->assign($r->user(), $offering, User::findOrFail($r->integer('teacher_id')))], 201);
    }

    public function revokeAssignment(Request $r, int $assignment)
    {
        $this->setup->revokeAssignment($r->user(), $assignment);

        return response()->noContent();
    }

    public function coordinator(Request $r, int $group)
    {
        $this->access->admin($r->user());
        $class = $this->access->group($group);
        $this->access->writable($class);
        $data = $r->validate(['coordinator_id' => ['nullable', 'integer', 'exists:users,id'], 'coordinator_can_manage_roster' => ['required', 'boolean']]);
        if ($data['coordinator_id']) {
            $u = User::findOrFail($data['coordinator_id']);
            abort_unless($u->isTeacher() && $u->is_active && $u->role_locked, 422);
        }
        $class->forceFill($data)->save();
        AuditLog::record($r->user(), 'school.coordinator.set', ['classroom_id' => $group, 'coordinator_id' => $data['coordinator_id'], 'can_manage_roster' => $data['coordinator_can_manage_roster']]);

        return response()->noContent();
    }

    public function roster(Request $r, int $group)
    {
        $class = $this->access->group($group);
        abort_unless($this->access->canView($r->user(), $class) || $this->access->rosterManager($r->user(), $class), 403);
        $query = DB::table('school_enrollments as e')->join('users as u', 'u.id', '=', 'e.student_id')->where('e.classroom_id', $group)->whereNotNull('e.active_student_id')
            ->select('e.id', 'u.id as student_id', 'u.display_name');
        if (! $r->user()->isStudent()) {
            $query->addSelect('u.school_identifier');
        }
        $data = $r->validate(['search' => ['sometimes', 'string', 'max:100']]);
        if (! empty($data['search'])) {
            $query->where('u.display_name', 'like', '%'.$data['search'].'%');
        }

        return response()->json($query->orderBy('u.display_name')->orderBy('u.id')->paginate(50));
    }

    public function pending(Request $r, int $group)
    {
        abort_unless($this->access->rosterManager($r->user(), $this->access->group($group)), 403);

        return response()->json(DB::table('memberships as m')->join('users as u', 'u.id', '=', 'm.student_id')->where('m.classroom_id', $group)->where('m.status', 'pending')
            ->select('m.id', 'm.student_id', 'u.display_name', 'm.requested_at')->orderBy('m.id')->paginate(50));
    }

    public function decide(Request $r, int $membership)
    {
        $m = Membership::findOrFail($membership);
        $group = $this->access->group($m->classroom_id);
        abort_unless($this->access->rosterManager($r->user(), $group), 403);
        $this->access->writable($group);
        $data = $r->validate(['decision' => ['required', 'in:accepted,rejected']]);
        DB::transaction(function () use ($r, $m, $group, $data) {
            $current = Membership::whereKey($m->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->status === 'pending', 409);
            if ($data['decision'] === 'accepted') {
                $this->setup->enroll($r->user(), $group, User::findOrFail($m->student_id));
            } else {
                $current->update(['status' => 'rejected', 'decided_at' => now(), 'decided_by' => $r->user()->id]);
                AuditLog::record($r->user(), 'school.enrollment.reject', ['membership_id' => $m->id]);
                $student = User::find($m->student_id);
                $notify = app(NotificationService::class);
                DB::afterCommit(fn () => $student && $notify->notify($student, NotificationService::MEMBERSHIP_REJECTED, [
                    'classroom_id' => $group->id, 'classroom_name' => $group->name,
                ]));
            }
        });

        return response()->noContent();
    }

    public function requestAssignment(Request $r, int $offering)
    {
        abort_unless($r->user()->isTeacher(), 403);
        $o = DB::table('module_offerings')->find($offering);
        abort_unless($o, 404);
        $this->access->writable($this->access->group($o->classroom_id));
        $data = $r->validate(['reason' => ['required', 'string', 'max:500']]);
        abort_if(DB::table('school_assignment_requests')->where('teacher_id', $r->user()->id)->where('offering_id', $offering)->exists(), 409, __('api.school.request_exists'));
        $id = DB::table('school_assignment_requests')->insertGetId($data + ['teacher_id' => $r->user()->id, 'offering_id' => $offering, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        $this->notifyAssignmentRequest($r->user(), $o, $id);

        return response()->json(['id' => $id], 201);
    }

    /** Notify admins (and the roster-capable coordinator) about a pending module request. */
    private function notifyAssignmentRequest(User $teacher, object $offering, int $requestId): void
    {
        $context = DB::table('module_offerings as o')->join('classrooms as c', 'c.id', '=', 'o.classroom_id')->join('school_modules as m', 'm.id', '=', 'o.module_id')
            ->where('o.id', $offering->id)->select('c.id as classroom_id', 'c.name as classroom_name', 'm.name as module_name',
                'c.coordinator_id', 'c.coordinator_can_manage_roster')->first();
        if (! $context) {
            return;
        }
        $recipients = User::where('is_active', true)->where('role', 'admin')->get();
        if ($context->coordinator_can_manage_roster && $context->coordinator_id) {
            $coordinator = User::find($context->coordinator_id);
            if ($coordinator?->is_active) {
                $recipients->push($coordinator);
            }
        }
        DB::afterCommit(fn () => app(NotificationService::class)->notifyMany($recipients->unique('id'), NotificationService::ASSIGNMENT_REQUEST_RECEIVED, [
            'request_id' => $requestId, 'url' => '/app/school', 'teacher_name' => $teacher->display_name,
            'classroom_id' => $context->classroom_id, 'classroom_name' => $context->classroom_name, 'module_name' => $context->module_name,
        ]));
    }

    public function enroll(Request $r, int $group)
    {
        abort_unless($this->access->rosterManager($r->user(), $this->access->group($group)), 403);
        $r->validate(['student_id' => ['required', 'integer', 'exists:users,id']]);
        $this->setup->enroll($r->user(), $this->access->group($group), User::findOrFail($r->integer('student_id')));

        return response()->noContent();
    }

    public function remove(Request $r, int $group, int $student)
    {
        $this->setup->leave($r->user(), $this->access->group($group), User::findOrFail($student));

        return response()->noContent();
    }

    public function transfer(Request $r, int $group, int $student)
    {
        $r->validate(['target_group_id' => ['required', 'integer', 'exists:classrooms,id']]);
        $this->setup->transfer($r->user(), $this->access->group($group), $this->access->group($r->integer('target_group_id')), User::findOrFail($student));

        return response()->noContent();
    }

    public function appoint(Request $r, int $group)
    {
        $r->validate(['student_id' => ['required', 'integer', 'exists:users,id'], 'ends_at' => ['required', 'date', 'after:now']]);

        return response()->json(['id' => $this->setup->appoint($r->user(), $this->access->group($group), User::findOrFail($r->integer('student_id')), $r->input('ends_at'))], 201);
    }

    public function revokeDelegate(Request $r, int $delegate)
    {
        $this->access->admin($r->user());
        $d = DB::table('class_delegates')->find($delegate);
        abort_unless($d, 404);
        DB::transaction(function () use ($r, $d) {
            Classroom::whereKey($d->classroom_id)->lockForUpdate()->firstOrFail();
            DB::table('class_delegates')->where('id', $d->id)->update(['active_slot' => null, 'revoked_at' => now(), 'updated_at' => now()]);
            AuditLog::record($r->user(), 'school.delegate.revoke', ['delegate_id' => $d->id]);
            $student = User::find($d->student_id);
            $group = Classroom::find($d->classroom_id);
            DB::afterCommit(fn () => $student && $group && app(NotificationService::class)->notify($student, NotificationService::DELEGATE_CHANGED, [
                'action' => 'revoked', 'classroom_id' => $group->id, 'classroom_name' => $group->name,
            ]));
        });

        return response()->noContent();
    }

    public function activate(Request $r, int $group)
    {
        $this->access->admin($r->user());
        $class = $this->access->group($group);
        $this->access->writable($class);
        abort_unless(DB::table('module_offerings')->where('classroom_id', $group)->whereIn('id', DB::table('teaching_assignments')->whereNotNull('active_teacher_id')->whereNull('ends_at')->select('offering_id'))->exists(), 409, __('api.school.missing_assignments'));
        $class->forceFill(['join_enabled' => true])->save();
        AuditLog::record($r->user(), 'school.group.activate', ['classroom_id' => $group]);

        return response()->json(['join_code' => $class->join_code]);
    }

    public function archive(Request $r, int $group)
    {
        $this->access->admin($r->user());
        $class = $this->access->group($group);
        DB::transaction(function () use ($r, $class) {
            $delegates = DB::table('class_delegates')->where('classroom_id', $class->id)->whereNotNull('active_slot')->pluck('student_id');
            $class->forceFill(['status' => 'archived', 'archived_at' => now(), 'join_enabled' => false])->save();
            DB::table('class_delegates')->where('classroom_id', $class->id)->whereNotNull('active_slot')->update(['active_slot' => null, 'revoked_at' => now(), 'updated_at' => now()]);
            AuditLog::record($r->user(), 'school.group.archive', ['classroom_id' => $class->id]);
            if ($delegates->isNotEmpty()) {
                $notify = app(NotificationService::class);
                DB::afterCommit(fn () => $notify->notifyMany(User::whereIn('id', $delegates)->get(), NotificationService::DELEGATE_CHANGED, [
                    'action' => 'revoked', 'classroom_id' => $class->id, 'classroom_name' => $class->name,
                ]));
            }
        });

        return response()->noContent();
    }

    /**
     * Restore an archived group. Invitations stay closed and revoked delegates
     * stay revoked: only the roster/settings state comes back, deliberately.
     */
    public function restore(Request $r, int $group)
    {
        $this->access->admin($r->user());
        DB::transaction(function () use ($r, $group) {
            $class = Classroom::whereKey($group)->lockForUpdate()->firstOrFail();
            abort_unless($class->is_official && $class->status === 'archived', 409);
            $year = DB::table('academic_years')->where('id', $class->academic_year_id)->lockForUpdate()->first();
            abort_unless($year && $year->status !== 'archived' && $year->ends_on >= now('Africa/Casablanca')->toDateString(), 422, __('api.school.year_frozen'));
            $class->forceFill(['status' => 'active', 'archived_at' => null])->save();
            AuditLog::record($r->user(), 'school.group.restore', ['classroom_id' => $class->id]);
        });

        return response()->noContent();
    }

    public function previewRoster(Request $r, int $group, RosterImportService $imports)
    {
        $class = $this->access->group($group);
        abort_unless($this->access->rosterManager($r->user(), $class), 403);
        $data = $r->validate(['file' => ['required_without:rows', 'file', 'max:2048', 'extensions:csv,txt'], 'rows' => ['required_without:file', 'array', 'min:1', 'max:2000'], 'rows.*.student_identifier' => ['required', 'string', 'max:64'], 'rows.*.email' => ['required', 'string', 'max:255'], 'rows.*.display_name' => ['required', 'string', 'max:255']]);
        $rows = $r->hasFile('file') ? $imports->csv($r->file('file')->getRealPath()) : $data['rows'];

        return response()->json($imports->preview($r->user(), $class, $rows))->header('Cache-Control', 'private, no-store');
    }

    public function commitRoster(Request $r, int $group, RosterImportService $imports)
    {
        $data = $r->validate(['batch_id' => ['required', 'uuid']]);

        return response()->json(['imported' => $imports->commit($r->user(), $this->access->group($group), $data['batch_id'])]);
    }

    public function people(Request $r)
    {
        $this->access->admin($r->user());
        $data = $r->validate(['role' => ['required', 'in:student,teacher,admin'], 'search' => ['sometimes', 'string', 'max:100']]);
        $query = User::where('role', $data['role'])->where('is_active', true);
        if (! empty($data['search'])) {
            $query->where('display_name', 'like', '%'.$data['search'].'%');
        }
        if ($data['role'] === 'teacher') {
            $query->where('role_locked', true);
        }

        return response()->json($query->select('id', 'display_name', 'role')->orderBy('display_name')->paginate(50));
    }

    public function contacts(Request $r, int $group)
    {
        $class = $this->access->group($group);
        $input = $r->validate(['offering_id' => ['sometimes', 'integer']]);
        if (isset($input['offering_id'])) {
            $offering = $this->access->offering($r->user(), $input['offering_id']);
            abort_unless($offering->classroom_id === $group, 403);
        } else {
            abort_unless($this->access->canView($r->user(), $class), 403);
        }
        $ids = DB::table('school_enrollments')->where('classroom_id', $group)->whereNotNull('active_student_id')->limit(5000)->pluck('student_id')->all();
        $staff = DB::table('teaching_assignments')->whereNotNull('active_teacher_id')->whereNull('ends_at')->whereIn('offering_id', DB::table('module_offerings')->where('classroom_id', $group)->select('id'))->limit(5000)->pluck('teacher_id')->all();
        if ($r->user()->isStudent() && ! $this->access->delegate($r->user(), $class)) {
            $ids = DB::table('class_delegates')->where('classroom_id', $group)->whereNotNull('active_slot')->whereNull('revoked_at')->where('ends_at', '>', now())->pluck('student_id')->all();
            if (! $this->access->enrolled($r->user(), $class)) {
                $ids = [];
            }
        }
        if (isset($input['offering_id'])) {
            $staff = DB::table('teaching_assignments')->where('offering_id', $input['offering_id'])->whereNotNull('active_teacher_id')->whereNull('ends_at')->pluck('teacher_id')->all();
        }
        if ($this->access->delegate($r->user(), $class)) {
            $staff = array_merge($staff, User::where('role', 'admin')->where('is_active', true)->pluck('id')->all());
        }

        return response()->json(['data' => User::whereIn('id', array_unique(array_merge($ids, $staff)))->where('id', '!=', $r->user()->id)->where('is_active', true)->select('id', 'display_name', 'role')->orderBy('display_name')->limit(200)->get()]);
    }

    public function grantModule(Request $r, int $offering)
    {
        $this->access->admin($r->user());
        $o = DB::table('module_offerings')->find($offering);
        abort_unless($o, 404);
        $this->access->writable($this->access->group($o->classroom_id));
        $data = $r->validate(['student_id' => ['required', 'integer', 'exists:users,id'], 'reason' => ['required', 'string', 'min:3', 'max:1000'], 'expires_at' => ['required', 'date', 'after:now']]);
        $student = User::findOrFail($data['student_id']);
        abort_unless($student->isStudent() && $student->is_active, 422);
        DB::table('module_access_grants')->updateOrInsert(['offering_id' => $offering, 'student_id' => $student->id], ['granted_by' => $r->user()->id, 'reason' => $data['reason'], 'expires_at' => $data['expires_at'], 'revoked_at' => null, 'created_at' => now(), 'updated_at' => now()]);
        Classroom::whereKey($o->classroom_id)->increment('roster_version');
        AuditLog::record($r->user(), 'school.module_access.grant', ['offering_id' => $offering, 'student_id' => $student->id]);

        return response()->noContent();
    }

    /** Named, admin-only access management; never expose a student directory to peers. */
    public function moduleGrants(Request $r, int $offering)
    {
        $this->access->admin($r->user());
        abort_unless(DB::table('module_offerings')->where('id', $offering)->exists(), 404);

        return response()->json(['data' => DB::table('module_access_grants as g')->join('users as u', 'u.id', '=', 'g.student_id')
            ->where('g.offering_id', $offering)->whereNull('g.revoked_at')->where('g.expires_at', '>', now())
            ->select('g.student_id', 'u.display_name', 'g.reason', 'g.expires_at')->orderBy('u.display_name')->get()]);
    }

    /** Reuse opt-in partner matching for accepted official enrollment, not module-only access. */
    public function partners(Request $r, int $group)
    {
        $class = $this->access->group($group);
        abort_unless($r->user()->isStudent() && $this->access->enrolled($r->user(), $class), 403);

        return app(PartnerController::class)->candidates($r, $class);
    }

    public function requestPartner(Request $r, int $group)
    {
        $class = $this->access->group($group);
        $this->access->writable($class);
        abort_unless($this->access->enrolled($r->user(), $class), 403);
        $r->merge(['classroom_id' => $group]); // The authorized route context is canonical.

        return app(PartnerController::class)->request($r);
    }

    public function respondPartner(Request $r, int $partner)
    {
        $record = PartnerRequest::findOrFail($partner);
        $class = $this->access->group($record->classroom_id);
        $this->access->writable($class);

        return app(PartnerController::class)->respond($r, $record);
    }

    public function revokeModule(Request $r, int $offering, int $student)
    {
        $this->access->admin($r->user());
        DB::table('module_access_grants')->where('offering_id', $offering)->where('student_id', $student)->update(['revoked_at' => now(), 'updated_at' => now()]);
        if ($o = DB::table('module_offerings')->find($offering)) {
            Classroom::whereKey($o->classroom_id)->increment('roster_version');
        }
        AuditLog::record($r->user(), 'school.module_access.revoke', ['offering_id' => $offering, 'student_id' => $student]);

        return response()->noContent();
    }

    public function assignmentRequests(Request $r)
    {
        abort_unless($r->user()->isAdmin() || $r->user()->isTeacher(), 403);
        $q = DB::table('school_assignment_requests as a')->join('users as u', 'u.id', '=', 'a.teacher_id')->leftJoin('module_offerings as o', 'o.id', '=', 'a.offering_id')->leftJoin('school_modules as m', 'm.id', '=', 'o.module_id')->leftJoin('classrooms as c', 'c.id', '=', 'o.classroom_id')
            ->select('a.id', 'a.teacher_id', 'a.offering_id', 'a.status', 'a.reason', 'a.requested_group_code', 'a.requested_module_code', 'a.requested_year', 'u.display_name', 'm.name as module_name', 'c.name as classroom_name');
        if (! $r->user()->isAdmin()) {
            $q->where('a.teacher_id', $r->user()->id);
        }

        return response()->json($q->orderByDesc('a.id')->paginate(30));
    }

    public function archiveYear(Request $r, int $year)
    {
        $this->access->admin($r->user());
        DB::transaction(function () use ($r, $year) {
            $record = DB::table('academic_years')->where('id', $year)->lockForUpdate()->first();
            abort_unless($record, 404);
            DB::table('academic_years')->where('id', $year)->update(['status' => 'archived', 'updated_at' => now()]);
            $groups = Classroom::where('academic_year_id', $year)->where('is_official', true)->orderBy('id')->lockForUpdate()->get();
            foreach ($groups as $group) {
                $group->forceFill(['status' => 'archived', 'join_enabled' => false, 'archived_at' => now()])->save();
            }
            DB::table('class_delegates')->whereIn('classroom_id', $groups->pluck('id'))->whereNotNull('active_slot')->update(['active_slot' => null, 'revoked_at' => now(), 'updated_at' => now()]);
            AuditLog::record($r->user(), 'school.year.archive', ['year_id' => $year]);
        });

        return response()->noContent();
    }

    public function setupRequest(Request $r)
    {
        abort_unless($r->user()->isTeacher(), 403);
        $data = $r->validate(['requested_group_code' => ['required', 'string', 'max:64'], 'requested_module_code' => ['required', 'string', 'max:64'], 'requested_year' => ['required', 'string', 'max:32'], 'reason' => ['required', 'string', 'max:500']]);
        $key = hash('sha256', $r->user()->id.'|'.$data['requested_group_code'].'|'.$data['requested_module_code'].'|'.$data['requested_year']);
        abort_if(DB::table('school_assignment_requests')->where('request_key', $key)->exists(), 409, __('api.school.request_exists'));
        $id = DB::table('school_assignment_requests')->insertGetId($data + ['request_key' => $key, 'teacher_id' => $r->user()->id, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        AuditLog::record($r->user(), 'school.setup.request', ['request_id' => $id]);
        // A setup request carries no offering yet: the context is the codes typed by the teacher.
        $recipients = User::where('is_active', true)->where('role', 'admin')->get();
        $teacherName = $r->user()->display_name;
        DB::afterCommit(fn () => app(NotificationService::class)->notifyMany($recipients, NotificationService::ASSIGNMENT_REQUEST_RECEIVED, [
            'request_id' => $id, 'url' => '/app/school', 'teacher_name' => $teacherName,
            'classroom_name' => $data['requested_group_code'], 'module_name' => $data['requested_module_code'],
        ]));

        return response()->json(['id' => $id], 201);
    }

    public function resolveRequest(Request $r, int $assignment)
    {
        $this->access->admin($r->user());
        $data = $r->validate(['offering_id' => ['required', 'integer', 'exists:module_offerings,id']]);
        DB::transaction(function () use ($r, $assignment, $data) {
            $request = DB::table('school_assignment_requests')->where('id', $assignment)->lockForUpdate()->first();
            abort_unless($request && $request->status === 'pending', 409);
            $teacher = User::findOrFail($request->teacher_id);
            $this->setup->assign($r->user(), $data['offering_id'], $teacher);
            DB::table('school_assignment_requests')->where('id', $assignment)->update(['status' => 'approved', 'updated_at' => now()]);
            AuditLog::record($r->user(), 'school.setup.request_resolved', ['request_id' => $assignment, 'offering_id' => $data['offering_id']]);
        });

        return response()->noContent();
    }

    public function updateGroup(Request $r, int $group)
    {
        $this->access->admin($r->user());
        $class = $this->access->group($group);
        $data = $r->validate(['name' => ['sometimes', 'required', 'string', 'max:255'], 'filiere' => ['sometimes', 'required', 'string', 'max:191'], 'level' => ['sometimes', 'required', 'string', 'max:64'], 'delegate_notices_enabled' => ['sometimes', 'boolean']]);
        $class->forceFill($data)->save();
        AuditLog::record($r->user(), 'school.group.update', ['classroom_id' => $group, 'fields' => array_keys($data)]);

        return response()->noContent();
    }

    public function invitation(Request $r, int $group)
    {
        $this->access->admin($r->user());
        $class = $this->access->group($group);
        $this->access->writable($class);
        $data = $r->validate(['enabled' => ['required', 'boolean'], 'regenerate' => ['sometimes', 'boolean']]);
        if ($data['regenerate'] ?? false) {
            app(JoinCodeService::class)->regenerate($class);
        }
        $class->forceFill(['join_enabled' => $data['enabled']])->save();
        AuditLog::record($r->user(), 'school.invitation.update', ['classroom_id' => $group, 'enabled' => $data['enabled']]);

        return response()->json(['join_code' => $class->fresh()->join_code, 'join_enabled' => $data['enabled']]);
    }

    public function deadlines(Request $r)
    {
        abort_unless($r->user()->isStudent(), 403);
        $student = $r->user();
        $offerings = DB::table('module_offerings as o')->join('classrooms as c', 'c.id', '=', 'o.classroom_id')->join('academic_years as y', 'y.id', '=', 'c.academic_year_id')
            ->where('c.status', 'active')->where('y.status', '!=', 'archived')->where('y.ends_on', '>=', now('Africa/Casablanca')->toDateString())
            ->where(fn ($q) => $q->whereIn('c.id', DB::table('school_enrollments')->where('student_id', $student->id)->whereNotNull('active_student_id')->select('classroom_id'))
                ->orWhereIn('o.id', DB::table('module_access_grants')->where('student_id', $student->id)->whereNull('revoked_at')->where('expires_at', '>', now())->select('offering_id')))->pluck('o.id');
        $data = [];
        $assignments = DB::table('assignments')->whereIn('offering_id', $offerings)->where('publication_status', 'published')->whereNotNull('due_at')
            ->whereNotIn('id', DB::table('submissions')->where('student_id', $student->id)->select('assignment_id'))->orderBy('due_at')->limit(100)->get();
        foreach ($assignments as $a) {
            $data[] = ['kind' => 'assignment', 'id' => $a->id, 'title' => $a->title, 'due_at' => $a->due_at, 'url' => '/app/assignments/'.$a->id];
        }
        $quizzes = DB::table('quizzes')->whereIn('offering_id', $offerings)->where('status', 'published')->where('due_at', '>=', now())
            ->whereNotIn('id', DB::table('attempts')->where('student_id', $student->id)->whereNotNull('submitted_at')->select('quiz_id'))->orderBy('due_at')->limit(100)->get();
        foreach ($quizzes as $q) {
            $data[] = ['kind' => 'quiz', 'id' => $q->id, 'title' => $q->title, 'due_at' => $q->due_at, 'url' => '/app/classes/'.$q->classroom_id.'/quizzes/'.$q->id];
        }
        // Assessment schedule metadata is visible; draft grade entries never are.
        foreach (DB::table('official_assessments')->whereIn('offering_id', $offerings)->where('assessed_on', '>=', now('Africa/Casablanca')->toDateString())->whereIn('id', DB::table('assessment_candidates')->where('student_id', $student->id)->select('assessment_id'))->orderBy('assessed_on')->limit(100)->get() as $a) {
            $data[] = ['kind' => 'assessment', 'id' => $a->id, 'title' => $a->title, 'due_at' => $a->assessed_on, 'url' => '/app/school/offerings/'.$a->offering_id];
        }
        usort($data, fn ($a, $b) => strcmp($a['due_at'], $b['due_at']));

        return response()->json(['data' => $data])->header('Cache-Control', 'private, no-store');
    }
}
