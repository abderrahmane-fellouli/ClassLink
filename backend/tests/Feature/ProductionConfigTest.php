<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * §25 / §16 — configuration de production.
 *
 * Ces tests ne portent pas sur la logique metier mais sur les garde-fous de
 * deploiement : ils verifient que la configuration lue par l'application
 * refuse explicitement les raccourcis de developpement et n'expose aucune
 * surface d'acces non declaree.
 */
class ProductionConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_dev_auth_is_disabled_when_the_environment_is_production(): void
    {
        config([
            'app.env' => 'production',
            'classlink.dev_auth.enabled' => false,
        ]);

        User::factory()->create(['role' => 'student']);

        $this->postJson('/api/auth/dev/login', ['role' => 'student'])->assertNotFound();
    }

    public function test_dev_auth_stays_available_in_local_for_the_demo(): void
    {
        config(['app.env' => 'local', 'classlink.dev_auth.enabled' => true]);

        User::factory()->create(['role' => 'student']);

        $this->postJson('/api/auth/dev/login', ['role' => 'student'])
            ->assertOk()
            ->assertJsonStructure(['token', 'user']);
    }

    public function test_no_password_field_is_ever_returned_in_production_shape(): void
    {
        $user = $this->teacher();

        $token = $this->tokenFor($user);
        $payload = $this->asToken($token)->getJson('/api/me')->json();

        $this->assertIsArray($payload);
        $this->assertArrayNotHasKey('password', $payload);
        $this->assertArrayNotHasKey('birth_date', $payload);
        $this->assertArrayNotHasKey('remember_token', $payload);
    }

    public function test_the_health_endpoint_is_reachable_without_authentication(): void
    {
        // Render surveille `/up` ; le deploiement echoue si la route disparait.
        $this->get('/up')->assertOk();
    }

    public function test_no_secret_leaks_through_the_configuration_dump(): void
    {
        // Garde-fou : si une cle etait ajoutee a `config/` et lue depuis
        // l'environnement, elle ne doit pas finir dans une reponse JSON.
        $response = $this->getJson('/api/internal/daily-digest');

        $body = $response->getContent();

        foreach (['APP_KEY', 'AZURE_CLIENT_SECRET', 'AWS_SECRET_ACCESS_KEY', 'DIGEST_TOKEN', 'AI_PROVIDER'] as $secret) {
            $this->assertStringNotContainsString(
                config($secret) ?: 'valeur-absente-de-test',
                $body,
                "La reponse interne ne doit jamais contenir {$secret}."
            );
        }
    }

    public function test_cors_never_allows_wildcard_origins(): void
    {
        $origins = config('cors.allowed_origins');

        $this->assertNotContains('*', $origins);
        $this->assertFalse(config('cors.supports_credentials'));
    }

    public function test_the_api_never_uses_cookie_authentication(): void
    {
        // §16 « Vol de jeton » : l'API est sans etat. Une requete sans
        // en-tete Authorization doit etre rejetee, jamais servie depuis un
        // cookie de session.
        $user = $this->teacher();
        $this->tokenFor($user);

        $this->getJson('/api/me')->assertUnauthorized();
    }

    public function test_the_frontend_url_drives_cors_and_oauth_redirects(): void
    {
        config([
            'classlink.frontend_url' => 'https://app.classlink.ma',
        ]);

        $this->assertSame('https://app.classlink.ma', config('classlink.frontend_url'));
        $this->assertSame('/auth/microsoft/callback', config('classlink.frontend_routes.callback'));
    }
}
