<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\SchoolFixture;
use Tests\TestCase;

class SchoolReportPrivacyTest extends TestCase
{
    use RefreshDatabase, SchoolFixture;

    public function test_report_is_visible_only_to_reporter_and_named_admin_without_private_history(): void
    {
        [$admin, $group, $teachers, $offerings, $students] = $this->schoolFixture();
        $thread = $this->asToken($this->tokenFor($students[0]))->postJson('/api/school/threads', ['classroom_id' => $group->id, 'offering_id' => $offerings[0], 'kind' => 'private_question', 'participant_ids' => [$teachers[0]->id], 'subject' => 'Synthetic private topic', 'body' => 'Synthetic private history must not be copied'])->assertCreated()->json('id');
        $report = $this->postJson("/api/school/threads/$thread/report", ['assigned_to' => $admin->id, 'reason' => 'Synthetic summary written by reporter'])->assertCreated()->json('id');
        $this->asToken($this->tokenFor($admin))->getJson('/api/school/reports')->assertOk()->assertJsonPath('data.0.id', $report)->assertDontSee('Synthetic private history must not be copied');
        $this->getJson("/api/school/threads/$thread")->assertNotFound();
        $this->patchJson("/api/school/reports/$report", ['response' => 'Synthetic support response', 'status' => 'resolved'])->assertNoContent();
        $this->asToken($this->tokenFor($students[1]))->getJson('/api/school/reports')->assertOk()->assertJsonCount(0, 'data');
        $this->asToken($this->tokenFor($students[0]))->getJson('/api/school/reports')->assertJsonPath('data.0.response', 'Synthetic support response');
        $this->assertDatabaseHas('audit_logs', ['action' => 'school.report.respond']);
    }
}
