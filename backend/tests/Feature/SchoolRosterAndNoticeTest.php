<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SchoolSetupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\SchoolFixture;
use Tests\TestCase;

class SchoolRosterAndNoticeTest extends TestCase
{
    use RefreshDatabase, SchoolFixture;

    public function test_authorized_roster_provisions_once_without_messages_and_rejects_conflicting_identities(): void
    {
        [$admin, $group, $teachers] = $this->schoolFixture();
        Mail::fake();
        $csv = "student_identifier;email;display_name\nSYN-PROVISION;synthetic.provisioned@ofppt-edu.ma;Synthetic Provisioned Student\n";
        $preview = $this->asToken($this->tokenFor($admin))->postJson("/api/school/groups/{$group->id}/roster-imports/preview", ['file' => UploadedFile::fake()->createWithContent('roster.csv', $csv)])->assertOk()->assertJsonCount(0, 'errors');
        $batch = $preview->json('batch_id');
        $this->postJson("/api/school/groups/{$group->id}/roster-imports/commit", ['batch_id' => $batch])->assertOk()->assertJsonPath('imported', 1);
        $this->postJson("/api/school/groups/{$group->id}/roster-imports/commit", ['batch_id' => $batch])->assertOk();
        $this->assertSame(1, User::where('school_identifier', 'SYN-PROVISION')->count());
        $this->assertDatabaseHas('users', ['email' => 'synthetic.provisioned@ofppt-edu.ma', 'role' => 'student', 'role_locked' => true]);
        $bad = $this->postJson("/api/school/groups/{$group->id}/roster-imports/preview", ['rows' => [['student_identifier' => 'SYN-PROVISION', 'email' => 'synthetic.conflict@ofppt-edu.ma', 'display_name' => 'Synthetic Conflict']]])->assertOk();
        $this->assertNotEmpty($bad->json('errors'));
        $this->postJson("/api/school/groups/{$group->id}/roster-imports/commit", ['batch_id' => $bad->json('batch_id')])->assertUnprocessable();
        $this->assertSame(0, User::where('email', 'synthetic.conflict@ofppt-edu.ma')->count());
        $this->asToken($this->tokenFor($teachers[0]))->postJson("/api/school/groups/{$group->id}/roster-imports/preview", ['file' => UploadedFile::fake()->createWithContent('roster.csv', $csv)])->assertForbidden();
        Mail::assertNothingSent();
    }

    public function test_announcements_preview_audience_are_read_only_and_retry_safe(): void
    {
        [$admin, $group, $teachers, , $students] = $this->schoolFixture();
        $preview = $this->asToken($this->tokenFor($teachers[0]))->postJson('/api/school/notices/preview', ['audience' => 'classes', 'group_ids' => [$group->id]])->assertOk();
        $this->assertSame(4, $preview->json('recipient_count'));
        $this->assertStringNotContainsString('@', $preview->getContent());
        $payload = ['preview_id' => $preview->json('preview_id'), 'subject' => 'Synthetic public notice', 'body' => 'Synthetic class information'];
        $id = $this->postJson('/api/school/notices/publish', $payload)->assertCreated()->json('thread_ids.0');
        $this->postJson('/api/school/notices/publish', $payload)->assertCreated()->assertJsonPath('thread_ids.0', $id);
        $this->assertDatabaseCount('school_messages', 1);
        $this->asToken($this->tokenFor($students[0]))->getJson("/api/school/threads/$id")->assertOk();
        $this->postJson("/api/school/threads/$id/messages", ['body' => 'Accidental private reply'])->assertForbidden();
        $this->asToken($this->tokenFor($teachers[0]))->postJson('/api/school/notices/preview', ['audience' => 'all'])->assertForbidden();
        $all = $this->asToken($this->tokenFor($admin))->postJson('/api/school/notices/preview', ['audience' => 'all'])->assertOk();
        $this->postJson('/api/school/notices/publish', ['preview_id' => $all->json('preview_id'), 'subject' => 'Synthetic school notice', 'body' => 'Synthetic global announcement'])->assertCreated();
    }

    public function test_notices_can_target_multiple_groups_with_a_union_audience(): void
    {
        [$admin, $group] = $this->schoolFixture();
        $setup = app(SchoolSetupService::class);
        $second = $setup->createGroup($admin, ['academic_year_id' => $group->academic_year_id, 'official_code' => 'SYN-2', 'name' => 'Synthetic second group', 'filiere' => 'Synthetic stream', 'level' => '2']);
        $module = DB::table('school_modules')->insertGetId(['code' => 'SYN-M2', 'name' => 'Synthetic module 2', 'created_at' => now(), 'updated_at' => now()]);
        $offering = DB::table('module_offerings')->insertGetId(['classroom_id' => $second->id, 'module_id' => $module, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $secondTeacher = $this->teacher(['role_locked' => true]);
        $setup->assign($admin, $offering, $secondTeacher);
        $secondStudent = User::factory()->create();
        $setup->enroll($admin, $second, $secondStudent);

        $uri = '/api/school/notices/preview';
        $preview = $this->asToken($this->tokenFor($admin))->postJson($uri, ['audience' => 'classes', 'group_ids' => [$group->id, $second->id]])->assertOk();
        // Groupe 1 : 2 stagiaires + 2 enseignants + 1 admin. Groupe 2 : 1 stagiaire + 1 enseignant + 1 admin.
        $this->assertSame(7, $preview->json('recipient_count'));
        $this->assertSame([
            ['classroom_id' => $group->id, 'recipient_count' => 5],
            ['classroom_id' => $second->id, 'recipient_count' => 3],
        ], $preview->json('breakdown'));

        $ids = $this->postJson('/api/school/notices/publish', ['preview_id' => $preview->json('preview_id'), 'subject' => 'Synthetic multi-group notice', 'body' => 'Synthetic union class information'])->assertCreated()->json('thread_ids');
        $this->assertCount(2, $ids);
        $this->assertDatabaseHas('school_threads', ['id' => $ids[1], 'classroom_id' => $second->id]);
        $this->assertDatabaseHas('school_thread_participants', ['thread_id' => $ids[1], 'user_id' => $secondStudent->id]);
        $this->assertDatabaseHas('school_thread_participants', ['thread_id' => $ids[1], 'user_id' => $secondTeacher->id]);

        // Précision : un enseignant du second groupe ne voit pas le premier → 403.
        $this->asToken($this->tokenFor($secondTeacher))->postJson($uri, ['audience' => 'classes', 'group_ids' => [$group->id, $second->id]])->assertForbidden();
    }

    public function test_module_exception_does_not_grant_another_module_or_group_roster_access(): void
    {
        [$admin, $group, , $offerings] = $this->schoolFixture();
        $student = $this->student();
        $this->asToken($this->tokenFor($admin))->postJson("/api/school/offerings/{$offerings[0]}/access-grants", ['student_id' => $student->id, 'reason' => 'Synthetic exceptional access', 'expires_at' => now()->addWeek()->toIso8601String()])->assertNoContent();
        $this->asToken($this->tokenFor($student))->getJson('/api/school')->assertOk()->assertJsonCount(1, 'offerings');
        $this->getJson("/api/school/offerings/{$offerings[0]}/tools/materials")->assertOk();
        $this->getJson("/api/school/offerings/{$offerings[1]}/tools/materials")->assertForbidden();
        $this->getJson("/api/school/groups/{$group->id}/roster")->assertForbidden();
        $this->asToken($this->tokenFor($admin))->deleteJson("/api/school/offerings/{$offerings[0]}/access-grants/{$student->id}")->assertNoContent();
        $this->asToken($this->tokenFor($student))->getJson("/api/school/offerings/{$offerings[0]}/tools/materials")->assertForbidden();
    }
}
