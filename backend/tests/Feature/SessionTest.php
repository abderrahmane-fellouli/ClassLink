<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * T-23, T-24, T-25 — Sessions et jetons (§7 RG-19 ; F-AUTH-06, F-AUTH-07).
 *
 * Ces tests utilisent de VRAIS jetons et l'en-tête `Authorization: Bearer`,
 * afin d'exercer exactement le chemin de production ( Sanctum + expiration).
 */
class SessionTest extends TestCase
{
    use RefreshDatabase;

    // -- T-23 : apres deconnexion, l'jeton ne fonctionne plus --------------------

    public function test_t23_logout_invalidates_the_token(): void
    {
        $user = $this->student();
        $token = $this->tokenFor($user);

        // Le jeton fonctionne.
        $this->asToken($token)
            ->getJson('/api/me')
            ->assertOk();

        $this->asToken($token)
            ->postJson('/api/auth/logout')
            ->assertStatus(204);

        // T-23 : la requete suivante avec l'ancien jeton renvoie 401.
        $this->asToken($token)
            ->getJson('/api/me')
            ->assertStatus(401);

        $this->assertDatabaseMissing('personal_access_tokens', ['token' => hash('sha256', explode('|', $token)[1])]);
    }

    public function test_t23_logout_is_audited(): void
    {
        $user = $this->student();
        $token = $this->tokenFor($user);

        $this->asToken($token)
            ->postJson('/api/auth/logout')
            ->assertStatus(204);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'auth.logout',
            'user_id' => $user->id,
        ]);
    }

    // -- RG-19 : duree de vie de 8 heures ---------------------------------------

    public function test_rg19_token_expires_after_eight_hours(): void
    {
        $user = $this->student();
        $token = $this->tokenFor($user);

        $this->asToken($token)
            ->getJson('/api/me')
            ->assertOk();

        $plain = explode('|', $token)[1];

        $record = PersonalAccessToken::where('token', hash('sha256', $plain))->firstOrFail();

        // RG-19 : expiration a exactement 8 heures.
        $this->assertEqualsWithDelta(
            Carbon::now()->addHours(8)->timestamp,
            $record->expires_at->timestamp,
            5
        );
    }

    // -- T-25 : un jeton expire renvoie 401 avec un message explicite -----------

    public function test_t25_expired_token_returns_401_with_an_explicit_message(): void
    {
        $user = $this->student();
        $token = $this->tokenFor($user);

        $plain = explode('|', $token)[1];

        PersonalAccessToken::where('token', hash('sha256', $plain))
            ->update(['expires_at' => Carbon::now()->subMinute()]);

        $response = $this->asToken($token)
            ->getJson('/api/me')
            ->assertStatus(401);

        $response->assertJsonPath('code', 'session_expired');
        $this->assertNotEmpty($response->json('message'));
    }

    public function test_a_random_or_tampered_token_is_rejected(): void
    {
        foreach ([
            'jeton vide' => '',
            'jeton bidon' => '1|abcdefghijklmnopqrstuvwxyz0123456789',
            'jeton sans prefixe' => 'abcdefghijklmnop',
        ] as $label => $value) {
            $this->withHeaders(['Authorization' => 'Bearer '.$value])
                ->getJson('/api/me')
                ->assertStatus(401, $label);
        }
    }

    // -- F-AUTH-07 : « Se deconnecter de toutes les sessions » ------------------

    public function test_destroying_all_sessions_invalidates_every_token(): void
    {
        $user = $this->student();

        $phone = $this->tokenFor($user);
        $computer = $this->tokenFor($user);

        $this->asToken($phone)->getJson('/api/me')->assertOk();
        $this->asToken($computer)->getJson('/api/me')->assertOk();

        $this->asToken($phone)
            ->deleteJson('/api/me/sessions')
            ->assertStatus(204);

        $this->asToken($phone)->getJson('/api/me')->assertStatus(401);
        $this->asToken($computer)->getJson('/api/me')->assertStatus(401);

        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.logout_all']);
    }

    public function test_destroying_all_sessions_keeps_the_other_users_sessions(): void
    {
        $user = $this->student();
        $other = $this->student();

        $mine = $this->tokenFor($user);
        $theirs = $this->tokenFor($other);

        $this->asToken($mine)->deleteJson('/api/me/sessions')->assertStatus(204);

        $this->asToken($theirs)->getJson('/api/me')->assertOk();
    }

    // -- RG-19 : purge des jetons expires (§11) ---------------------------------

    public function test_purge_removes_only_expired_tokens(): void
    {
        $keep = $this->tokenFor($this->student());
        $drop = $this->tokenFor($this->student());

        $dropPlain = explode('|', $drop)[1];

        PersonalAccessToken::where('token', hash('sha256', $dropPlain))
            ->update(['expires_at' => Carbon::now()->subDay()]);

        $deleted = app(TokenService::class)->purgeExpired();

        $this->assertSame(1, $deleted);

        $this->asToken($keep)->getJson('/api/me')->assertOk();
        $this->asToken($drop)->getJson('/api/me')->assertStatus(401);
    }

    // -- Aucune donnee sensible dans le profil ----------------------------------

    public function test_me_never_exposes_a_password_field(): void
    {
        $user = $this->student();

        $response = $this->asToken($this->tokenFor($user))
            ->getJson('/api/me')
            ->assertOk();

        $this->assertStringNotContainsString('password', $response->getContent());
    }

    public function test_there_is_no_password_column_at_all(): void
    {
        // §2.1 : la connexion se fait par Microsoft ou par code à usage
        // unique. Aucun mot de passe n'est stocké, nulle part.
        $this->assertFalse(
            \Illuminate\Support\Facades\Schema::hasColumn('users', 'password')
        );

        $this->assertFalse(
            \Illuminate\Support\Facades\Schema::hasColumn('users', 'password_reset_token')
        );
    }

    public function test_there_is_no_birth_date_column(): void
    {
        // §12 : aucune date de naissance n'est collectée.
        $this->assertFalse(
            \Illuminate\Support\Facades\Schema::hasColumn('users', 'birth_date')
        );
    }

    // -- F-AUTH-05 : un compte « pending » ne peut pas appeler l'API -----------

    public function test_a_pending_account_cannot_use_the_api(): void
    {
        $pending = \App\Models\User::factory()->pending()->create();

        $this->asToken($this->tokenFor($pending))
            ->getJson('/api/me')
            ->assertStatus(403)
            ->assertJsonPath('role', 'pending');
    }

    public function test_an_inactive_account_cannot_use_the_api(): void
    {
        $inactive = $this->student();
        $token = $this->tokenFor($inactive);

        $inactive->update(['is_active' => false]);

        $this->asToken($token)
            ->getJson('/api/me')
            ->assertStatus(403);
    }

    // -- Un seul audit par emission de jeton ------------------------------------

    public function test_issuing_a_token_writes_exactly_one_login_log(): void
    {
        $user = $this->student();

        $this->tokenFor($user);

        $this->assertSame(
            1,
            AuditLog::where('action', 'auth.login')->where('user_id', $user->id)->count()
        );
    }
}
