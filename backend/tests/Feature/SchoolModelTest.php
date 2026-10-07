<?php

namespace Tests\Feature;

use App\Models\Classroom;
use App\Models\Membership;
use App\Models\User;
use App\Services\SchoolSetupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SchoolModelTest extends TestCase
{
    use RefreshDatabase;

    private function school(): array
    {
        $admin = $this->admin();
        $year = DB::table('academic_years')->insertGetId(['name' => 'Synthetic year', 'starts_on' => now()->subMonth()->toDateString(), 'ends_on' => now()->addYear()->toDateString(), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $group = app(SchoolSetupService::class)->createGroup($admin, ['academic_year_id' => $year, 'official_code' => 'SYN-1', 'name' => 'Synthetic group', 'filiere' => 'Synthetic stream', 'level' => '2']);
        $module = DB::table('school_modules')->insertGetId(['code' => 'SYN-M', 'name' => 'Synthetic module', 'created_at' => now(), 'updated_at' => now()]);
        $offering = DB::table('module_offerings')->insertGetId(['classroom_id' => $group->id, 'module_id' => $module, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        return [$admin, $group, $offering];
    }

    public function test_admin_can_configure_more_than_initial_six_groups_and_ten_teachers(): void
    {
        [$admin, $group, $offering] = $this->school();
        for ($i = 2; $i <= 7; $i++) {
            $this->asToken($this->tokenFor($admin))->postJson('/api/school/groups', ['academic_year_id' => $group->academic_year_id, 'official_code' => 'SYN-'.$i, 'name' => 'Synthetic group '.$i, 'filiere' => 'Synthetic', 'level' => '2'])->assertCreated();
        }
        for ($i = 0; $i < 11; $i++) {
            $teacher = $this->teacher(['role_locked' => true]);
            $this->asToken($this->tokenFor($admin))->postJson("/api/school/offerings/$offering/teachers", ['teacher_id' => $teacher->id])->assertCreated();
        }
        $this->assertDatabaseCount('teaching_assignments', 11);
        $this->assertSame(7, Classroom::where('is_official', true)->count());
        $this->assertNull($group->teacher_id);
    }

    public function test_two_teachers_share_a_roster_without_roster_edit_permission_or_self_assignment(): void
    {
        [$admin, $group, $offering] = $this->school();
        $teacher = $this->teacher(['role_locked' => true]);
        $second = $this->teacher(['role_locked' => true]);
        $student = $this->student();
        $setup = app(SchoolSetupService::class);
        $setup->assign($admin, $offering, $teacher);
        $setup->assign($admin, $offering, $second);
        $setup->enroll($admin, $group, $student);
        foreach ([$teacher, $second] as $actor) {
            $this->asToken($this->tokenFor($actor))->getJson("/api/school/groups/{$group->id}/roster")->assertOk()->assertJsonPath('data.0.student_id', $student->id);
            $this->postJson("/api/school/groups/{$group->id}/enrollments", ['student_id' => $student->id])->assertForbidden();
            $this->postJson("/api/school/offerings/$offering/teachers", ['teacher_id' => $actor->id])->assertForbidden();
        }
        $this->assertDatabaseCount('school_enrollments', 1);
        $this->assertDatabaseCount('memberships', 1);
        $this->asToken($this->tokenFor($this->teacher()))->getJson("/api/school/groups/{$group->id}/roster")->assertForbidden();
    }

    public function test_coordinator_permission_is_explicit_and_delegate_limit_is_serialized(): void
    {
        [$admin, $group, $offering] = $this->school();
        $teacher = $this->teacher(['role_locked' => true]);
        $this->asToken($this->tokenFor($admin))->putJson("/api/school/groups/{$group->id}/coordinator", ['coordinator_id' => $teacher->id, 'coordinator_can_manage_roster' => true])->assertNoContent();
        $students = User::factory()->count(3)->create();
        foreach ($students as $student) {
            $this->asToken($this->tokenFor($teacher))->postJson("/api/school/groups/{$group->id}/enrollments", ['student_id' => $student->id])->assertNoContent();
        }
        foreach ($students as $index => $student) {
            $response = $this->asToken($this->tokenFor($admin))->postJson("/api/school/groups/{$group->id}/delegates", ['student_id' => $student->id, 'ends_at' => now()->addMonth()->toIso8601String()]);
            $response->assertStatus($index < 2 ? 201 : 409);
        }
        $this->asToken($this->tokenFor($teacher))->postJson("/api/school/groups/{$group->id}/delegates", ['student_id' => $students[2]->id, 'ends_at' => now()->addMonth()->toIso8601String()])->assertForbidden();
        $this->assertSame(2, DB::table('class_delegates')->whereNotNull('active_slot')->count());
    }

    public function test_transfer_preserves_history_and_ends_delegate_responsibility(): void
    {
        [$admin, $group] = $this->school();
        $setup = app(SchoolSetupService::class);
        $target = $setup->createGroup($admin, ['academic_year_id' => $group->academic_year_id, 'official_code' => 'SYN-2', 'name' => 'Synthetic target', 'filiere' => 'Synthetic', 'level' => '2']);
        $student = $this->student();
        $setup->enroll($admin, $group, $student);
        $setup->appoint($admin, $group, $student, now()->addMonth()->toIso8601String());
        $this->asToken($this->tokenFor($admin))->postJson("/api/school/groups/{$target->id}/enrollments", ['student_id' => $student->id])->assertConflict();
        $this->postJson("/api/school/groups/{$group->id}/enrollments/{$student->id}/transfer", ['target_group_id' => $target->id])->assertNoContent();
        $this->assertDatabaseCount('school_enrollments', 2);
        $this->assertSame(1, DB::table('school_enrollments')->whereNotNull('active_student_id')->count());
        $this->assertSame(0, DB::table('class_delegates')->whereNotNull('active_slot')->count());
        $this->assertDatabaseHas('memberships', ['classroom_id' => $group->id, 'student_id' => $student->id, 'status' => 'removed']);
        $this->asToken($this->tokenFor($student))->getJson("/api/school/groups/{$group->id}/roster")->assertForbidden();
    }

    public function test_invitation_only_creates_pending_membership_and_module_teachers_cannot_decide(): void
    {
        [$admin, $group, $offering] = $this->school();
        $teacher = $this->teacher(['role_locked' => true]);
        app(SchoolSetupService::class)->assign($admin, $offering, $teacher);
        $this->asToken($this->tokenFor($admin))->postJson("/api/school/groups/{$group->id}/activate")->assertOk();
        $student = $this->student();
        $this->asToken($this->tokenFor($student))->postJson('/api/join-requests', ['code' => $group->join_code])->assertCreated();
        $membership = Membership::firstOrFail();
        $this->assertSame('pending', $membership->status);
        $this->assertDatabaseCount('school_enrollments', 0);
        $this->asToken($this->tokenFor($teacher))->postJson("/api/school/memberships/{$membership->id}/decision", ['decision' => 'accepted'])->assertForbidden();
        $this->asToken($this->tokenFor($admin))->postJson("/api/school/memberships/{$membership->id}/decision", ['decision' => 'accepted'])->assertNoContent();
        $this->assertDatabaseCount('school_enrollments', 1);
    }

    public function test_legacy_owner_endpoints_cannot_access_official_groups_and_archive_blocks_writes(): void
    {
        [$admin, $group, $offering] = $this->school();
        $teacher = $this->teacher(['role_locked' => true]);
        app(SchoolSetupService::class)->assign($admin, $offering, $teacher);
        $this->asToken($this->tokenFor($teacher))->getJson("/api/classes/{$group->id}/materials")->assertForbidden();
        $this->asToken($this->tokenFor($admin))->postJson("/api/school/groups/{$group->id}/archive")->assertNoContent();
        $this->postJson("/api/school/groups/{$group->id}/enrollments", ['student_id' => $this->student()->id])->assertConflict();
        $this->assertDatabaseHas('classrooms', ['id' => $group->id, 'status' => 'archived']);
    }
}
