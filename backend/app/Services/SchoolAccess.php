<?php

namespace App\Services;

use App\Models\Classroom;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class SchoolAccess
{
    public function group(int $id): Classroom
    {
        return Classroom::where('is_official', true)->findOrFail($id);
    }

    public function assertActive(User $user): void
    {
        abort_unless($user->fresh()?->canAccessApp(), 403);
    }

    public function admin(User $user): void
    {
        $this->assertActive($user);
        abort_unless($user->fresh()?->isAdmin(), 403);
    }

    public function writable(Classroom $group): void
    {
        if (DB::transactionLevel() > 0) {
            $group = Classroom::whereKey($group->id)->lockForUpdate()->firstOrFail();
        } else {
            $group = $group->fresh();
        }
        abort_if($this->readOnly($group), 409, __('api.school.archived'));
    }

    public function readOnly(Classroom $group): bool
    {
        $year = DB::table('academic_years')->find($group->academic_year_id);

        return $group->isReadOnly() || ! $year || $year->status === 'archived' || $year->ends_on < now('Africa/Casablanca')->toDateString();
    }

    public function teaches(User $user, int $offeringId): bool
    {
        return $user->is_active && $user->isTeacher()
            && DB::table('teaching_assignments')->where('offering_id', $offeringId)
                ->where('teacher_id', $user->id)->whereNotNull('active_teacher_id')
                ->where('starts_at', '<=', now())->whereNull('ends_at')->exists();
    }

    public function enrolled(User $user, Classroom $group): bool
    {
        return $user->is_active && $user->isStudent()
            && DB::table('school_enrollments')->where('classroom_id', $group->id)
                ->where('student_id', $user->id)->whereNotNull('active_student_id')->whereNull('ends_at')->exists();
    }

    public function exceptional(User $user, int $offering): bool
    {
        return $user->isStudent() && $user->is_active && DB::table('module_access_grants')->where('offering_id', $offering)->where('student_id', $user->id)->whereNull('revoked_at')->where('expires_at', '>', now())->exists();
    }

    public function canView(User $user, Classroom $group): bool
    {
        $scope = request()?->attributes->get('school_offering_id');
        if ($scope && DB::table('module_offerings')->where('id', $scope)->where('classroom_id', $group->id)->exists() && $this->exceptional($user, $scope)) {
            return true;
        }

        return $user->canAccessApp() && ($user->isAdmin() || $this->enrolled($user, $group)
            || ($user->isTeacher() && $group->coordinator_id === $user->id)
            || ($user->isTeacher() && DB::table('module_offerings')->where('classroom_id', $group->id)
                ->whereIn('id', DB::table('teaching_assignments')->where('teacher_id', $user->id)->whereNotNull('active_teacher_id')->whereNull('ends_at')->select('offering_id'))->exists()));
    }

    public function rosterManager(User $user, Classroom $group): bool
    {
        return $user->canAccessApp() && ($user->isAdmin()
            || ($user->isTeacher() && $group->coordinator_id === $user->id && $group->coordinator_can_manage_roster));
    }

    public function offering(User $user, int $id, bool $write = false): object
    {
        $this->assertActive($user);
        $offering = DB::table('module_offerings')->find($id);
        abort_unless($offering, 404);
        $group = $this->group($offering->classroom_id);
        if ($write) {
            $current = User::whereKey($user->id)->lockForUpdate()->first();
            abort_unless($current && $current->is_active && $current->isTeacher(), 403);
            $assignment = DB::table('teaching_assignments')->where('offering_id', $id)->where('teacher_id', $user->id)->whereNotNull('active_teacher_id')->whereNull('ends_at')->lockForUpdate()->first();
            abort_unless($assignment && $assignment->starts_at <= now()->toDateTimeString(), 403);
            $this->writable($group);
            $offering = DB::table('module_offerings')->where('id', $id)->lockForUpdate()->first();
            abort_unless($offering && $offering->status === 'active', 409);
        } else {
            abort_unless($this->teaches($user, $id) || $this->enrolled($user, $group) || $this->exceptional($user, $id), 403);
        }

        return $offering;
    }

    public function delegate(User $user, Classroom $group): bool
    {
        return $this->enrolled($user, $group) && DB::table('class_delegates')->where('classroom_id', $group->id)
            ->where('student_id', $user->id)->whereNotNull('active_slot')->whereNull('revoked_at')
            ->where('starts_at', '<=', now())->where('ends_at', '>', now())->exists();
    }
}
