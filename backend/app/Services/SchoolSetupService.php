<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Classroom;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class SchoolSetupService
{
    public function __construct(private SchoolAccess $access, private JoinCodeService $codes) {}

    public function groups(User $actor)
    {
        $query = Classroom::where('is_official', true);
        if (! $actor->isAdmin()) {
            $query->where(function ($q) use ($actor) {
                if ($actor->isTeacher()) {
                    $q->whereIn('id', DB::table('module_offerings')->whereIn('id', DB::table('teaching_assignments')->where('teacher_id', $actor->id)->whereNotNull('active_teacher_id')->whereNull('ends_at')->select('offering_id'))->select('classroom_id'));
                    $q->orWhere('coordinator_id', $actor->id);
                } else {
                    $q->whereIn('id', DB::table('school_enrollments')->where('student_id', $actor->id)->whereNotNull('active_student_id')->select('classroom_id'))
                        ->orWhereIn('id', DB::table('module_offerings')->whereIn('id', DB::table('module_access_grants')->where('student_id', $actor->id)->whereNull('revoked_at')->where('expires_at', '>', now())->select('offering_id'))->select('classroom_id'));
                }
            });
        }

        return $query->select(['id', 'name', 'official_code', 'filiere', 'level', 'school_year', 'academic_year_id', 'status', 'join_enabled', 'delegate_notices_enabled', 'roster_version', 'coordinator_id', 'coordinator_can_manage_roster'])->orderBy('official_code');
    }

    public function requestEnrollment(User $student, Classroom $group): Membership
    {
        $this->access->assertActive($student);
        abort_unless($student->isStudent() && $group->join_enabled, 403);
        $this->access->writable($group);

        return DB::transaction(function () use ($student, $group) {
            User::whereKey($student->id)->lockForUpdate()->firstOrFail();
            $membership = Membership::where('classroom_id', $group->id)->where('student_id', $student->id)->first();
            abort_if($membership && in_array($membership->status, ['pending', 'accepted']), 409);
            abort_if($membership && $membership->isInRejectionCooldown(), 429);
            abort_if(DB::table('school_enrollments')->where('academic_year_id', $group->academic_year_id)->where('active_student_id', $student->id)->exists(), 409, __('api.school.primary_group'));
            $membership = Membership::updateOrCreate(['classroom_id' => $group->id, 'student_id' => $student->id], ['status' => 'pending', 'requested_at' => now(), 'decided_at' => null, 'decided_by' => null]);
            DB::afterCommit(function () use ($group, $membership) {
                $recipients = User::where('is_active', true)->where('role', 'admin')->get();
                if ($group->coordinator_can_manage_roster && $group->coordinator_id) {
                    $coordinator = User::find($group->coordinator_id);
                    if ($coordinator?->is_active) {
                        $recipients->push($coordinator);
                    }
                }
                app(NotificationService::class)->notifyMany($recipients->unique('id'), NotificationService::JOIN_REQUESTED, ['membership_id' => $membership->id, 'classroom_id' => $group->id]);
            });

            return $membership;
        });
    }

    public function createGroup(User $actor, array $data): Classroom
    {
        $this->access->admin($actor);
        $year = DB::table('academic_years')->find($data['academic_year_id']);
        abort_unless($year && $year->status !== 'archived', 422);
        $group = new Classroom;
        $group->forceFill($data + [
            'is_official' => true, 'teacher_id' => null, 'subject' => 'Modules',
            'group_label' => $data['official_code'], 'school_year' => $year->name,
            'join_code' => $this->codes->generate(), 'join_enabled' => false, 'status' => 'active',
        ])->save();
        AuditLog::record($actor, 'school.group.create', ['classroom_id' => $group->id]);

        return $group;
    }

    public function assign(User $actor, int $offeringId, User $teacher): int
    {
        $this->access->admin($actor);
        abort_unless($teacher->isTeacher() && $teacher->is_active && $teacher->role_locked, 422, __('api.school.verified_teacher'));

        return DB::transaction(function () use ($actor, $offeringId, $teacher) {
            $offering = DB::table('module_offerings')->where('id', $offeringId)->lockForUpdate()->first();
            abort_unless($offering, 404);
            $this->access->writable($this->access->group($offering->classroom_id));
            abort_if(DB::table('teaching_assignments')->where('offering_id', $offeringId)->where('active_teacher_id', $teacher->id)->exists(), 409);
            $id = DB::table('teaching_assignments')->insertGetId([
                'offering_id' => $offeringId, 'teacher_id' => $teacher->id,
                'active_teacher_id' => $teacher->id, 'starts_at' => now(), 'assigned_by' => $actor->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            AuditLog::record($actor, 'school.assignment.create', ['assignment_id' => $id, 'teacher_id' => $teacher->id, 'offering_id' => $offeringId]);
            DB::table('school_assignment_requests')->where('offering_id', $offeringId)->where('teacher_id', $teacher->id)->update(['status' => 'approved', 'updated_at' => now()]);
            DB::afterCommit(fn () => $this->notifyTeachingChange($teacher, $offeringId, 'assigned'));

            return $id;
        });
    }

    public function revokeAssignment(User $actor, int $id): void
    {
        $this->access->admin($actor);
        DB::transaction(function () use ($actor, $id) {
            $row = DB::table('teaching_assignments')->where('id', $id)->lockForUpdate()->first();
            abort_unless($row, 404);
            DB::table('teaching_assignments')->where('id', $id)->update(['active_teacher_id' => null, 'ends_at' => now(), 'updated_at' => now()]);
            AuditLog::record($actor, 'school.assignment.revoke', ['assignment_id' => $id]);
            DB::afterCommit(fn () => $this->notifyTeachingChange(User::find($row->teacher_id), (int) $row->offering_id, 'revoked'));
        });
    }

    /**
     * Notify the concerned teacher about a teaching assignment change.
     * The payload stays link-safe: only `classroom_id`/names, never a grade.
     */
    private function notifyTeachingChange(?User $teacher, int $offering, string $action): void
    {
        if (! $teacher || ! $teacher->is_active) {
            return;
        }
        $offering = DB::table('module_offerings as o')->join('classrooms as c', 'c.id', '=', 'o.classroom_id')->join('school_modules as m', 'm.id', '=', 'o.module_id')
            ->where('o.id', $offering)->select('o.classroom_id', 'c.name as class_name', 'm.name as module_name')->first();
        if (! $offering) {
            return;
        }
        app(NotificationService::class)->notify($teacher, NotificationService::TEACHING_ASSIGNMENT_CHANGED, [
            'action' => $action, 'url' => '/app/school', 'classroom_id' => $offering->classroom_id,
            'classroom_name' => $offering->class_name, 'module_name' => $offering->module_name,
        ]);
    }

    public function enroll(User $actor, Classroom $group, User $student): void
    {
        abort_unless($this->access->rosterManager($actor, $group), 403);
        $this->access->writable($group);
        abort_unless($student->isStudent() && $student->is_active, 422);
        DB::transaction(function () use ($actor, $group, $student) {
            User::whereKey($student->id)->lockForUpdate()->firstOrFail();
            Classroom::whereKey($group->id)->lockForUpdate()->firstOrFail();
            $existing = DB::table('school_enrollments')->where('academic_year_id', $group->academic_year_id)->where('active_student_id', $student->id)->first();
            if ($existing) {
                abort_unless($existing->classroom_id === $group->id, 409, __('api.school.primary_group'));

                return;
            }
            DB::table('school_enrollments')->insert([
                'academic_year_id' => $group->academic_year_id, 'classroom_id' => $group->id,
                'student_id' => $student->id, 'active_student_id' => $student->id,
                'starts_at' => now(), 'decided_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            Membership::updateOrCreate(['classroom_id' => $group->id, 'student_id' => $student->id], [
                'status' => 'accepted', 'requested_at' => now(), 'decided_at' => now(), 'decided_by' => $actor->id,
            ]);
            $group->increment('roster_version');
            AuditLog::record($actor, 'school.enrollment.accept', ['classroom_id' => $group->id, 'student_id' => $student->id]);
            DB::afterCommit(fn () => app(NotificationService::class)->notify($student, NotificationService::MEMBERSHIP_ACCEPTED, [
                'classroom_id' => $group->id, 'classroom_name' => $group->name, 'via' => 'roster',
            ]));
        });
    }

    public function leave(User $actor, Classroom $group, User $student): void
    {
        abort_unless($this->access->rosterManager($actor, $group), 403);
        $this->access->writable($group);
        DB::transaction(function () use ($actor, $group, $student) {
            User::whereKey($student->id)->lockForUpdate()->firstOrFail();
            Classroom::whereKey($group->id)->lockForUpdate()->firstOrFail();
            DB::table('school_enrollments')->where('classroom_id', $group->id)->where('active_student_id', $student->id)
                ->update(['active_student_id' => null, 'ends_at' => now(), 'updated_at' => now()]);
            Membership::where('classroom_id', $group->id)->where('student_id', $student->id)->update(['status' => 'removed', 'decided_at' => now(), 'decided_by' => $actor->id]);
            $wasDelegate = DB::table('class_delegates')->where('classroom_id', $group->id)->where('student_id', $student->id)->whereNotNull('active_slot')->exists();
            DB::table('class_delegates')->where('classroom_id', $group->id)->where('student_id', $student->id)->whereNotNull('active_slot')
                ->update(['active_slot' => null, 'revoked_at' => now(), 'updated_at' => now()]);
            $group->increment('roster_version');
            AuditLog::record($actor, 'school.enrollment.remove', ['classroom_id' => $group->id, 'student_id' => $student->id]);
            $notify = app(NotificationService::class);
            DB::afterCommit(fn () => $notify->notify($student, NotificationService::MEMBERSHIP_REMOVED, [
                'classroom_id' => $group->id, 'classroom_name' => $group->name,
            ]));
            if ($wasDelegate) {
                DB::afterCommit(fn () => $notify->notify($student, NotificationService::DELEGATE_CHANGED, [
                    'action' => 'revoked', 'classroom_id' => $group->id, 'classroom_name' => $group->name,
                ]));
            }
        });
    }

    public function transfer(User $actor, Classroom $from, Classroom $to, User $student): void
    {
        $this->access->admin($actor);
        abort_unless($from->academic_year_id === $to->academic_year_id && $from->id !== $to->id, 422);
        abort_unless($this->access->enrolled($student, $from), 409);
        DB::transaction(function () use ($actor, $from, $to, $student) {
            $this->leave($actor, $from, $student);
            $this->enroll($actor, $to, $student);
            AuditLog::record($actor, 'school.enrollment.transfer', ['student_id' => $student->id, 'from' => $from->id, 'to' => $to->id]);
        });
    }

    public function appoint(User $actor, Classroom $group, User $student, string $endsAt): int
    {
        $this->access->admin($actor);
        $this->access->writable($group);

        return DB::transaction(function () use ($actor, $group, $student, $endsAt) {
            Classroom::whereKey($group->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->access->enrolled($student, $group), 422);
            $yearEnd = DB::table('academic_years')->where('id', $group->academic_year_id)->value('ends_on');
            abort_unless(now()->parse($endsAt)->isFuture() && now()->parse($endsAt)->lte(now()->parse($yearEnd)->endOfDay()), 422);
            DB::table('class_delegates')->where('classroom_id', $group->id)->whereNotNull('active_slot')->where('ends_at', '<=', now())->update(['active_slot' => null, 'updated_at' => now()]);
            abort_if(DB::table('class_delegates')->where('classroom_id', $group->id)->where('student_id', $student->id)->whereNotNull('active_slot')->exists(), 409);
            $slots = DB::table('class_delegates')->where('classroom_id', $group->id)->whereNotNull('active_slot')->pluck('active_slot')->all();
            $slot = ! in_array(1, $slots) ? 1 : (! in_array(2, $slots) ? 2 : null);
            abort_unless($slot, 409, __('api.school.delegate_limit'));
            $id = DB::table('class_delegates')->insertGetId([
                'classroom_id' => $group->id, 'student_id' => $student->id, 'active_slot' => $slot,
                'starts_at' => now(), 'ends_at' => $endsAt, 'appointed_by' => $actor->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            AuditLog::record($actor, 'school.delegate.appoint', ['delegate_id' => $id, 'student_id' => $student->id, 'classroom_id' => $group->id]);
            DB::afterCommit(fn () => app(NotificationService::class)->notify($student, NotificationService::DELEGATE_CHANGED, [
                'action' => 'appointed', 'classroom_id' => $group->id, 'classroom_name' => $group->name, 'ends_at' => $endsAt,
            ]));

            return $id;
        });
    }
}
