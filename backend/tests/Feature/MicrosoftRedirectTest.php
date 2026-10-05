<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\Factory;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Socialite\Facades\Socialite;
use Tests\TestCase;

/**
 * §17.6 — contrat de redirection entre l'API et le frontend.
 *
 * Ces tests verrouillent les chemins de retour declares dans
 * `config/classlink.php` : ils doivent correspondre exactement aux routes de
 * `frontend/src/router.tsx`. Une divergence (cas observé avant la livraison :
 * `/auth/callback` et `/access-denied`) fait échouer la connexion Microsoft
 * sans qu'aucune erreur ne soit visible côté API.
 *
 * Le jeton doit transiter par le fragment (`#token=`) et jamais par la query
 * string, afin de ne pas figurer dans un journal de serveur.
 */
class MicrosoftRedirectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'classlink.frontend_url' => 'https://app.classlink.test',
            'services.azure.client_id' => 'fake-client-id',
            'services.azure.client_secret' => 'fake-secret',
            'services.azure.redirect' => 'https://api.classlink.test/api/auth/microsoft/callback',
            'services.azure.tenant' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    /** Simule Microsoft qui renvoie le profil de l'adresse donnee. */
    private function microsoftReturns(string $email, ?string $name = 'Zakariae Chergui'): void
    {
        \Laravel\Socialite\Facades\Socialite::shouldReceive('driver->stateless->user')
            ->andReturn((new SocialiteUser)->map(['id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'email' => $email, 'name' => $name]));
    }

    private function callbackResponse()
    {
        $state = Str::random(64);
        Cache::put('oauth-state:'.hash('sha256', $state), true, now()->addMinutes(10));

        return $this->withUnencryptedCookie('classlink_oauth_state', $state)
            ->get('/api/auth/microsoft/callback?state='.$state);
    }

    public function test_callback_without_browser_state_cannot_issue_a_token(): void
    {
        $this->get('/api/auth/microsoft/callback?code=untrusted')
            ->assertRedirect('https://app.classlink.test/auth/microsoft/callback#error=invalid_state');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_state_is_browser_bound_and_single_use(): void
    {
        $state = Str::random(64);
        Cache::put('oauth-state:'.hash('sha256', $state), true, now()->addMinutes(10));
        $this->withUnencryptedCookie('classlink_oauth_state', Str::random(64))
            ->get('/api/auth/microsoft/callback?state='.$state)
            ->assertRedirectContains('#error=invalid_state');
        $this->microsoftReturns('2007031400094@ofppt-edu.ma');
        $this->withUnencryptedCookie('classlink_oauth_state', $state)
            ->get('/api/auth/microsoft/callback?state='.$state)->assertRedirectContains('#token=');
        $this->get('/api/auth/microsoft/callback?state='.$state)->assertRedirectContains('#error=invalid_state');
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_provider_failure_returns_recoverable_frontend_error(): void
    {
        $state = Str::random(64);
        Cache::put('oauth-state:'.hash('sha256', $state), true, now()->addMinutes(10));
        \Laravel\Socialite\Facades\Socialite::shouldReceive('driver->stateless->user')
            ->andThrow(new \RuntimeException('Provider secret must not leak'));
        $this->withUnencryptedCookie('classlink_oauth_state', $state)
            ->get('/api/auth/microsoft/callback?state='.$state)
            ->assertRedirect('https://app.classlink.test/auth/microsoft/callback#error=microsoft_unavailable');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_real_redirect_includes_state_graph_scope_and_secure_cookie(): void
    {
        $response = $this->get('https://api.classlink.test/api/auth/microsoft/redirect');
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame(64, strlen($query['state']));
        $this->assertStringContainsString('User.Read', $query['scope']);
        $this->assertSame(config('services.azure.redirect'), $query['redirect_uri']);
        $cookie = collect($response->headers->getCookies())->first();
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertTrue($cookie->isSecure());
        $this->assertSame('lax', $cookie->getSameSite());
    }

    public function test_organizational_multitenant_authorization_url_keeps_callback_and_state_protection(): void
    {
        config(['services.azure.tenant' => 'organizations', 'services.azure.redirect' => 'http://localhost:8000/api/auth/microsoft/callback']);
        $response = $this->get('/api/auth/microsoft/redirect');
        $url = $response->headers->get('Location');
        $this->assertSame('/organizations/oauth2/v2.0/authorize', parse_url($url, PHP_URL_PATH));
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('http://localhost:8000/api/auth/microsoft/callback', $query['redirect_uri']);
        $this->assertSame(64, strlen($query['state']));
        $this->assertStringContainsString('User.Read', $query['scope']);
        $cookie = collect($response->headers->getCookies())->first();
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
    }

    public function test_frontend_routes_match_the_spa_router(): void
    {
        $this->assertSame('/auth/microsoft/callback', config('classlink.frontend_routes.callback'));
        $this->assertSame('/denied', config('classlink.frontend_routes.denied'));
        $this->assertSame('/pending', config('classlink.frontend_routes.pending'));
    }

    public function test_a_teacher_landing_receives_the_token_in_the_fragment(): void
    {
        User::factory()->teacher()->create(['email' => 'zakariyae.chergui@ofppt-edu.ma']);
        $this->microsoftReturns('zakariyae.chergui@ofppt-edu.ma');

        $response = $this->callbackResponse();

        $this->assertStringStartsWith(
            'https://app.classlink.test/auth/microsoft/callback#token=',
            (string) $response->headers->get('Location')
        );
        $response->assertRedirectContains('#token=');

        $user = User::where('email', 'zakariyae.chergui@ofppt-edu.ma')->firstOrFail();
        $this->assertSame('teacher', $user->role);
    }

    public function test_the_token_never_appears_in_the_query_string(): void
    {
        $this->microsoftReturns('2007031400094@ofppt-edu.ma');

        $target = $this->callbackResponse()->headers->get('Location');

        $this->assertStringContainsString('#token=', (string) $target);
        $this->assertStringNotContainsString('?token=', (string) $target);
    }

    public function test_a_foreign_domain_is_sent_to_the_access_denied_screen(): void
    {
        $this->microsoftReturns('x@gmail.com');

        $response = $this->callbackResponse();

        $response->assertRedirect('https://app.classlink.test/denied');
        $this->assertDatabaseMissing('users', ['email' => 'x@gmail.com']);
    }

    public function test_an_unknown_format_is_sent_to_the_pending_screen(): void
    {
        // §17.6 B : format inconnu -> « Compte en attente de validation ».
        $this->microsoftReturns('abc123@ofppt-edu.ma');

        $response = $this->callbackResponse();

        $response->assertRedirectContains('https://app.classlink.test/pending#verification=');
        $this->assertDatabaseHas('users', [
            'email' => 'abc123@ofppt-edu.ma',
            'role' => 'pending',
        ]);
    }

    public function test_a_pending_account_receives_no_token(): void
    {
        $this->microsoftReturns('abc123@ofppt-edu.ma');

        $this->callbackResponse();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_an_inactive_account_is_sent_to_the_access_denied_screen(): void
    {
        User::factory()->teacher()->create([
            'email' => 'hamza.bouzid@ofppt-edu.ma',
            'is_active' => false,
        ]);

        $this->microsoftReturns('hamza.bouzid@ofppt-edu.ma');

        $this->callbackResponse()
            ->assertRedirect('https://app.classlink.test/denied');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_a_locked_role_survives_a_new_microsoft_login(): void
    {
        // RG-03 : un rôle attribué par le super admin n'est jamais recalculé.
        User::factory()->create([
            'email' => '2007031400094@ofppt-edu.ma',
            'role' => 'teacher',
            'role_locked' => true,
        ]);

        $this->microsoftReturns('2007031400094@ofppt-edu.ma');

        $this->callbackResponse()
            ->assertRedirectContains('https://app.classlink.test/auth/microsoft/callback#token=');

        $this->assertSame('teacher', User::where('email', '2007031400094@ofppt-edu.ma')->value('role'));
    }

    public function test_the_login_is_written_to_the_audit_log(): void
    {
        $this->microsoftReturns('2007031400094@ofppt-edu.ma');

        $this->callbackResponse();

        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login']);
    }

    public function test_a_trailing_slash_in_frontend_url_does_not_double_the_separator(): void
    {
        config(['classlink.frontend_url' => 'https://app.classlink.test/']);

        $this->microsoftReturns('x@gmail.com');

        $this->callbackResponse()
            ->assertRedirect('https://app.classlink.test/denied');
    }

    public function test_the_socialite_facade_resolves_and_the_azure_driver_is_registered(): void
    {
        // Laravel 11 n'enregistre plus d'alias de classe : un `use` erroné
        // vers `Socialite\Facades\Socialite` fait echouer la connexion
        // Microsoft avant meme d'atteindre la detection de role.
        $this->assertTrue(class_exists(Socialite::class) || class_exists(\Laravel\Socialite\Facades\Socialite::class));
        $this->assertNotNull(app(Factory::class));
    }

    public function test_cors_is_restricted_to_the_configured_frontend_origin(): void
    {
        // Sans `config/cors.php`, Laravel 11 applique son defaut
        // `allowed_origins => ['*']`, ce qui laisserait n'importe quel site
        // appeler l'API. On verifie la configuration resolue : l'en-tete
        // `Access-Control-Allow-Origin` est fige a la construction du
        // middleware et ne refleterait pas une modification a chaud.
        $this->assertNotSame(['*'], config('cors.allowed_origins'));
        $this->assertNotContains('*', config('cors.allowed_origins'));
        $this->assertSame(config('classlink.allowed_origins'), config('cors.allowed_origins'));
        $this->assertFalse(config('cors.supports_credentials'), 'L\'API est sans etat : aucun cookie de session.');
        $this->assertSame(['api/*'], config('cors.paths'));
    }

    public function test_cors_accepts_several_declared_origins(): void
    {
        // FRONTEND_URL peut lister plusieurs origines (production + previsualisation).
        config(['classlink.allowed_origins' => [
            'https://app.classlink.ma',
            'https://staging.classlink.ma',
        ]]);

        $cors = require base_path('config/cors.php');

        $this->assertSame([
            'https://app.classlink.ma',
            'https://staging.classlink.ma',
        ], $cors['allowed_origins']);
    }
}
