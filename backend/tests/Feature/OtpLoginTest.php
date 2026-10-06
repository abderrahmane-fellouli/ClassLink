<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Message;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * T-05, T-06 — Connexion par code a usage unique (F-AUTH-02, §16).
 */
class OtpLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_smtp_failure_is_not_reported_as_success_and_invalidates_code(): void
    {
        Mail::shouldReceive('raw')->once()
            ->andThrow(new \RuntimeException('SMTP password must not leak'));
        $response = $this->withHeader('Accept-Language', 'en')
            ->postJson('/api/auth/otp/request', ['email' => '2007031400094@ofppt-edu.ma']);
        $response->assertStatus(503);
        $this->assertStringNotContainsString('SMTP password', $response->getContent());
        $this->assertDatabaseCount('otp_codes', 0);
    }

    public function test_english_otp_email_and_subject_are_localized(): void
    {
        Mail::shouldReceive('raw')->once()
            ->withArgs(function ($body, $callback) {
                $this->assertStringContainsString('Your ClassLink code is:', $body);
                $message = new Message(new Email);
                $callback($message);
                $this->assertSame('Your ClassLink sign-in code', $message->getSymfonyMessage()->getSubject());

                return true;
            });
        $this->withHeader('Accept-Language', 'en')
            ->postJson('/api/auth/otp/request', ['email' => '2007031400094@ofppt-edu.ma'])->assertStatus(202);
    }

    public function test_delivery_diagnostics_never_log_secrets_otp_or_provider_payloads(): void
    {
        // All values here are synthetic; no real provider credentials are used.
        config()->set('mail.default', 'smtp');
        config()->set('mail.mailers.smtp', [
            'host' => 'smtp-relay.brevo.com', 'port' => 587,
            'scheme' => null, 'encryption' => 'tls', 'require_tls' => true,
            'username' => 'synthetic-user', 'password' => 'synthetic-password',
            'url' => 'smtp://synthetic-user:synthetic-password@smtp-relay.brevo.com:587',
        ]);
        config()->set('mail.from.address', 'sender@example.test');
        $bodySent = null;
        Mail::shouldReceive('raw')->once()->andReturnUsing(function ($body) use (&$bodySent) {
            $bodySent = $body;
            throw new TransportException('Failed to authenticate synthetic-user synthetic-password '.base64_encode('synthetic-password').' bearer-token '.$body, 535);
        });
        Log::shouldReceive('warning')->once()->withArgs(function ($message, $context) use (&$bodySent) {
            $this->assertSame('OTP email delivery failed', $message);
            $this->assertSame(TransportException::class, $context['exception_class']);
            $this->assertSame('authentication_failed', $context['error_category']);
            $this->assertSame('SMTP authentication failed.', $context['sanitized_message']);
            $this->assertSame(535, $context['smtp_response_code']);
            $this->assertTrue($context['mail_url_configured']);
            $this->assertTrue($context['smtp_host_is_brevo']);
            $this->assertTrue($context['smtp_username_configured']);
            $this->assertTrue($context['smtp_password_configured']);
            $serialized = json_encode($context);
            foreach (['synthetic-user', 'synthetic-password', base64_encode('synthetic-password'), 'bearer-token', $bodySent, '2007031400094@ofppt-edu.ma'] as $secret) {
                $this->assertStringNotContainsString($secret, $serialized);
            }
            preg_match('/\b[0-9]{6}\b/', $bodySent, $matches);
            $this->assertNotEmpty($matches);
            $this->assertStringNotContainsString($matches[0], $serialized);
            foreach ($context as $value) {
                $this->assertFalse(is_object($value));
            }

            return true;
        });
        // Laravel separately reports the generic BusinessRuleException.
        Log::shouldReceive('error')->andReturnNull();
        $this->withHeader('Accept-Language', 'en')
            ->postJson('/api/auth/otp/request', ['email' => '2007031400094@ofppt-edu.ma'])
            ->assertStatus(503)
            ->assertJsonPath('message', __('api.otp.delivery_failed'));
        $this->assertDatabaseCount('otp_codes', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    /**
     * Remplace le code hache par un code connu du test, via exactement le
     * meme mecanisme que le modele (§11 : stockage hache uniquement).
     */
    private function withKnownCode(string $email): string
    {
        $otp = OtpCode::where('email', $email)->latest('id')->firstOrFail();

        $plain = '654321';

        $otp->update(['code_hash' => OtpCode::hash($plain), 'attempts' => 0]);

        return $plain;
    }

    // -- T-05 : code correct dans les 10 minutes ------------------------------

    public function test_t05_valid_code_within_ten_minutes_issues_a_token(): void
    {
        $this->postJson('/api/auth/otp/request', ['email' => '2007031400094@ofppt-edu.ma'])
            ->assertStatus(202);

        $code = $this->withKnownCode('2007031400094@ofppt-edu.ma');

        $response = $this->postJson('/api/auth/otp/verify', [
            'email' => '2007031400094@ofppt-edu.ma',
            'code' => $code,
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'email', 'display_name', 'role']]);

        $this->assertIsString($response->json('token'));
        $this->assertNotEmpty($response->json('token'));

        // RG-02 : le compte cree automatiquement est bien un etudiant.
        $this->assertSame(Role::Student->value, $response->json('user.role'));
        $this->assertDatabaseHas('users', [
            'email' => '2007031400094@ofppt-edu.ma',
            'role' => Role::Student->value,
        ]);

        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login']);
    }

    // -- T-06 : code expire ----------------------------------------------------

    public function test_t06_expired_code_is_refused_and_issues_no_token(): void
    {
        $this->postJson('/api/auth/otp/request', ['email' => '2007031400094@ofppt-edu.ma'])
            ->assertStatus(202);

        $otp = OtpCode::where('email', '2007031400094@ofppt-edu.ma')->latest('id')->firstOrFail();
        $code = $this->withKnownCode('2007031400094@ofppt-edu.ma');

        // Depasse de 10 minutes.
        $otp->update(['expires_at' => Carbon::now()->subMinute()]);

        $before = User::count();

        $response = $this->postJson('/api/auth/otp/verify', [
            'email' => '2007031400094@ofppt-edu.ma',
            'code' => $code,
        ]);

        $response->assertStatus(422);
        $this->assertArrayNotHasKey('token', $response->json());
        $this->assertSame($before, User::count());

        // Un code expire n'est meme pas comptabilise comme un essai.
        $this->assertSame(0, $otp->fresh()->attempts);
    }

    // -- T-06 : sixieme essai --------------------------------------------------

    public function test_t06_sixth_attempt_is_refused_even_with_the_correct_code(): void
    {
        $this->postJson('/api/auth/otp/request', ['email' => '2007031400094@ofppt-edu.ma'])
            ->assertStatus(202);

        $code = $this->withKnownCode('2007031400094@ofppt-edu.ma');
        $otp = OtpCode::where('email', '2007031400094@ofppt-edu.ma')->latest('id')->firstOrFail();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/otp/verify', [
                'email' => '2007031400094@ofppt-edu.ma',
                'code' => '111111',
            ])->assertStatus(422);
        }

        $this->assertSame(5, $otp->fresh()->attempts);

        $response = $this->postJson('/api/auth/otp/verify', [
            'email' => '2007031400094@ofppt-edu.ma',
            'code' => $code,
        ]);

        $response->assertStatus(422);
        $this->assertArrayNotHasKey('token', $response->json());
        $this->assertDatabaseCount('users', 0);
    }

    // -- RG-01 : aucun code pour un domaine etranger --------------------------

    public function test_no_code_is_created_for_a_foreign_domain(): void
    {
        $this->postJson('/api/auth/otp/request', ['email' => 'x@gmail.com'])
            ->assertStatus(202);

        $this->assertDatabaseCount('otp_codes', 0);
    }

    public function test_request_response_is_identical_for_known_and_unknown_addresses(): void
    {
        $known = $this->postJson('/api/auth/otp/request', ['email' => '2007031400094@ofppt-edu.ma']);
        $unknown = $this->postJson('/api/auth/otp/request', ['email' => '2007031400999@ofppt-edu.ma']);

        $known->assertStatus(202);
        $unknown->assertStatus(202);

        $this->assertSame($known->json(), $unknown->json());
    }

    // -- RG-01 : adresse mal formee rejetee par la validation ------------------

    public function test_malformed_address_with_allowed_domain_is_rejected(): void
    {
        $this->postJson('/api/auth/otp/request', ['email' => 'a@b@ofppt-edu.ma'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('otp_codes', 0);
    }

    // -- §11 : le code n'est jamais stocke en clair -----------------------------

    public function test_code_is_stored_hashed_only(): void
    {
        $this->postJson('/api/auth/otp/request', ['email' => '2007031400094@ofppt-edu.ma'])
            ->assertStatus(202);

        $otp = OtpCode::where('email', '2007031400094@ofppt-edu.ma')->latest('id')->firstOrFail();

        $this->assertNotSame('654321', $otp->code_hash);
        $this->assertSame(64, strlen($otp->code_hash));
        $this->assertSame(OtpCode::hash('654321'), OtpCode::hash('654321'));

        // Le schema ne contient aucune colonne « code en clair ».
        $this->assertArrayNotHasKey('plain_code', $otp->getAttributes());
    }

    // -- Usage unique ----------------------------------------------------------

    public function test_code_cannot_be_reused(): void
    {
        $this->postJson('/api/auth/otp/request', ['email' => '2007031400094@ofppt-edu.ma']);
        $code = $this->withKnownCode('2007031400094@ofppt-edu.ma');

        $this->postJson('/api/auth/otp/verify', [
            'email' => '2007031400094@ofppt-edu.ma', 'code' => $code,
        ])->assertOk();

        $this->postJson('/api/auth/otp/verify', [
            'email' => '2007031400094@ofppt-edu.ma', 'code' => $code,
        ])->assertStatus(422);
    }

    // -- Un nouveau code invalide le precedent ---------------------------------

    public function test_requesting_again_invalidates_the_previous_code(): void
    {
        $this->postJson('/api/auth/otp/request', ['email' => '2007031400094@ofppt-edu.ma']);
        $first = $this->withKnownCode('2007031400094@ofppt-edu.ma');

        $this->postJson('/api/auth/otp/request', ['email' => '2007031400094@ofppt-edu.ma']);

        $this->postJson('/api/auth/otp/verify', [
            'email' => '2007031400094@ofppt-edu.ma', 'code' => $first,
        ])->assertStatus(422);
    }

    // -- Compte inactif --------------------------------------------------------

    public function test_inactive_account_is_refused(): void
    {
        User::factory()->create([
            'email' => '2007031400099@ofppt-edu.ma',
            'is_active' => false,
        ]);

        $this->postJson('/api/auth/otp/request', ['email' => '2007031400099@ofppt-edu.ma']);
        $code = $this->withKnownCode('2007031400099@ofppt-edu.ma');

        $this->postJson('/api/auth/otp/verify', [
            'email' => '2007031400099@ofppt-edu.ma', 'code' => $code,
        ])->assertStatus(422);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
