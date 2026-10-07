<?php

namespace Tests\Feature;

use App\Jobs\DeliverSchoolNotifications;
use App\Services\SchoolSetupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\SchoolFixture;
use Tests\TestCase;

class SchoolCommunicationTest extends TestCase
{
    use RefreshDatabase, SchoolFixture;

    public function test_private_student_teacher_question_rejects_nonparticipants_and_suspension(): void
    {
        [$admin, $group, $teachers, $offerings, $students] = $this->schoolFixture();
        $id = $this->asToken($this->tokenFor($students[0]))->postJson('/api/school/threads', ['classroom_id' => $group->id, 'offering_id' => $offerings[0], 'kind' => 'private_question', 'subject' => 'Synthetic question', 'body' => 'Private synthetic message', 'participant_ids' => [$teachers[0]->id]])->assertCreated()->json('id');
        foreach ([$admin, $teachers[1], $students[1]] as $outsider) {
            $this->asToken($this->tokenFor($outsider))->getJson("/api/school/threads/$id")->assertNotFound();
            $this->postJson("/api/school/threads/$id/messages", ['body' => 'Must not be sent'])->assertNotFound();
        }
        $this->asToken($this->tokenFor($teachers[0]))->getJson("/api/school/threads/$id")->assertOk()->assertJsonPath('messages.0.body', 'Private synthetic message');
        $this->getJson('/api/school/threads')->assertOk()->assertJsonPath('data.0.id', $id);
        $teachers[0]->update(['is_active' => false]);
        $this->asToken($this->tokenFor($teachers[0]))->getJson("/api/school/threads/$id")->assertForbidden();
        (new DeliverSchoolNotifications)->handle();
        $this->assertStringNotContainsString('Private synthetic message', DB::table('notifications')->pluck('payload')->implode(''));
    }

    public function test_delegate_replacement_never_inherits_previous_private_threads(): void
    {
        [$admin, $group, $teachers, , $students] = $this->schoolFixture();
        $setup = app(SchoolSetupService::class);
        $delegateId = $setup->appoint($admin, $group, $students[0], now()->addMonth()->toIso8601String());
        $id = $this->asToken($this->tokenFor($students[1]))->postJson('/api/school/threads', ['classroom_id' => $group->id, 'kind' => 'delegate_contact', 'subject' => 'Synthetic class issue', 'body' => 'Private source message', 'participant_ids' => [$students[0]->id]])->assertCreated()->json('id');
        $this->asToken($this->tokenFor($admin))->deleteJson("/api/school/delegates/$delegateId")->assertNoContent();
        $replacement = $this->student();
        $setup->enroll($admin, $group, $replacement);
        $setup->appoint($admin, $group, $replacement, now()->addMonth()->toIso8601String());
        $this->asToken($this->tokenFor($replacement))->getJson("/api/school/threads/$id")->assertNotFound();
        $this->asToken($this->tokenFor($students[0]))->getJson("/api/school/threads/$id")->assertNotFound();
        $this->getJson('/api/school/threads')->assertOk()->assertJsonCount(0, 'data');
        $this->asToken($this->tokenFor($students[1]))->getJson("/api/school/threads/$id")->assertOk();
    }

    public function test_unrelated_contacts_and_teacher_replacement_fail_closed(): void
    {
        [$admin, $group, $teachers, $offerings, $students] = $this->schoolFixture();
        $outsider = $this->teacher(['role_locked' => true]);
        $payload = ['classroom_id' => $group->id, 'offering_id' => $offerings[0], 'kind' => 'private_question', 'subject' => 'Synthetic', 'body' => 'Synthetic body', 'participant_ids' => [$outsider->id]];
        $this->asToken($this->tokenFor($students[0]))->postJson('/api/school/threads', $payload)->assertForbidden();
        $payload['participant_ids'] = [$teachers[0]->id];
        $id = $this->postJson('/api/school/threads', $payload)->assertCreated()->json('id');
        $assignment = DB::table('teaching_assignments')->where('offering_id', $offerings[0])->first();
        app(SchoolSetupService::class)->revokeAssignment($admin, $assignment->id);
        app(SchoolSetupService::class)->assign($admin, $offerings[0], $outsider);
        $this->asToken($this->tokenFor($teachers[0]))->getJson("/api/school/threads/$id")->assertNotFound();
        $this->asToken($this->tokenFor($outsider))->getJson("/api/school/threads/$id")->assertNotFound();
        $this->asToken($this->tokenFor($students[0]))->postJson('/api/school/threads', $payload + ['attachments' => ['not supported']])->assertUnprocessable();
    }
}
