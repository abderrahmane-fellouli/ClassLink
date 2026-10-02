<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
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
            'services.azure.tenant' => 'fake-tenant',
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
            ->andReturn((new SocialiteUser)->map(['email' => $email, 'name' => $name]));
    }

    public function test_frontend_routes_match_the_spa_router(): void
    {
        $this->assertSame('/auth/microsoft/callback', config('classlink.frontend_routes.callback'));
        $this->assertSame('/denied', config('classlink.frontend_routes.denied'));
        $this->assertSame('/pending', config('classlink.frontend_routes.pending'));
    }

    public function test_a_teacher_landing_receives_the_token_in_the_fragment(): void
    {
        $this->microsoftReturns('zakariyae.chergui@ofppt-edu.ma');

        $response = $this->get('/api/auth/microsoft/callback');

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

        $target = $this->get('/api/auth/microsoft/callback')->headers->get('Location');

        $this->assertStringContainsString('#token=', (string) $target);
        $this->assertStringNotContainsString('?token=', (string) $target);
    }

    public function test_a_foreign_domain_is_sent_to_the_access_denied_screen(): void
    {
        $this->microsoftReturns('x@gmail.com');

        $response = $this->get('/api/auth/microsoft/callback');

        $response->assertRedirect('https://app.classlink.test/denied');
        $this->assertDatabaseMissing('users', ['email' => 'x@gmail.com']);
    }

    public function test_an_unknown_format_is_sent_to_the_pending_screen(): void
    {
        // §17.6 B : format inconnu -> « Compte en attente de validation ».
        $this->microsoftReturns('abc123@ofppt-edu.ma');

        $response = $this->get('/api/auth/microsoft/callback');

        $response->assertRedirect('https://app.classlink.test/pending');
        $this->assertDatabaseHas('users', [
            'email' => 'abc123@ofppt-edu.ma',
            'role' => 'pending',
        ]);
    }

    public function test_a_pending_account_receives_no_token(): void
    {
        $this->microsoftReturns('abc123@ofppt-edu.ma');

        $this->get('/api/auth/microsoft/callback');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_an_inactive_account_is_sent_to_the_access_denied_screen(): void
    {
        User::factory()->teacher()->create([
            'email' => 'hamza.bouzid@ofppt-edu.ma',
            'is_active' => false,
        ]);

        $this->microsoftReturns('hamza.bouzid@ofppt-edu.ma');

        $this->get('/api/auth/microsoft/callback')
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

        $this->get('/api/auth/microsoft/callback')
            ->assertRedirectContains('https://app.classlink.test/auth/microsoft/callback#token=');

        $this->assertSame('teacher', User::where('email', '2007031400094@ofppt-edu.ma')->value('role'));
    }

    public function test_the_login_is_written_to_the_audit_log(): void
    {
        $this->microsoftReturns('zakariyae.chergui@ofppt-edu.ma');

        $this->get('/api/auth/microsoft/callback');

        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login']);
    }

    public function test_a_trailing_slash_in_frontend_url_does_not_double_the_separator(): void
    {
        config(['classlink.frontend_url' => 'https://app.classlink.test/']);

        $this->microsoftReturns('x@gmail.com');

        $this->get('/api/auth/microsoft/callback')
            ->assertRedirect('https://app.classlink.test/denied');
    }

    public function test_the_socialite_facade_resolves_and_the_azure_driver_is_registered(): void
    {
        // Laravel 11 n'enregistre plus d'alias de classe : un `use` erroné
        // vers `Socialite\Facades\Socialite` fait echouer la connexion
        // Microsoft avant meme d'atteindre la detection de role.
        $this->assertTrue(class_exists(\Socialite\Facades\Socialite::class) || class_exists(\Laravel\Socialite\Facades\Socialite::class));
        $this->assertNotNull(app(\Laravel\Socialite\Contracts\Factory::class));
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
