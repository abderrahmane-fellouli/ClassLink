<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as ProviderUser;
use Tests\TestCase;

class MicrosoftOrganizationsTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    private const OBJECT = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.azure.tenant' => 'organizations', 'classlink.frontend_url' => 'https://app.classlink.test']);
        Http::preventStrayRequests();
    }

    private function signIn(string $email)
    {
        $state = Str::random(64);
        Cache::put('oauth-state:'.hash('sha256', $state), true, now()->addMinutes(10));
        $profile = (new ProviderUser)->setRaw(['tid' => 'untrusted-tid-must-not-be-used'])
            ->map(['id' => self::OBJECT, 'email' => $email, 'name' => 'Organizational fixture'])
            ->setToken('mock-graph-token-not-a-real-secret');
        Socialite::shouldReceive('driver->stateless->user')->once()->andReturn($profile);

        return $this->withUnencryptedCookie('classlink_oauth_state', $state)
            ->get('/api/auth/microsoft/callback?state='.$state);
    }

    public function test_multitenant_student_identity_uses_authenticated_organization_not_app_authority_or_raw_tid(): void
    {
        Http::fake(['graph.microsoft.com/*' => Http::response(['value' => [[
            'id' => self::TENANT, 'verifiedDomains' => [['name' => 'OFPPT-EDU.MA']],
        ]]])]);
        $this->signIn('2007031400094@ofppt-edu.ma')->assertRedirectContains('#token=');
        $user = User::firstOrFail();
        $this->assertSame(self::TENANT, $user->microsoft_tenant_id);
        $this->assertSame(self::OBJECT, $user->microsoft_object_id);
        $this->assertSame('student', $user->role);
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://graph.microsoft.com/v1.0/organization')
            && $request->hasHeader('Authorization', 'Bearer mock-graph-token-not-a-real-secret')
            && $request['$select'] === 'id,verifiedDomains');
    }

    public function test_multitenant_teacher_still_has_no_privileges_before_admin_approval(): void
    {
        Http::fake(['graph.microsoft.com/*' => Http::response(['value' => [[
            'id' => self::TENANT, 'verifiedDomains' => [['name' => 'ofppt-edu.ma']],
        ]]])]);
        $this->signIn('ZAKARIYAE.CHERGUI@ofppt-edu.ma')->assertRedirectContains('/pending#verification=');
        $this->assertSame('pending', User::firstOrFail()->role);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_organization_without_verified_exact_ofppt_domain_is_rejected(): void
    {
        Http::fake(['graph.microsoft.com/*' => Http::response(['value' => [[
            'id' => self::TENANT, 'verifiedDomains' => [['name' => 'ofppt-edu.ma.example.com']],
        ]]])]);
        $this->signIn('123@ofppt-edu.ma')->assertRedirect('https://app.classlink.test/denied');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_graph_permission_failure_has_no_unsafe_tenant_fallback(): void
    {
        Http::fake(['graph.microsoft.com/*' => Http::response([], 403)]);
        $this->signIn('123@ofppt-edu.ma')->assertRedirect('https://app.classlink.test/denied');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_foreign_domain_does_not_trigger_directory_lookup(): void
    {
        Http::fake();
        $this->signIn('123@another-school.example')->assertRedirect('https://app.classlink.test/denied');
        Http::assertNothingSent();
    }
}
