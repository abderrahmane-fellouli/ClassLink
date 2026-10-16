<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\SchoolAccess;
use App\Services\SchoolSetupService;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TemporaryReviewLoginTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://api.classlink.space/api/auth/temporary-review';

    private const URL = self::BASE.'/student';

    private const ACCOUNT = '2007031400094@ofppt-edu.ma';

    private const DELEGATE = '2005080600439@ofppt-edu.ma';

    private const TEACHER = 'ZAKARIYAE.CHERGUI@ofppt-edu.ma';

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance('env', 'production');
        $this->secret = bin2hex(random_bytes(32));
        config(['temporary-review.secret' => $this->secret,
            'temporary-review.until' => now()->addHour()->utc()->format('Y-m-d\TH:i:s\Z')]);
    }

    private function review(array $body = [], string $selector = 'student')
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->secret)->postJson(self::BASE.'/'.$selector, $body);
    }

    public function test_missing_configuration_refuses_access_without_issuing_a_token(): void
    {
        config(['temporary-review.secret' => null]);
        $this->review()->assertNotFound();
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_wrong_secret_and_short_configured_secret_are_rejected(): void
    {
        $this->withToken(bin2hex(random_bytes(32)))->postJson(self::URL)->assertNotFound();
        config(['temporary-review.secret' => 'not-a-real-secret']);
        $this->review()->assertNotFound();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_missing_invalid_or_expired_cutoff_is_fail_closed(): void
    {
        foreach ([null, 'tomorrow', now()->subSecond()->utc()->format('Y-m-d\TH:i:s\Z')] as $cutoff) {
            config(['temporary-review.until' => $cutoff]);
            $this->review()->assertNotFound();
        }
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_get_http_and_nonproduction_requests_cannot_issue_tokens(): void
    {
        $this->getJson(self::URL)->assertStatus(405);
        $this->withToken($this->secret)->postJson(str_replace('https:', 'http:', self::URL))->assertNotFound();
        $this->app->instance('env', 'local');
        $this->review()->assertNotFound();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_request_parameters_cannot_select_another_account_or_redirect(): void
    {
        $this->student(['email' => self::ACCOUNT]);
        $this->review(['email' => 'someone.else@ofppt-edu.ma', 'role' => 'admin', 'redirect' => 'https://example.invalid'])->assertNotFound();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_missing_or_inactive_account_is_not_created_or_activated(): void
    {
        $this->review()->assertNotFound();
        $this->assertDatabaseCount('users', 0);
        $user = $this->student(['email' => self::ACCOUNT, 'is_active' => false]);
        $this->review()->assertNotFound();
        $this->assertFalse($user->fresh()->is_active);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_single_account_token_is_audited_short_lived_and_preserves_permissions(): void
    {
        Mail::fake();
        $user = $this->student(['email' => self::ACCOUNT]);
        $other = $this->admin();
        $before = $user->fresh()->getRawOriginal();
        $otherBefore = $other->fresh()->getRawOriginal();
        $response = $this->review()->assertStatus(302);
        $this->assertSame('', $response->getContent());
        $location = $response->headers->get('Location');
        $this->assertStringStartsWith('https://classlink.space/auth/microsoft/callback#token=', $location);
        $plain = rawurldecode(substr($location, strpos($location, '#token=') + 7));
        $token = PersonalAccessToken::findToken($plain);
        $this->assertSame($user->id, $token->tokenable_id);
        $this->assertLessThanOrEqual(900, now()->diffInSeconds($token->expires_at));
        $this->assertGreaterThan(890, now()->diffInSeconds($token->expires_at));
        $this->assertSame((string) $token->id, $response->headers->get('X-Review-Token-Id'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        $this->assertSame($before, $user->fresh()->getRawOriginal());
        $this->assertSame($otherBefore, $other->fresh()->getRawOriginal());
        $audit = AuditLog::where('user_id', $user->id)->where('action', 'auth.login')->sole();
        $this->assertSame('temporary-review:student:'.hash('sha256', $this->secret), $audit->context['method']);
        $this->assertStringNotContainsString($this->secret, $audit->toJson());
        $this->assertStringNotContainsString($plain, $audit->toJson());
        $this->asToken($plain)->getJson('/api/me')->assertOk()->assertJsonPath('id', $user->id)->assertJsonPath('role', 'student');
        $this->asToken($plain)->getJson('/api/admin/users')->assertForbidden();
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        $this->travel(16)->minutes();
        $this->asToken($plain)->getJson('/api/me')->assertUnauthorized();
    }

    public function test_token_is_clipped_to_the_global_cutoff(): void
    {
        $this->student(['email' => self::ACCOUNT]);
        $cutoff = now()->addMinutes(2)->utc()->format('Y-m-d\TH:i:s\Z');
        config(['temporary-review.until' => $cutoff]);
        $response = $this->review()->assertStatus(302);
        $this->assertSame(str_replace('Z', '+00:00', $cutoff), $response->headers->get('X-Review-Expires-At'));
    }

    public function test_pending_account_is_not_approved_by_review_access(): void
    {
        $user = $this->student(['email' => self::ACCOUNT, 'role' => 'pending']);
        $before = $user->fresh()->getRawOriginal();
        $this->review()->assertNotFound();
        $this->assertSame($before, $user->fresh()->getRawOriginal());
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_query_string_secret_is_not_an_authentication_method(): void
    {
        $this->postJson(self::URL.'?secret='.rawurlencode($this->secret))->assertNotFound();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_failed_issue_rolls_back_the_token_and_audit_together(): void
    {
        $user = $this->student(['email' => self::ACCOUNT]);
        $this->app->instance(TokenService::class, new class extends TokenService
        {
            public function issue(User $user, string $method = 'otp'): string
            {
                parent::issue($user, $method);
                throw new \RuntimeException('Synthetic issuance failure');
            }
        });
        Log::shouldReceive('error')->once()->with('Temporary review access failed', ['exception_class' => \RuntimeException::class]);
        $this->review()->assertStatus(503)->assertJsonMissing(['message' => 'Synthetic issuance failure']);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_endpoint_has_its_own_rate_limit(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->withToken(bin2hex(random_bytes(32)))->postJson(self::URL)->assertNotFound();
        }
        $this->review()->assertStatus(429);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_logout_revokes_only_the_review_token_and_secret_cannot_be_replayed(): void
    {
        $user = $this->student(['email' => self::ACCOUNT]);
        $existing = $this->tokenFor($user);
        $response = $this->review()->assertStatus(302);
        $id = (int) $response->headers->get('X-Review-Token-Id');
        $plain = rawurldecode(explode('#token=', $response->headers->get('Location'), 2)[1]);
        $this->asToken($plain)->postJson('/api/auth/logout')->assertNoContent();
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $id]);
        $this->asToken($plain)->getJson('/api/me')->assertUnauthorized();
        $this->asToken($existing)->getJson('/api/me')->assertOk();
        $this->review()->assertStatus(410);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    private function delegateFixture(): array
    {
        $admin = $this->admin();
        $user = $this->student(['email' => self::DELEGATE]);
        $year = DB::table('academic_years')->insertGetId(['name' => 'Synthetic review year', 'status' => 'active',
            'starts_on' => now()->subMonth()->toDateString(), 'ends_on' => now()->addYear()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
        $setup = app(SchoolSetupService::class);
        $group = $setup->createGroup($admin, ['academic_year_id' => $year, 'official_code' => 'SYN-REVIEW',
            'name' => 'Synthetic review scope', 'filiere' => 'Synthetic', 'level' => '2']);
        $setup->enroll($admin, $group, $user);
        $delegate = $setup->appoint($admin, $group, $user, now()->addDay()->toDateTimeString());

        return [$user, $group, $delegate];
    }

    private function businessSnapshot(): array
    {
        $snapshot = [];
        foreach (['users', 'school_enrollments', 'class_delegates', 'memberships', 'teaching_assignments', 'personal_access_tokens', 'audit_logs'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $snapshot;
    }

    public function test_only_declared_selectors_are_accepted_and_old_route_is_removed(): void
    {
        $this->withToken($this->secret)->postJson(self::BASE)->assertNotFound();
        $this->review([], 'someone@ofppt-edu.ma')->assertNotFound();
        $this->review([], 'super-admin')->assertNotFound();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_student_selector_does_not_relabel_an_existing_teacher(): void
    {
        $user = $this->teacher(['email' => self::ACCOUNT]);
        $before = $this->businessSnapshot();
        $this->review()->assertNotFound();
        $this->assertSame($before, $this->businessSnapshot());
        $this->assertSame('teacher', $user->fresh()->role);
    }

    public function test_teacher_selector_requires_the_actual_teacher_role_without_creating_assignments(): void
    {
        $user = $this->student(['email' => strtolower(self::TEACHER)]);
        $before = $this->businessSnapshot();
        $this->review([], 'teacher')->assertNotFound();
        $this->assertSame($before, $this->businessSnapshot());
        // Changing the synthetic fixture verifies the separate approved-role case.
        $user->forceFill(['role' => 'teacher', 'role_locked' => true])->save();
        $beforeUser = $user->fresh()->getRawOriginal();
        $response = $this->review([], 'teacher')->assertStatus(302);
        $plain = rawurldecode(explode('#token=', $response->headers->get('Location'), 2)[1]);
        $this->asToken($plain)->getJson('/api/me')->assertOk()->assertJsonPath('id', $user->id)->assertJsonPath('role', 'teacher');
        $this->assertSame($beforeUser, $user->fresh()->getRawOriginal());
        $this->assertDatabaseCount('teaching_assignments', 0);
    }

    public function test_inactive_teacher_is_not_activated(): void
    {
        $this->teacher(['email' => self::TEACHER, 'is_active' => false]);
        $before = $this->businessSnapshot();
        $this->review([], 'teacher')->assertNotFound();
        $this->assertSame($before, $this->businessSnapshot());
    }

    public function test_delegate_selector_requires_an_existing_current_responsibility(): void
    {
        $this->student(['email' => self::DELEGATE]);
        $before = $this->businessSnapshot();
        $this->review([], 'delegate')->assertNotFound();
        $this->assertSame($before, $this->businessSnapshot());
    }

    public function test_delegate_address_does_not_substitute_for_the_student_role(): void
    {
        $this->teacher(['email' => self::DELEGATE]);
        $before = $this->businessSnapshot();
        $this->review([], 'delegate')->assertNotFound();
        $this->assertSame($before, $this->businessSnapshot());
    }

    public function test_suspended_delegate_is_not_activated_or_issued_a_token(): void
    {
        [$user] = $this->delegateFixture();
        $user->forceFill(['is_active' => false])->save();
        $before = $this->businessSnapshot();
        $this->review([], 'delegate')->assertNotFound();
        $this->assertSame($before, $this->businessSnapshot());
    }

    public static function invalidDelegateStates(): array
    {
        return array_map(fn ($state) => [$state], ['expired', 'revoked', 'not_started', 'no_active_slot', 'enrollment_ended', 'enrollment_not_started', 'group_archived', 'year_archived']);
    }

    #[DataProvider('invalidDelegateStates')]
    public function test_delegate_selector_refuses_invalid_scopes(string $state): void
    {
        [$user, $group, $delegate] = $this->delegateFixture();
        match ($state) {
            'expired' => DB::table('class_delegates')->where('id', $delegate)->update(['starts_at' => now()->subDays(2), 'ends_at' => now()->subDay()]),
            'revoked' => DB::table('class_delegates')->where('id', $delegate)->update(['active_slot' => null, 'revoked_at' => now()]),
            'not_started' => DB::table('class_delegates')->where('id', $delegate)->update(['starts_at' => now()->addHour()]),
            'no_active_slot' => DB::table('class_delegates')->where('id', $delegate)->update(['active_slot' => null]),
            'enrollment_ended' => DB::table('school_enrollments')->where('student_id', $user->id)->update(['active_student_id' => null, 'ends_at' => now()]),
            'enrollment_not_started' => DB::table('school_enrollments')->where('student_id', $user->id)->update(['starts_at' => now()->addHour()]),
            'group_archived' => $group->forceFill(['status' => 'archived', 'archived_at' => now()])->save(),
            'year_archived' => DB::table('academic_years')->where('id', $group->academic_year_id)->update(['status' => 'archived']),
        };
        $before = $this->businessSnapshot();
        $this->review([], 'delegate')->assertNotFound();
        $this->assertSame($before, $this->businessSnapshot());
    }

    public function test_valid_delegate_remains_a_scoped_student_and_revocation_is_not_overridden_by_token(): void
    {
        [$user, $group, $delegate] = $this->delegateFixture();
        $beforeUser = $user->fresh()->getRawOriginal();
        $beforeDelegates = DB::table('class_delegates')->get()->toJson();
        $response = $this->review([], 'delegate')->assertStatus(302)->assertHeader('X-Review-Selector', 'delegate');
        $plain = rawurldecode(explode('#token=', $response->headers->get('Location'), 2)[1]);
        $this->asToken($plain)->getJson('/api/me')->assertOk()->assertJsonPath('id', $user->id)->assertJsonPath('role', 'student');
        $this->asToken($plain)->getJson('/api/admin/users')->assertForbidden();
        $this->assertSame($beforeUser, $user->fresh()->getRawOriginal());
        $this->assertSame($beforeDelegates, DB::table('class_delegates')->get()->toJson());
        DB::table('class_delegates')->where('id', $delegate)->update(['active_slot' => null, 'revoked_at' => now()]);
        $this->assertFalse(app(SchoolAccess::class)->delegate($user->fresh(), $group));
        $this->asToken($plain)->getJson('/api/school')->assertOk()->assertJsonPath('delegates', []);
    }

    public function test_same_secret_allows_one_issue_for_each_pinned_selector_not_one_global_issue(): void
    {
        [$delegate] = $this->delegateFixture();
        $student = $this->student(['email' => self::ACCOUNT]);
        $teacher = $this->teacher(['email' => self::TEACHER]);
        foreach (['student' => $student, 'delegate' => $delegate, 'teacher' => $teacher] as $selector => $user) {
            $response = $this->review([], $selector)->assertStatus(302);
            $token = PersonalAccessToken::find((int) $response->headers->get('X-Review-Token-Id'));
            $this->assertSame($user->id, $token->tokenable_id);
            $this->assertSame('temporary-review:'.$selector.':'.hash('sha256', $this->secret), $token->name);
        }
        $this->assertDatabaseCount('personal_access_tokens', 3);
        $this->travel(61)->seconds();
        foreach (['student', 'delegate', 'teacher'] as $selector) {
            $this->review([], $selector)->assertStatus(410);
        }
        $this->assertDatabaseCount('personal_access_tokens', 3);
    }

    public function test_legacy_one_account_consumption_still_counts_for_student_but_not_teacher(): void
    {
        $student = $this->student(['email' => self::ACCOUNT]);
        $teacher = $this->teacher(['email' => self::TEACHER]);
        $this->tokenFor($student, 'temporary-review:'.hash('sha256', $this->secret));
        $this->review()->assertStatus(410);
        $response = $this->review([], 'teacher')->assertStatus(302);
        $this->assertSame($teacher->id, PersonalAccessToken::find((int) $response->headers->get('X-Review-Token-Id'))->tokenable_id);
    }

    public function test_discovery_reports_actual_roles_scope_and_only_active_admin_emails_without_writes(): void
    {
        [$delegate, $group] = $this->delegateFixture();
        $this->student(['email' => self::ACCOUNT]);
        $this->student(['email' => self::TEACHER]); // Pattern must not be treated as proof of role.
        $this->admin(['email' => 'synthetic.second.admin@ofppt-edu.ma']);
        $inactive = $this->admin(['email' => 'synthetic.inactive.admin@ofppt-edu.ma', 'is_active' => false]);
        $before = $this->businessSnapshot();
        $response = $this->withToken($this->secret)->getJson(self::BASE.'/accounts')->assertOk();
        $response->assertJsonPath('accounts.student.current_role', 'student')
            ->assertJsonPath('accounts.student.selector_eligible', true)
            ->assertJsonPath('accounts.teacher.current_role', 'student')
            ->assertJsonPath('accounts.teacher.selector_eligible', false)
            ->assertJsonPath('accounts.delegate.current_role', 'student')
            ->assertJsonPath('accounts.delegate.selector_eligible', true)
            ->assertJsonPath('accounts.delegate.delegate_scopes.0.classroom_id', $group->id)
            ->assertJsonPath('admin_selected', false);
        $admins = User::where('role', 'admin')->where('is_active', true)->orderBy('email')->pluck('email')->all();
        $this->assertSame($admins, $response->json('eligible_admin_emails'));
        $this->assertNotContains($inactive->email, $response->json('eligible_admin_emails'));
        $this->assertSame($before, $this->businessSnapshot());
        $this->assertStringNotContainsString($this->secret, $response->getContent());
        $this->review()->assertStatus(302); // Read-only discovery did not consume issuance.
    }

    public function test_single_admin_is_reported_but_never_automatically_selected_or_issued(): void
    {
        $admin = $this->admin(['email' => 'synthetic.only.admin@ofppt-edu.ma']);
        $before = $this->businessSnapshot();
        $this->withToken($this->secret)->getJson(self::BASE.'/accounts')->assertOk()
            ->assertJsonPath('eligible_admin_emails', [$admin->email])->assertJsonPath('admin_selected', false);
        $this->review([], 'admin')->assertStatus(409);
        $this->assertSame($before, $this->businessSnapshot());
    }

    public function test_empty_production_shaped_data_is_reported_without_inventing_accounts(): void
    {
        $before = $this->businessSnapshot();
        $this->withToken($this->secret)->getJson(self::BASE.'/accounts')->assertOk()
            ->assertJsonPath('accounts.student.exists_unique', false)
            ->assertJsonPath('accounts.student.current_role', null)
            ->assertJsonPath('accounts.delegate.selector_eligible', false)
            ->assertJsonPath('accounts.teacher.selector_eligible', false)
            ->assertJsonPath('eligible_admin_emails', [])
            ->assertJsonPath('admin_selected', false);
        $this->assertSame($before, $this->businessSnapshot());
    }

    public function test_discovery_is_protected_and_rejects_arbitrary_email_parameters(): void
    {
        $this->getJson(self::BASE.'/accounts')->assertNotFound();
        $this->withToken($this->secret)->getJson(self::BASE.'/accounts?email=someone@ofppt-edu.ma')->assertNotFound();
        config(['temporary-review.secret' => null]);
        $this->withToken($this->secret)->getJson(self::BASE.'/accounts')->assertNotFound();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
