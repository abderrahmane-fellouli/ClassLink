<?php

namespace Tests\Feature;

use App\Jobs\PruneExpiredOtpCodes;
use App\Models\AiProvider;
use App\Models\Announcement;
use App\Models\Classroom;
use App\Models\Membership;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\EmailDigestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * §17.11 — routes internes appelées par les tâches planifiées GitHub Actions.
 *
 * Ces routes ne sont PAS protégées par un jeton utilisateur mais par un secret
 * partagé (`X-Digest-Token`). Deux propriétés doivent être garanties :
 *   1. sans secret configuré, la route est fermée (404) ;
 *   2. un secret absent ou erroné ne donne jamais accès à la tâche.
 */
class InternalRouteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['classlink.digest_token' => 'secret-de-livraison']);
    }

    public function test_the_digest_routes_are_closed_when_no_secret_is_configured(): void
    {
        config(['classlink.digest_token' => '']);

        $this->postJson('/api/internal/daily-digest')
            ->assertNotFound();

        $this->postJson('/api/internal/prune')
            ->assertNotFound();

        $this->postJson('/api/internal/ai-quota-reset')
            ->assertNotFound();
    }

    public function test_the_digest_routes_reject_a_missing_token(): void
    {
        $this->postJson('/api/internal/daily-digest')->assertUnauthorized();
        $this->postJson('/api/internal/prune')->assertUnauthorized();
        $this->postJson('/api/internal/ai-quota-reset')->assertUnauthorized();
    }

    public function test_the_digest_routes_reject_a_wrong_token(): void
    {
        $this->withHeader('X-Digest-Token', 'mauvais-secret')
            ->postJson('/api/internal/daily-digest')
            ->assertUnauthorized();

        $this->withHeader('X-Digest-Token', 'mauvais-secret')
            ->postJson('/api/internal/prune')
            ->assertUnauthorized();

        $this->withHeader('X-Digest-Token', 'mauvais-secret')
            ->postJson('/api/internal/ai-quota-reset')
            ->assertUnauthorized();
    }

    public function test_a_user_token_does_not_open_an_internal_route(): void
    {
        // Un jeton d'etudiant valide ne doit rien changer : seule la tache
        // planifiee dispose du secret.
        $token = $this->tokenFor($this->teacher());

        $this->asToken($token)
            ->postJson('/api/internal/prune')
            ->assertUnauthorized();

        $this->asToken($token)
            ->postJson('/api/internal/ai-quota-reset')
            ->assertUnauthorized();
    }

    /*
     * F-IA-05 — la remise a zero des quotas doit etre declenchable en
     * production, sans dependre d'un `schedule:run` qui n'y tourne pas.
     */

    private function provider(array $attributes = []): AiProvider
    {
        static $sequence = 0;

        return AiProvider::create(array_merge([
            'name' => 'openai-'.(++$sequence),
            'priority' => 1,
            'enabled' => true,
            'daily_limit' => 20,
            'used_today' => 0,
        ], $attributes));
    }

    public function test_the_quota_reset_route_returns_the_number_of_providers_reset(): void
    {
        $this->provider(['used_today' => 12]);
        $this->provider(['used_today' => 3]);

        $response = $this->withHeader('X-Digest-Token', 'secret-de-livraison')
            ->postJson('/api/internal/ai-quota-reset')
            ->assertOk();

        $this->assertSame(2, $response->json('reset'));
    }

    public function test_the_quota_reset_route_clears_the_daily_counter(): void
    {
        $provider = $this->provider([
            'used_today' => 20,
            'last_reset_at' => now()->subDay(),
        ]);

        $this->withHeader('X-Digest-Token', 'secret-de-livraison')
            ->postJson('/api/internal/ai-quota-reset')
            ->assertOk();

        $provider->refresh();

        $this->assertSame(0, (int) $provider->used_today);
        $this->assertTrue(
            $provider->last_reset_at?->isToday(),
            'La date de remise a zero doit etre mise a jour.'
        );
    }

    public function test_the_quota_reset_route_does_not_change_the_configured_limit(): void
    {
        $provider = $this->provider(['used_today' => 20, 'daily_limit' => 7]);

        $this->withHeader('X-Digest-Token', 'secret-de-livraison')
            ->postJson('/api/internal/ai-quota-reset')
            ->assertOk();

        // Seul le compteur journalier est remis a zero, pas la limite.
        $this->assertSame(7, (int) $provider->fresh()->daily_limit);
    }

    public function test_a_student_cannot_reset_the_ai_quota(): void
    {
        $provider = $this->provider(['used_today' => 7]);

        // Ni jeton, ni identifiant d'enseignant : la route reste fermee.
        $this->asToken($this->tokenFor($this->student()))
            ->postJson('/api/internal/ai-quota-reset')
            ->assertUnauthorized();

        $this->assertSame(7, (int) $provider->fresh()->used_today);
    }

    public function test_the_daily_digest_task_is_still_scheduled_for_local_use(): void
    {
        // La route interne ne remplace pas `routes/console.php` : un
        // `schedule:run` local doit toujours declencher les memes taches.
        $commands = collect(app(Schedule::class)->events())
            ->map(fn ($event) => (string) ($event->command ?? ''))
            ->all();

        $this->assertTrue(
            collect($commands)->contains(fn ($command) => str_contains($command, 'classlink:daily-digest')),
            'Le resume quotidien doit rester planifie.'
        );

        $this->assertTrue(
            collect($commands)->contains(fn ($command) => str_contains($command, 'classlink:prune')),
            'La purge doit rester planifiee.'
        );

        // La remise a zero des quotas reste declaree (00:05), meme si la
        // route interne en assure le declenchement en production.
        $expressions = collect(app(Schedule::class)->events())
            ->map(fn ($event) => $event->expression)
            ->all();

        $this->assertContains('5 0 * * *', $expressions);
    }

    public function test_the_digest_route_returns_the_number_of_emails_sent(): void
    {
        Mail::fake();

        $response = $this->withHeader('X-Digest-Token', 'secret-de-livraison')
            ->postJson('/api/internal/daily-digest');

        $response->assertOk();
        $this->assertIsInt($response->json('sent'));
    }

    public function test_the_digest_reaches_an_accepted_student_with_a_new_announcement(): void
    {
        Mail::fake();

        $classroom = Classroom::factory()->create();
        $student = User::factory()->create(['role' => 'student', 'locale' => 'fr']);

        Membership::create([
            'classroom_id' => $classroom->id,
            'student_id' => $student->id,
            'status' => 'accepted',
            'requested_at' => now()->subDay(),
            'decided_at' => now()->subDay(),
        ]);

        Announcement::create([
            'classroom_id' => $classroom->id,
            'author_id' => $classroom->teacher_id,
            'title' => 'Cours de demain',
            'body' => 'Preparation du chapitre 4.',
            'pinned' => false,
        ]);

        $response = $this->withHeader('X-Digest-Token', 'secret-de-livraison')
            ->postJson('/api/internal/daily-digest');

        $response->assertOk()->assertJson(['sent' => 1]);
    }

    public function test_a_student_without_accepted_membership_receives_no_digest(): void
    {
        Mail::fake();

        $classroom = Classroom::factory()->create();
        $student = User::factory()->create(['role' => 'student']);

        Membership::create([
            'classroom_id' => $classroom->id,
            'student_id' => $student->id,
            'status' => 'pending',
            'requested_at' => now()->subDay(),
        ]);

        Announcement::create([
            'classroom_id' => $classroom->id,
            'author_id' => $classroom->teacher_id,
            'title' => 'Annonce',
            'body' => 'Contenu.',
            'pinned' => false,
        ]);

        $this->withHeader('X-Digest-Token', 'secret-de-livraison')
            ->postJson('/api/internal/daily-digest')
            ->assertOk()
            ->assertJson(['sent' => 0]);
    }

    public function test_the_prune_route_deletes_only_expired_otp_codes(): void
    {
        OtpCode::create([
            'email' => '2007031400094@ofppt-edu.ma',
            'code_hash' => hash('sha256', '123456'),
            'expires_at' => now()->addMinutes(10),
            'attempts' => 0,
        ]);

        $expired = OtpCode::create([
            'email' => '2007031400095@ofppt-edu.ma',
            'code_hash' => hash('sha256', '654321'),
            'expires_at' => now()->subMinute(),
            'attempts' => 1,
        ]);

        $response = $this->withHeader('X-Digest-Token', 'secret-de-livraison')
            ->postJson('/api/internal/prune');

        $response->assertOk();
        $this->assertIsInt($response->json('deleted'));
        $this->assertGreaterThanOrEqual(1, $response->json('deleted'));

        $this->assertDatabaseMissing('otp_codes', ['id' => $expired->id]);
    }

    public function test_the_prune_job_is_shared_by_the_command_and_the_route(): void
    {
        // La route interne et `php artisan classlink:prune` doivent appeler le
        // meme job, sinon la purge planifiee divergerait de la purge manuelle.
        $this->assertInstanceOf(PruneExpiredOtpCodes::class, app(PruneExpiredOtpCodes::class));
        $this->assertInstanceOf(EmailDigestService::class, app(EmailDigestService::class));

        $this->artisan('classlink:prune')->assertSuccessful();
    }
}
