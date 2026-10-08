<?php

namespace Tests\Feature;

use App\Models\Classroom;
use App\Models\PartnerProfile;
use App\Models\User;
use App\Services\SchoolSetupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\SchoolFixture;
use Tests\TestCase;

class SchoolUxAccessTest extends TestCase
{
    use RefreshDatabase, SchoolFixture;

    private function asUser(User $user): static
    {
        return $this->asToken($this->tokenFor($user));
    }

    public function test_admin_historical_class_actions_do_not_include_official_groups(): void
    {
        [$admin, $group, $teachers] = $this->schoolFixture();
        $legacy = Classroom::factory()->create(['teacher_id' => $teachers[0]->id, 'name' => 'Synthetic historical group']);
        $this->asUser($admin)->getJson('/api/admin/classes')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $legacy->id);
    }

    public function test_official_enrollment_can_find_opt_in_partners_without_exposing_email(): void
    {
        [$admin, $group, $teachers, $offerings, $students] = $this->schoolFixture();
        PartnerProfile::create(['user_id' => $students[1]->id, 'opt_in' => true, 'skills' => ['Synthetic skill'], 'availability' => []]);
        $this->asUser($students[0])->getJson('/api/school/groups/'.$group->id.'/partners')->assertOk()
            ->assertJsonPath('data.0.user_id', $students[1]->id)->assertJsonMissingPath('data.0.email');
        $outsider = $this->student(['display_name' => 'Synthetic outsider']);
        $this->asUser($outsider)->getJson('/api/school/groups/'.$group->id.'/partners')->assertForbidden();
        DB::table('module_access_grants')->insert(['offering_id' => $offerings[0], 'student_id' => $outsider->id, 'granted_by' => $admin->id,
            'reason' => 'Synthetic scoped access', 'expires_at' => now()->addWeek(), 'created_at' => now(), 'updated_at' => now()]);
        $this->asUser($outsider)->getJson('/api/school/groups/'.$group->id.'/partners')->assertForbidden();
        $this->asUser($teachers[0])->getJson('/api/school/groups/'.$group->id.'/partners')->assertForbidden();
    }

    public function test_module_grant_directory_is_admin_only_and_revocation_updates_it(): void
    {
        [$admin, $group, $teachers, $offerings, $students] = $this->schoolFixture();
        $url = '/api/school/offerings/'.$offerings[0].'/access-grants';
        $this->asUser($admin)->postJson($url, ['student_id' => $students[0]->id, 'reason' => 'Synthetic supported access', 'expires_at' => now()->addWeek()->toIso8601String()])->assertNoContent();
        $this->asUser($admin)->getJson($url)->assertOk()->assertJsonPath('data.0.student_id', $students[0]->id)->assertJsonMissingPath('data.0.email');
        $this->asUser($teachers[0])->getJson($url)->assertForbidden();
        $this->asUser($students[0])->getJson($url)->assertForbidden();
        $this->asUser($admin)->deleteJson($url.'/'.$students[0]->id)->assertNoContent();
        $this->asUser($admin)->getJson($url)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_roster_preview_explains_when_a_transfer_is_required_before_commit(): void
    {
        [$admin, $group, , , $students] = $this->schoolFixture();
        $target = app(SchoolSetupService::class)->createGroup($admin, ['academic_year_id' => $group->academic_year_id,
            'official_code' => 'SYN-UX-TARGET', 'name' => 'Synthetic transfer destination', 'filiere' => 'Synthetic', 'level' => '2']);
        $students[0]->forceFill(['school_identifier' => 'SYN-UX-TRANSFER'])->save();
        $this->asUser($admin)->postJson('/api/school/groups/'.$target->id.'/roster-imports/preview', ['rows' => [[
            'student_identifier' => 'SYN-UX-TRANSFER', 'email' => $students[0]->email, 'display_name' => 'Synthetic transfer student',
        ]]])->assertOk()->assertJsonPath('errors.0.reason', 'transfer_required');
        $this->assertDatabaseHas('school_enrollments', ['classroom_id' => $group->id, 'active_student_id' => $students[0]->id]);
    }

    public function test_admin_delegate_notice_setting_round_trips_as_a_boolean(): void
    {
        [$admin, $group] = $this->schoolFixture();
        $this->asUser($admin)->patchJson('/api/school/groups/'.$group->id, ['delegate_notices_enabled' => true])->assertNoContent();
        $this->getJson('/api/school')->assertOk()->assertJsonPath('groups.0.delegate_notices_enabled', true);
        $this->patchJson('/api/school/groups/'.$group->id, ['delegate_notices_enabled' => false])->assertNoContent();
        $this->getJson('/api/school')->assertOk()->assertJsonPath('groups.0.delegate_notices_enabled', false);
    }

    public function test_official_partner_request_can_be_answered_only_by_its_enrolled_recipient(): void
    {
        [$admin, $group, $teachers, , $students] = $this->schoolFixture();
        PartnerProfile::create(['user_id' => $students[1]->id, 'opt_in' => true, 'skills' => [], 'availability' => []]);
        $created = $this->asUser($students[0])->postJson('/api/school/groups/'.$group->id.'/partner-requests', ['to_user_id' => $students[1]->id, 'classroom_id' => 999999])
            ->assertCreated()->assertJsonPath('is_official', true)->assertJsonPath('classroom_id', $group->id);
        $url = '/api/school/partner-requests/'.$created->json('id').'/respond';
        $this->postJson($url, ['status' => 'accepted'])->assertForbidden();
        $this->asUser($teachers[0])->postJson($url, ['status' => 'accepted'])->assertForbidden();
        $this->asUser($students[1])->getJson('/api/me/partner-requests')->assertOk()->assertJsonPath('data.0.can_respond', true);
        $this->postJson($url, ['status' => 'accepted'])->assertOk()->assertJsonPath('status', 'accepted');
        $this->assertDatabaseHas('partner_requests', ['id' => $created->json('id'), 'status' => 'accepted']);
    }
}
