<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\SchoolFixture;
use Tests\TestCase;

class SchoolSetupRequestTest extends TestCase
{
    use RefreshDatabase, SchoolFixture;

    public function test_missing_space_report_never_grants_access_until_admin_explicitly_assigns(): void
    {
        [$admin, $group, , $offerings] = $this->schoolFixture();
        $teacher = $this->teacher(['role_locked' => true]);
        $id = $this->asToken($this->tokenFor($teacher))->postJson('/api/school/setup-requests', ['requested_group_code' => 'SYN-1', 'requested_module_code' => 'SYN-M0', 'requested_year' => 'Synthetic year', 'reason' => 'Synthetic missing space report'])->assertCreated()->json('id');
        $this->getJson("/api/school/offerings/{$offerings[0]}/tools/materials")->assertForbidden();
        $this->postJson("/api/school/assignment-requests/$id/resolve", ['offering_id' => $offerings[0]])->assertForbidden();
        $this->asToken($this->tokenFor($admin))->postJson("/api/school/assignment-requests/$id/resolve", ['offering_id' => $offerings[0]])->assertNoContent();
        $this->asToken($this->tokenFor($teacher))->getJson("/api/school/offerings/{$offerings[0]}/tools/materials")->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'school.setup.request_resolved']);
    }

    public function test_database_rejects_a_third_delegate_slot_even_outside_the_service(): void
    {
        [$admin, $group, , , $students] = $this->schoolFixture();
        $this->expectException(QueryException::class);
        DB::table('class_delegates')->insert(['classroom_id' => $group->id, 'student_id' => $students[0]->id, 'active_slot' => 3, 'starts_at' => now(), 'ends_at' => now()->addMonth(), 'appointed_by' => $admin->id, 'created_at' => now(), 'updated_at' => now()]);
    }
}
