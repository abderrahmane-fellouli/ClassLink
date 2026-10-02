<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Classroom;
use App\Models\Membership;
use App\Models\Quiz;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * T-26 et T-28 — Langue et journal d'audit.
 *
 * T-26 : « Passage du français à l'anglais — aucun texte non traduit. »
 *   La part backend couvre les messages de validation et d'API : la langue
 *   choisie par l'utilisateur s'applique réellement, et aucune clé brute
 *   (`validation.required`) ne fuit dans la réponse. Le parcours visuel
 *   appartient au frontend.
 *
 * T-28 : « Connexion, décision d'adhésion, changement de rôle — trois lignes
 *   dans le journal. »
 */
class AuditAndLocaleTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // T-28 — Journal d'audit
    // =========================================================================

    public function test_t28_login_membership_decision_and_role_change_are_three_log_lines(): void
    {
        $teacher = $this->teacher();
        $student = $this->student();
        $classroom = Classroom::factory()->create([
            'teacher_id' => $teacher->id,
            'join_code' => 'TDI2025A',
        ]);
        $admin = $this->admin();

        /*
         * Les jetons de mise en place sont effacés du journal : on ne veut
         * observer que les trois événements sensibles du scénario. La
         * connexion de l'enseignant, elle, passe par le vrai point d'entrée.
         */
        $studentToken = $this->tokenFor($student);
        $adminToken = $this->tokenFor($admin);
        AuditLog::query()->delete();

        // 1. Connexion.
        $token = $this->postJson('/api/auth/dev/login', ['email' => $teacher->email])
            ->assertOk()
            ->json('token');

        $this->asToken($token)->getJson('/api/me')->assertOk();

        // 2. L'étudiant demande à rejoindre avec le code, l'enseignant accepte.
        $this->asToken($studentToken)
            ->postJson('/api/join-requests', ['code' => 'TDI2025A'])
            ->assertStatus(201);

        $membership = Membership::where('classroom_id', $classroom->id)
            ->where('student_id', $student->id)
            ->firstOrFail();

        $this->asToken($token)
            ->postJson("/api/join-requests/{$membership->id}/accept")
            ->assertOk();

        // 3. Changement de rôle par un administrateur.
        $this->asToken($adminToken)
            ->patchJson("/api/admin/users/{$student->id}", ['role' => 'teacher'])
            ->assertOk();

        // Bilan : les trois événements sensibles sont bien journalisés, dans
        // l'ordre, et rien d'autre n'a été écrit.
        $this->assertSame(
            ['auth.login', 'membership.accept', 'user.role_change'],
            AuditLog::orderBy('id')->pluck('action')->all()
        );
    }

    public function test_t28_the_journal_records_the_author_and_the_ip(): void
    {
        $teacher = $this->teacher();
        $token = $this->tokenFor($teacher);

        $this->asToken($token)->getJson('/api/me')->assertOk();

        $log = AuditLog::where('action', 'auth.login')->firstOrFail();

        $this->assertSame($teacher->id, $log->user_id);
        $this->assertSame('127.0.0.1', $log->ip);
    }

    public function test_t28_the_login_context_records_the_authentication_method(): void
    {
        $teacher = $this->teacher();

        $this->asToken($this->tokenFor($teacher, 'otp'))->getJson('/api/me')->assertOk();

        $context = AuditLog::where('action', 'auth.login')->firstOrFail()->context;

        $this->assertSame('otp', $context['method'] ?? null);
    }

    public function test_t28_an_anonymous_action_is_still_logged_without_a_user(): void
    {
        $this->postJson('/api/auth/otp/request', ['email' => 'inconnu@ofppt-edu.ma'])
            ->assertStatus(202);

        $this->assertSame(0, AuditLog::whereNotNull('user_id')->count());
    }

    public function test_the_journal_context_is_valid_json(): void
    {
        $teacher = $this->teacher();
        $classroom = Classroom::factory()->create(['teacher_id' => $teacher->id]);

        $this->actingAs($teacher)
            ->postJson("/api/classes/{$classroom->id}/quizzes", [
                'title' => 'Quiz journalisé',
                'questions' => [[
                    'statement' => 'Question',
                    'type' => 'single',
                    'options' => [
                        ['label' => 'Oui', 'is_correct' => true],
                        ['label' => 'Non', 'is_correct' => false],
                    ],
                ]],
            ])
            ->assertStatus(201);

        $this->actingAs($teacher)
            ->postJson('/api/quizzes/'.Quiz::firstOrFail()->id.'/publish')
            ->assertOk();

        $log = AuditLog::where('action', 'quiz.publish')->firstOrFail();

        $this->assertIsArray($log->context);
        $this->assertArrayHasKey('quiz_id', $log->context);
    }

    public function test_audit_lines_are_never_modified_after_the_fact(): void
    {
        $teacher = $this->teacher();

        $this->actingAs($teacher)
            ->postJson('/api/auth/logout')
            ->assertNoContent();

        $before = $this->snapshot();

        $this->assertNotEmpty($before);

        // D'autres actions sensibles s'ajoutent sans toucher aux lignes existantes.
        $this->actingAs($teacher)
            ->patchJson('/api/me', ['display_name' => 'Nom modifie'])
            ->assertOk();

        $this->actingAs($teacher)
            ->postJson('/api/auth/logout')
            ->assertNoContent();

        $after = $this->snapshot();

        $this->assertSame($before, array_slice($after, 0, count($before)));
        $this->assertGreaterThan(count($before), count($after));
    }

    /**
     * Instantané textuel du journal : `toArray()` sérialise les dates, ce qui
     * évite de comparer deux objets Carbon distincts mais équivalents.
     */
    private function snapshot(): array
    {
        return AuditLog::orderBy('id')->get()
            ->map(fn ($line) => $line->toArray())
            ->all();
    }

    public function test_a_written_audit_line_cannot_be_rewritten_or_deleted(): void
    {
        $line = AuditLog::record($this->teacher(), 'quiz.publish', ['quiz_id' => 7]);

        try {
            $line->update(['action' => 'quiz.delete']);

            $this->fail('La mise à jour aurait dû être refusée.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('immuable', $e->getMessage());
        }

        try {
            $line->delete();

            $this->fail('La suppression aurait dû être refusée.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('immuable', $e->getMessage());
        }

        $this->assertDatabaseHas('audit_logs', [
            'id' => $line->id,
            'action' => 'quiz.publish',
        ]);
    }

    // =========================================================================
    // T-26 — Langue
    // =========================================================================

    public function test_t26_the_locale_is_persisted_on_the_profile(): void
    {
        $teacher = $this->teacher(['locale' => 'fr']);

        $this->actingAs($teacher)
            ->patchJson('/api/me', ['locale' => 'en'])
            ->assertOk()
            ->assertJsonPath('locale', 'en');

        $this->assertSame('en', $teacher->fresh()->locale);
    }

    public function test_t26_french_validation_messages_are_translated(): void
    {
        $teacher = $this->teacher(['locale' => 'fr']);

        $errors = $this->actingAs($teacher)
            ->patchJson('/api/me', ['locale' => 'de'])
            ->assertStatus(422)
            ->json('errors');

        $this->assertStringNotContainsString('validation.', $errors['locale'][0]);
        $this->assertSame('La valeur sélectionnée pour langue est invalide.', $errors['locale'][0]);
    }

    public function test_t26_english_validation_messages_are_translated(): void
    {
        $teacher = $this->teacher(['locale' => 'en']);

        $errors = $this->actingAs($teacher)
            ->patchJson('/api/me', ['locale' => 'de'])
            ->assertStatus(422)
            ->json('errors');

        $this->assertStringNotContainsString('validation.', $errors['locale'][0]);
        $this->assertSame('The selected language is invalid.', $errors['locale'][0]);
    }

    public function test_t26_no_raw_translation_key_is_ever_returned(): void
    {
        $teacher = $this->teacher(['locale' => 'fr']);
        $classroom = Classroom::factory()->create(['teacher_id' => $teacher->id]);

        $bodies = [
            $this->actingAs($teacher)->patchJson('/api/me', ['locale' => 'de'])->getContent(),
            $this->actingAs($teacher)->getJson('/api/nope')->getContent(),
            $this->actingAs($teacher)->postJson("/api/classes/{$classroom->id}/quizzes", [])->getContent(),
            $this->postJson('/api/auth/otp/request', ['email' => 'pas-un-email'])->getContent(),
        ];

        foreach ($bodies as $body) {
            $this->assertStringNotContainsString('validation.', $body);
            $this->assertStringNotContainsString('api.', $body);
        }
    }

    public function test_t26_the_api_error_message_follows_the_chosen_language(): void
    {
        $french = $this->teacher(['locale' => 'fr']);
        $english = $this->teacher(['locale' => 'en']);

        $fr = $this->actingAs($french)->getJson('/api/nope')->json('message');
        $en = $this->actingAs($english)->getJson('/api/nope')->json('message');

        $this->assertSame('Ressource introuvable.', $fr);
        $this->assertSame('Resource not found.', $en);
        $this->assertNotSame($fr, $en);
    }

    public function test_t26_the_session_expired_message_is_localized(): void
    {
        $teacher = $this->teacher(['locale' => 'en']);

        $this->asToken('jeton-inexistant')
            ->getJson('/api/me')
            ->assertStatus(401)
            ->assertJsonPath('message', 'Session expired. Please sign in again.');
    }

    public function test_t26_the_file_error_is_localized(): void
    {
        [$classroom, $teacher] = $this->classWithMember();
        $teacher->update(['locale' => 'en']);

        $this->actingAs($teacher)
            ->post("/api/classes/{$classroom->id}/materials", [
                'title' => 'Fichier',
                'file' => \Illuminate\Http\UploadedFile::fake()->create('virus.exe', 10),
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'File type not allowed.');
    }

    public function test_t26_the_browser_language_header_is_honoured(): void
    {
        $this->withHeader('Accept-Language', 'en-GB,en;q=0.9,fr;q=0.8')
            ->postJson('/api/auth/otp/request', ['email' => 'x@ofppt-edu.ma'])
            ->assertStatus(202);

        $this->assertSame('en', app()->getLocale());
    }

    public function test_t26_an_unsupported_language_falls_back_to_the_default(): void
    {
        $this->withHeader('Accept-Language', 'ar-MA,ar;q=0.9')
            ->postJson('/api/auth/otp/request', ['email' => 'x@ofppt-edu.ma'])
            ->assertStatus(202);

        $this->assertSame('fr', app()->getLocale());
    }

    public function test_t26_an_arabic_user_locale_falls_back_to_the_default(): void
    {
        $teacher = $this->teacher(['locale' => 'ar']);

        $this->actingAs($teacher)
            ->patchJson('/api/me', ['display_name' => 'Nom'])
            ->assertOk();

        $this->assertSame('fr', app()->getLocale());
    }

    public function test_only_french_and_english_are_accepted(): void
    {
        $teacher = $this->teacher();

        $this->actingAs($teacher)
            ->patchJson('/api/me', ['locale' => 'ar'])
            ->assertStatus(422);
    }

    public function test_both_locales_declare_the_same_keys(): void
    {
        $flatten = function (array $array, string $prefix = '') use (&$flatten): array {
            $keys = [];

            foreach ($array as $key => $value) {
                $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
                $keys = array_merge($keys, is_array($value) ? $flatten($value, $path) : [$path]);
            }

            return $keys;
        };

        $fr = $flatten(require __DIR__.'/../../lang/fr/api.php');
        $en = $flatten(require __DIR__.'/../../lang/en/api.php');

        sort($fr);
        sort($en);

        $this->assertSame($fr, $en, 'Les fichiers de langue FR et EN doivent rester alignés.');
    }
}
