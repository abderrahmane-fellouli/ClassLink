<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class TemporaryReviewLoginTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://api.classlink.space/api/auth/temporary-review';

    private const ACCOUNT = '2007031400094@ofppt-edu.ma';

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance('env', 'production');
        $this->secret = bin2hex(random_bytes(32));
        config(['temporary-review.secret' => $this->secret,
            'temporary-review.until' => now()->addHour()->utc()->format('Y-m-d\TH:i:s\Z')]);
    }

    private function review(array $body = [])
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->secret)->postJson(self::URL, $body);
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
        $this->assertSame('temporary-review:'.hash('sha256', $this->secret), $audit->context['method']);
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
        Log::shouldReceive('error')->once()->with('Temporary review token issuance failed', ['exception_class' => \RuntimeException::class]);
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
}
