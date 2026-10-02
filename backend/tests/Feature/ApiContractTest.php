<?php

namespace Tests\Feature;

use App\Models\Classroom;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * §12 / §16 — contrat de réponse de l'API pour les clients non-navigateur.
 *
 * Le SPA envoie `Accept: application/json`, donc ce cas n'apparaît jamais dans
 * une recette navigateur. En revanche `curl`, une application mobile ou une
 * sonde de supervision n'envoient pas cet en-tête : avant le middleware
 * `ForceJsonResponse`, la route protégée sans jeton levait
 * « Route [login] not defined » et répondait **500** au lieu du **401**
 * documenté. Ces tests figent le comportement correct.
 */
class ApiContractTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string}>
     */
    public static function unauthenticatedRoutes(): array
    {
        return [
            'me' => ['/api/me'],
            'classes' => ['/api/classes'],
            'notifications' => ['/api/notifications'],
            'quizzes d une classe' => ['/api/classes/1/quizzes'],
            'deadlines' => ['/api/me/deadlines'],
        ];
    }

    /**
     * Sans en-tête `Accept`, une route protégée doit répondre 401 JSON.
     *
     * @dataProvider unauthenticatedRoutes
     */
    public function test_protected_route_without_token_returns_401_json_even_without_accept_header(string $uri): void
    {
        $response = $this->call('GET', $uri, [], [], [], [
            'HTTP_ACCEPT' => '', // aucun Accept : cas curl / sonde
        ]);

        $response->assertStatus(401);
        $response->assertHeader('Content-Type', 'application/json');
        $response->assertJson([
            'code' => 'session_expired',
        ]);
    }

    public function test_a_bearer_token_never_comes_from_a_cookie(): void
    {
        // §16 « Vol de jeton » : un cookie de session ne vaut pas
        // authentification, même si `statefulApi()` est actif.
        $user = User::factory()->create(['role' => 'student']);

        $this->withUnencryptedCookie('laravel_session', 'anything')
            ->withUnencryptedCookie('XSRF-TOKEN', 'anything')
            ->get('/api/me', ['Accept' => 'application/json'])
            ->assertStatus(401);
    }

    public function test_a_valid_bearer_token_still_passes_when_accept_is_absent(): void
    {
        $user = $this->teacher();

        // `call()` n'applique pas `defaultHeaders` (contrairement à `get()`),
        // donc l'en-tête doit passer par le tableau `$server`.
        $response = $this->call('GET', '/api/me', [], [], [], [
            'HTTP_ACCEPT' => '',
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->tokenFor($user),
        ]);

        $response->assertOk();
        $this->assertSame($user->id, $response->json('id'));
    }

    public function test_an_expired_or_bogus_token_returns_401_not_500(): void
    {
        $response = $this->call('GET', '/api/me', [], [], [], [
            'HTTP_ACCEPT' => '',
            'HTTP_AUTHORIZATION' => 'Bearer jeton-inexistant',
        ]);

        $response->assertStatus(401);
        $this->assertSame('session_expired', $response->json('code'));
    }

    public function test_validation_errors_are_json_without_accept_header(): void
    {
        $response = $this->call('POST', '/api/auth/otp/request', [], [], [], [
            'HTTP_ACCEPT' => '',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['email' => 'pas-une-adresse']));

        $response->assertStatus(422);
        $response->assertHeader('Content-Type', 'application/json');
        $this->assertArrayHasKey('errors', $response->json());
        $this->assertArrayHasKey('email', $response->json('errors'));
    }

    public function test_an_unknown_api_route_returns_json_404_without_accept_header(): void
    {
        $response = $this->call('GET', '/api/does-not-exist', [], [], [], ['HTTP_ACCEPT' => '']);

        $response->assertStatus(404);
        $response->assertJson(['message' => __('api.errors.not_found')]);
    }

    public function test_a_forbidden_role_returns_json_403_without_accept_header(): void
    {
        $student = $this->student();
        $classroom = Classroom::factory()->create();

        $response = $this->call('PATCH', "/api/classes/{$classroom->id}", [], [], [], [
            'HTTP_ACCEPT' => '',
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->tokenFor($student),
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['name' => 'Classe renommee']));

        $this->assertContains($response->status(), [401, 403]);
        $response->assertHeader('Content-Type', 'application/json');
    }

    public function test_an_html_accept_header_does_not_yield_an_html_error_page(): void
    {
        // Un diagnostic posé dans un navigateur (`Accept: text/html`) ne doit
        // pas non plus recevoir une page HTML : l'API n'en produit aucune.
        $response = $this->call('GET', '/api/me', [], [], [], [
            'HTTP_ACCEPT' => 'text/html,application/xhtml+xml',
        ]);

        $response->assertStatus(401);
        $this->assertStringNotContainsString(
            'text/html',
            (string) $response->headers->get('Content-Type')
        );
    }
}
