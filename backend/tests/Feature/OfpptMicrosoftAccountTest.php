<?php

namespace Tests\Feature;

use App\Models\OtpCode;
use App\Models\User;
use App\Services\MicrosoftAccountService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as ProviderUser;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OfpptMicrosoftAccountTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    private const OBJECT = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

    private const OTHER = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.azure.tenant' => self::TENANT, 'classlink.frontend_url' => 'https://app.classlink.test']);
    }

    private function microsoftLogin(string $email, string $object = self::OBJECT, array $raw = [])
    {
        $state = Str::random(64);
        Cache::put('oauth-state:'.hash('sha256', $state), true, now()->addMinutes(10));
        Socialite::shouldReceive('driver->stateless->user')->once()
            ->andReturn((new ProviderUser)->setRaw($raw)->map(['id' => $object, 'email' => $email, 'name' => 'Verified test account']));

        return $this->withUnencryptedCookie('classlink_oauth_state', $state)
            ->get('/api/auth/microsoft/callback?state='.$state.'&email=2007031400094@ofppt-edu.ma');
    }

    #[DataProvider('students')]
    public function test_numeric_accounts_enter_student_flow_without_inferred_personal_data(string $email): void
    {
        $this->microsoftLogin($email)->assertRedirectContains('/auth/microsoft/callback#token=');
        $user = User::firstOrFail();
        $this->assertSame('student', $user->role);
        $this->assertSame('student', $user->role_candidate);
        $this->assertSame(strtolower($email), $user->email);
        $this->assertSame(self::TENANT, $user->microsoft_tenant_id);
        $this->assertSame(self::OBJECT, $user->microsoft_object_id);
        $this->assertNotNull($user->microsoft_verified_at);
        $this->assertArrayNotHasKey('birth_date', $user->getAttributes());
        $this->assertArrayNotHasKey('microsoft_object_id', $user->toArray());
    }

    public static function students(): array
    {
        return [['2007031400094@ofppt-edu.ma'], ['123@OFPPT-EDU.MA'], ['12345678901234@ofppt-edu.ma']];
    }

    public function test_teacher_candidate_requires_audited_admin_approval(): void
    {
        $response = $this->microsoftLogin('ZAKARIYAE.CHERGUI@OFPPT-EDU.MA')->assertRedirectContains('/pending#verification=');
        $this->assertStringNotContainsString('#token=', $response->headers->get('Location'));
        $teacher = User::firstOrFail();
        $this->assertSame('pending', $teacher->role);
        $this->assertSame('teacher', $teacher->role_candidate);
        $this->assertFalse($teacher->role_locked);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->actingAs($teacher)->postJson('/api/classes', ['name' => 'Unauthorized'])->assertForbidden();

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->getJson('/api/admin/users/pending')->assertOk()
            ->assertJsonPath('data.0.email', 'zakariyae.chergui@ofppt-edu.ma')
            ->assertJsonPath('data.0.role_candidate', 'teacher')
            ->assertJsonPath('data.0.verification_source', 'microsoft');
        $this->patchJson('/api/admin/users/'.$teacher->id, ['role' => 'teacher'])->assertOk()
            ->assertJsonPath('role_locked', true);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.role_change']);
        $this->actingAs($teacher->fresh())->postJson('/api/classes', [
            'name' => 'Approved class', 'subject' => 'Web', 'group_label' => 'TDI', 'school_year' => '2026',
        ])->assertForbidden();
        $this->microsoftLogin('zakariyae.chergui@ofppt-edu.ma')->assertRedirectContains('#token=');
        $this->assertSame('teacher', $teacher->fresh()->role);
    }

    #[DataProvider('invalidAddresses')]
    public function test_noneligible_provider_accounts_cannot_be_overridden_by_frontend_email(string $email): void
    {
        $this->microsoftLogin($email)->assertRedirect('https://app.classlink.test/denied');
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public static function invalidAddresses(): array
    {
        return [
            ['person@outlook.com'], ['person@hotmail.com'], ['person@gmail.com'], ['person@school.example'],
            ['123@ofppt-edu.ma.example.com'], ['123@fakeofppt-edu.ma'], ['123@ofppt-edu.com'],
            ['123@ofppt.edu.ma'], ['@ofppt-edu.ma'], [''], ['a@@ofppt-edu.ma'], ["a@ofppt-edu.ma\r\n"],
        ];
    }

    public function test_missing_object_or_wrong_tenant_is_denied(): void
    {
        $this->microsoftLogin('123@ofppt-edu.ma', '')->assertRedirect('https://app.classlink.test/denied');
        $this->microsoftLogin('123@ofppt-edu.ma', self::OBJECT, ['tid' => self::OTHER])->assertRedirect('https://app.classlink.test/denied');
        config(['services.azure.tenant' => 'common']);
        $this->microsoftLogin('123@ofppt-edu.ma')->assertRedirect('https://app.classlink.test/denied');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_structurally_malformed_profile_is_denied_without_warnings_or_writes(): void
    {
        $profile = (new ProviderUser)->map(['id' => ['not-a-string'], 'email' => ['not-an-address']]);
        $this->assertNull((new MicrosoftAccountService)->resolve($profile));
        $this->assertDatabaseCount('users', 0);
    }

    public function test_legacy_user_links_once_and_upn_rename_keeps_same_user_and_approval(): void
    {
        $existing = User::factory()->teacher()->create(['email' => 'old.name@ofppt-edu.ma']);
        $this->microsoftLogin('OLD.NAME@ofppt-edu.ma')->assertRedirectContains('#token=');
        $this->microsoftLogin('new.name@ofppt-edu.ma')->assertRedirectContains('#token=');
        $this->assertDatabaseCount('users', 1);
        $this->assertSame('new.name@ofppt-edu.ma', $existing->fresh()->email);
        $this->assertSame('teacher', $existing->fresh()->role);
        $this->assertSame(self::OBJECT, $existing->fresh()->microsoft_object_id);
    }

    public function test_other_object_cannot_claim_linked_email_or_merge_rename_collision(): void
    {
        $this->microsoftLogin('123@ofppt-edu.ma')->assertRedirectContains('#token=');
        $this->microsoftLogin('123@ofppt-edu.ma', self::OTHER)->assertRedirect('https://app.classlink.test/denied');
        User::factory()->create(['email' => '456@ofppt-edu.ma']);
        $this->microsoftLogin('456@ofppt-edu.ma')->assertRedirect('https://app.classlink.test/denied');
        $this->assertDatabaseCount('users', 2);
        $this->assertSame('123@ofppt-edu.ma', User::where('microsoft_object_id', self::OBJECT)->firstOrFail()->email);
    }

    public function test_repeated_successful_login_is_one_user_not_a_duplicate_identity(): void
    {
        $this->microsoftLogin('123@ofppt-edu.ma');
        $this->microsoftLogin('123@OFPPT-EDU.MA');
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('personal_access_tokens', 2);
    }

    public function test_database_rejects_duplicate_tenant_object_pair(): void
    {
        $this->microsoftLogin('123@ofppt-edu.ma');
        $other = User::factory()->create(['email' => '456@ofppt-edu.ma']);
        $this->expectException(UniqueConstraintViolationException::class);
        $other->forceFill(['microsoft_tenant_id' => self::TENANT, 'microsoft_object_id' => self::OBJECT])->save();
    }

    public function test_student_cannot_self_promote_or_set_verification_fields(): void
    {
        $student = User::factory()->create();
        $this->actingAs($student)->patchJson('/api/me', [
            'role' => 'teacher', 'role_locked' => true, 'microsoft_object_id' => self::OBJECT,
            'microsoft_verified_at' => now()->toIso8601String(),
        ])->assertStatus(422)->assertJsonValidationErrors(['role', 'role_locked', 'microsoft_object_id', 'microsoft_verified_at']);
        $this->assertSame('student', $student->fresh()->role);
        $this->assertNull($student->fresh()->microsoft_verified_at);
        $this->patchJson('/api/admin/users/'.$student->id, ['role' => 'teacher'])->assertForbidden();
    }

    public function test_pending_verification_receipt_is_not_a_login_token_and_is_single_use(): void
    {
        $response = $this->microsoftLogin('trainer.name@ofppt-edu.ma');
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_FRAGMENT), $params);
        $this->postJson('/api/auth/microsoft/pending-verification', $params)->assertOk()
            ->assertExactJson(['verification_source' => 'microsoft', 'role_candidate' => 'teacher', 'status' => 'pending']);
        $this->postJson('/api/auth/microsoft/pending-verification', $params)->assertNotFound();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_admin_rejection_is_audited_and_blocks_future_login(): void
    {
        $this->microsoftLogin('trainer.name@ofppt-edu.ma');
        $candidate = User::firstOrFail();
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->patchJson('/api/admin/users/'.$candidate->id, ['is_active' => false])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.activation_change']);
        $this->microsoftLogin('trainer.name@ofppt-edu.ma')->assertRedirect('https://app.classlink.test/denied');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_email_fallback_does_not_certify_microsoft_identity_or_auto_grant_teacher(): void
    {
        OtpCode::create([
            'email' => 'trainer.name@ofppt-edu.ma', 'code_hash' => OtpCode::hash('123456'),
            'attempts' => 0, 'expires_at' => now()->addMinutes(10),
        ]);
        $this->postJson('/api/auth/otp/verify', ['email' => 'trainer.name@ofppt-edu.ma', 'code' => '123456'])->assertStatus(422);
        $candidate = User::firstOrFail();
        $this->assertSame('pending', $candidate->role);
        $this->assertNull($candidate->microsoft_verified_at);
        $this->assertNull($candidate->microsoft_object_id);
    }

    public function test_numeric_email_fallback_uses_generic_name_and_never_claims_microsoft_verification(): void
    {
        OtpCode::create([
            'email' => '12345678901234@ofppt-edu.ma', 'code_hash' => OtpCode::hash('123456'),
            'attempts' => 0, 'expires_at' => now()->addMinutes(10),
        ]);
        $this->postJson('/api/auth/otp/verify', ['email' => '12345678901234@ofppt-edu.ma', 'code' => '123456'])
            ->assertOk()->assertJsonPath('user.display_name', 'Student')->assertJsonPath('user.role', 'student');
        $user = User::firstOrFail();
        $this->assertNull($user->microsoft_verified_at);
        $this->assertNull($user->microsoft_object_id);
    }

    public function test_additive_upgrade_preserves_records_and_approved_roles_but_reviews_legacy_auto_teachers(): void
    {
        [$classroom, $legacyTeacher, $student] = $this->classWithMember();
        $legacyTeacher->update(['role_locked' => false]);
        $legacyTeacher->createToken('legacy-session');
        $approvedTeacher = User::factory()->teacher()->create();
        $approvedTeacher->createToken('approved-session');
        $migration = require database_path('migrations/2026_10_04_000001_add_microsoft_identity_to_users.php');
        $migration->down();
        $migration->up();
        $this->assertSame('pending', $legacyTeacher->fresh()->role);
        $this->assertSame('teacher', $legacyTeacher->fresh()->role_candidate);
        $this->assertSame(0, $legacyTeacher->tokens()->count());
        $this->assertSame('teacher', $approvedTeacher->fresh()->role);
        $this->assertSame(1, $approvedTeacher->tokens()->count());
        $this->assertSame($legacyTeacher->id, $classroom->fresh()->teacher_id);
        $this->assertSame(1, $student->memberships()->count());
    }

    public function test_expired_pending_receipt_cannot_claim_verification(): void
    {
        $response = $this->microsoftLogin('trainer.name@ofppt-edu.ma');
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_FRAGMENT), $params);
        $this->travel(11)->minutes();
        $this->postJson('/api/auth/microsoft/pending-verification', $params)->assertNotFound();
    }
}
