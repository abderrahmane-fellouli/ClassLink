<?php

namespace Tests\Feature;

use App\Models\Classroom;
use App\Models\Membership;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\LocalSchoolAccountImport;
use App\Services\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class LocalSchoolImportTest extends TestCase
{
    use RefreshDatabase;

    private function input(): array
    {
        return ['group_code' => 'SYN-LOCAL', 'group_aliases' => ['SYN-ALIAS'], 'academic_year' => 'Synthetic local year', 'starts_on' => now()->subMonth()->toDateString(), 'ends_on' => now()->addYear()->toDateString(),
            'students' => [['name' => 'Synthetic Learner', 'email' => 'synthetic.learner@ofppt-edu.ma']],
            'teachers' => [['name' => 'SYNTHETIC TEACHER', 'email' => 'SYNTHETIC.TEACHER@ofppt-edu.ma', 'module_code' => 'SYN-M', 'module_title' => 'Synthetic Module']]];
    }

    public function test_local_import_reuses_alias_and_accounts_and_is_idempotent_without_notifications(): void
    {
        Mail::fake();
        $admin = $this->admin();
        $adminBefore = $admin->fresh()->getRawOriginal();
        $teacher = $this->teacher(['email' => 'synthetic.teacher@ofppt-edu.ma', 'role_locked' => true, 'display_name' => 'Earlier Synthetic Teacher', 'microsoft_object_id' => 'synthetic-object-id', 'microsoft_tenant_id' => 'synthetic-tenant-id', 'microsoft_verified_at' => now()]);
        $student = $this->student(['email' => 'synthetic.learner@ofppt-edu.ma']);
        $group = Classroom::factory()->create(['teacher_id' => $teacher->id, 'name' => 'SYN-ALIAS', 'school_year' => 'Synthetic local year']);
        $membership = Membership::create(['classroom_id' => $group->id, 'student_id' => $student->id, 'status' => 'accepted', 'requested_at' => now()->subWeek(), 'decided_at' => now()->subDay(), 'decided_by' => $teacher->id]);
        $input = $this->input();
        $service = app(LocalSchoolAccountImport::class);
        $first = $service->run($input, $admin);
        $this->assertSame($group->id, $first['group_id']);
        $this->assertSame(0, $first['created_accounts']);
        $this->assertSame(2, $first['updated_accounts']);
        $this->assertSame($teacher->id, User::whereRaw('LOWER(email) = ?', ['synthetic.teacher@ofppt-edu.ma'])->first()->id);
        $this->assertSame('SYNTHETIC.TEACHER@ofppt-edu.ma', $teacher->fresh()->email);
        $this->assertSame('synthetic-object-id', $teacher->fresh()->microsoft_object_id);
        $this->assertEquals($teacher->microsoft_verified_at, $teacher->fresh()->microsoft_verified_at);
        $this->assertSame($membership->id, Membership::where('classroom_id', $group->id)->where('student_id', $student->id)->first()->id);
        $this->assertEquals($membership->requested_at, $membership->fresh()->requested_at);
        $this->assertSame($adminBefore, $admin->fresh()->getRawOriginal());
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('class_delegates', 0);
        $second = $service->run($input, $admin);
        $this->assertSame(0, $second['created_accounts']);
        $this->assertSame(0, $second['updated_accounts']);
        $this->assertSame(0, $second['enrollments_created']);
        $this->assertSame(0, $second['assignments_created']);
        $this->assertDatabaseCount('school_modules', 1);
        $this->assertDatabaseCount('module_offerings', 1);
        $this->assertDatabaseCount('school_enrollments', 1);
        $this->assertDatabaseCount('teaching_assignments', 1);
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    public function test_import_rejects_remote_defaults_and_connection_urls_before_any_writes(): void
    {
        $admin = $this->admin();
        foreach ([['database.default' => 'pgsql'], ['database.connections.sqlite.url' => 'pgsql://remote.example.invalid/synthetic']] as $override) {
            try {
                config($override);
                app(LocalSchoolAccountImport::class)->run($this->input(), $admin);
                $this->fail('Remote configuration must be rejected.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('local SQLite', $e->getMessage());
            } finally {
                config(['database.default' => 'sqlite', 'database.connections.sqlite.url' => null]);
            }
        }
        $this->assertDatabaseCount('academic_years', 0);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_production_dev_auth_is_rejected_even_if_its_flag_is_forced_on(): void
    {
        config(['classlink.dev_auth.enabled' => true]);
        $student = $this->student(['email' => 'synthetic.learner@ofppt-edu.ma']);
        $this->app->instance('env', 'production');
        $this->postJson('/api/auth/dev/login', ['email' => $student->email])->assertNotFound();
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertNull($student->fresh()->last_login_at);
    }

    public function test_production_and_admin_conflicts_are_rejected_without_partial_changes(): void
    {
        $admin = $this->admin(['email' => 'synthetic.learner@ofppt-edu.ma']);
        $input = $this->input();
        try {
            app(LocalSchoolAccountImport::class)->run($input, $admin);
            $this->fail('Admin must not be downgraded.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('administrator', $e->getMessage());
        }
        $this->assertDatabaseCount('academic_years', 0);
        $this->assertDatabaseCount('users', 1);
        $this->assertSame('admin', $admin->fresh()->role);
        $this->app->instance('env', 'production');
        try {
            app(LocalSchoolAccountImport::class)->run($input, $admin);
            $this->fail('Production must be rejected.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('local', $e->getMessage());
        }
    }

    public function test_demo_cleanup_preserves_unrelated_memberships_and_historical_teacher(): void
    {
        $admin = $this->admin();
        $teacher = $this->teacher(['email' => 'hamza.bouzid@ofppt-edu.ma', 'display_name' => 'Hamza Bouzid']);
        $legacy = Classroom::factory()->create(['teacher_id' => $teacher->id, 'join_code' => 'TDI2025A', 'name' => 'Développement Web — TDI 1', 'subject' => 'Développement Web', 'group_label' => 'TDI 1', 'school_year' => '2025-2026']);
        $safe = $this->student(['email' => '20070314000'.(94 + 1).'@ofppt-edu.ma', 'display_name' => 'Salma Benali', 'role_locked' => false]);
        Membership::create(['classroom_id' => $legacy->id, 'student_id' => $safe->id, 'status' => 'accepted', 'requested_at' => now()]);
        $uncertain = $this->student(['email' => '20070314000'.(94 + 2).'@ofppt-edu.ma', 'display_name' => 'Amine Chraibi', 'role_locked' => false]);
        $unrelated = Classroom::factory()->create(['teacher_id' => $teacher->id, 'name' => 'Synthetic unrelated group']);
        Membership::create(['classroom_id' => $unrelated->id, 'student_id' => $uncertain->id, 'status' => 'accepted', 'requested_at' => now()]);
        $stats = app(LocalSchoolAccountImport::class)->run($this->input(), $admin);
        $this->assertSame(1, $stats['removed_accounts']);
        $this->assertNull($safe->fresh());
        $this->assertNotNull($uncertain->fresh());
        $this->assertNotNull($teacher->fresh());
        $this->assertNotNull($unrelated->fresh());
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_local_login_matches_preserved_email_case_and_rejects_remote_config(): void
    {
        config(['classlink.dev_auth.enabled' => true]);
        $teacher = $this->teacher(['email' => 'SYNTHETIC.TEACHER@ofppt-edu.ma', 'role_locked' => true]);
        $this->postJson('/api/auth/dev/login', ['email' => 'synthetic.teacher@ofppt-edu.ma'])->assertOk()->assertJsonPath('user.id', $teacher->id);
        try {
            config(['database.default' => 'pgsql', 'database.connections.pgsql.host' => 'remote.example.invalid', 'database.connections.pgsql.url' => null]);
            $this->postJson('/api/auth/dev/login', ['email' => 'synthetic.teacher@ofppt-edu.ma'])->assertNotFound();
        } finally {
            config(['database.default' => 'sqlite']);
        }
    }

    public function test_otp_reuses_uppercase_teacher_identity_instead_of_creating_another_account(): void
    {
        $teacher = $this->teacher(['email' => 'SYNTHETIC.TEACHER@ofppt-edu.ma', 'role_locked' => true]);
        $credential = (string) random_int(100000, 999999);
        OtpCode::create(['email' => 'synthetic.teacher@ofppt-edu.ma', 'code_hash' => OtpCode::hash($credential), 'expires_at' => now()->addMinutes(10), 'attempts' => 0]);
        $result = app(OtpService::class)->verify('SYNTHETIC.TEACHER@ofppt-edu.ma', $credential);
        $this->assertTrue($result['ok']);
        $this->assertSame($teacher->id, $result['user']->id);
        $this->assertSame('SYNTHETIC.TEACHER@ofppt-edu.ma', $teacher->fresh()->email);
        $this->assertDatabaseCount('users', 1);
    }
}
