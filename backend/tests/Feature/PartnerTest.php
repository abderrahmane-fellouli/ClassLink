<?php

namespace Tests\Feature;

use App\Models\Classroom;
use App\Models\Membership;
use App\Models\PartnerProfile;
use App\Models\PartnerRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * F-PAR-01 à F-PAR-04, RG-17, RG-18 — Partenaires d'étude.
 *
 * RG-17 : « Le profil partenaire est désactivé par défaut. Il n'est visible
 * que par les membres des mêmes classes et n'affiche jamais l'email. »
 *
 * Ces tests couvrent le chemin d'écriture `POST /partner-requests`, qui
 * n'était auparavant couvert par aucun test alors que c'est le seul moyen de
 * contourner le filtre `opt_in` appliqué par `candidates()`.
 */
class PartnerTest extends TestCase
{
    use RefreshDatabase;

    private Classroom $classroom;

    private User $teacher;

    private User $sender;

    /** camarade ayant activé son profil (opt-in). */
    private User $optedIn;

    /** camarade ayant explicitement refusé (opt-out). */
    private User $optedOut;

    /** camarade n'ayant jamais ouvert son profil. */
    private User $noProfile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = $this->teacher();
        $this->classroom = Classroom::factory()->create(['teacher_id' => $this->teacher->id]);

        $this->sender = $this->accept($this->student());
        $this->optedIn = $this->accept($this->student());
        $this->optedOut = $this->accept($this->student());
        $this->noProfile = $this->accept($this->student());

        $this->optIn($this->optedIn);
        $this->optIn($this->optedOut, enabled: false);
    }

    private function accept(User $student): User
    {
        Membership::create([
            'classroom_id' => $this->classroom->id,
            'student_id' => $student->id,
            'status' => 'accepted',
            'requested_at' => now(),
            'decided_at' => now(),
            'decided_by' => $this->teacher->id,
        ]);

        return $student;
    }

    private function optIn(User $student, bool $enabled = true): void
    {
        PartnerProfile::create([
            'user_id' => $student->id,
            'opt_in' => $enabled,
            'skills' => ['algorithme'],
            'availability' => ['mardi'],
        ]);
    }

    private function send(User $from, User $to): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($from)->postJson('/api/partner-requests', [
            'to_user_id' => $to->id,
            'classroom_id' => $this->classroom->id,
        ]);
    }

    // -- F-PAR-01 : opt-in, désactivé par défaut -------------------------------

    public function test_the_partner_profile_is_disabled_by_default(): void
    {
        // Les ressources ne sont pas enveloppées (`JsonResource::withoutWrapping()`).
        $response = $this->actingAs($this->sender)
            ->getJson('/api/me/partner-profile')
            ->assertSuccessful();

        $this->assertFalse((bool) $response->json('opt_in'));
        $this->assertSame($this->sender->id, (int) $response->json('user_id'));
    }

    public function test_a_student_can_enable_their_own_profile(): void
    {
        $response = $this->actingAs($this->sender)
            ->putJson('/api/me/partner-profile', [
                'opt_in' => true,
                'skills' => ['algorithme'],
                'availability' => ['mardi'],
            ])
            ->assertOk();

        $this->assertTrue((bool) $response->json('opt_in'));
        $this->assertDatabaseHas('partner_profiles', [
            'user_id' => $this->sender->id,
            'opt_in' => true,
        ]);
    }

    // -- F-PAR-02 : recherche limitée aux membres ayant activé leur profil ---

    public function test_candidates_only_lists_students_who_opted_in(): void
    {
        $ids = collect(
            $this->actingAs($this->sender)
                ->getJson("/api/classes/{$this->classroom->id}/partners")
                ->assertOk()
                ->json('data')
        )->pluck('user_id')->all();

        $this->assertContains($this->optedIn->id, $ids);
        $this->assertNotContains($this->optedOut->id, $ids, 'Un profil désactivé ne doit jamais être proposé.');
        $this->assertNotContains($this->noProfile->id, $ids);
        $this->assertNotContains($this->sender->id, $ids, 'On ne se propose pas soi-même.');
    }

    public function test_candidates_never_expose_an_email(): void
    {
        $response = $this->actingAs($this->sender)
            ->getJson("/api/classes/{$this->classroom->id}/partners")
            ->assertOk();

        $this->assertStringNotContainsString('@ofppt-edu.ma', $response->getContent());
        $this->assertStringNotContainsString('email', $response->getContent());
    }

    // -- RG-17 : le consentement bloque la demande -----------------------------

    public function test_an_opted_in_classmate_can_be_contacted(): void
    {
        $response = $this->send($this->sender, $this->optedIn)->assertStatus(201);

        $this->assertSame('pending', $response->json('status'));
        $this->assertSame('outgoing', $response->json('direction'));
        $this->assertSame($this->optedIn->id, (int) $response->json('counterpart.id'));

        // F-PAR-03 : le destinataire est notifié dans l'application.
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->optedIn->id,
            'type' => 'partner_request_received',
        ]);
    }

    public function test_an_opted_out_classmate_cannot_be_contacted(): void
    {
        $this->send($this->sender, $this->optedOut)->assertStatus(403);

        $this->assertSame(0, PartnerRequest::where('to_user_id', $this->optedOut->id)->count());
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $this->optedOut->id,
            'type' => 'partner_request_received',
        ]);
    }

    public function test_a_student_without_a_profile_cannot_be_contacted(): void
    {
        // RG-17 : ne jamais créer de profil à la place du camarade.
        $this->send($this->sender, $this->noProfile)->assertStatus(403);

        $this->assertSame(0, PartnerRequest::where('to_user_id', $this->noProfile->id)->count());
        $this->assertDatabaseMissing('partner_profiles', ['user_id' => $this->noProfile->id]);
    }

    public function test_opting_out_after_a_request_blocks_new_requests(): void
    {
        $this->send($this->sender, $this->optedIn)->assertStatus(201);

        $this->actingAs($this->optedIn)
            ->putJson('/api/me/partner-profile', ['opt_in' => false])
            ->assertOk();

        $other = $this->accept($this->student());
        $this->optIn($other);

        $this->send($other, $this->optedIn)->assertStatus(403);
    }

    // -- Frontières d'autorisation (RG-04, RG-05, RG-17) ---------------------

    public function test_a_student_cannot_contact_someone_outside_the_class(): void
    {
        $outsiderClass = Classroom::factory()->create(['teacher_id' => $this->teacher->id]);
        $outsider = $this->student();
        $this->optIn($outsider);

        $this->actingAs($this->sender)->postJson('/api/partner-requests', [
            'to_user_id' => $outsider->id,
            'classroom_id' => $outsiderClass->id,
        ])->assertStatus(403);

        $this->assertSame(0, PartnerRequest::where('to_user_id', $outsider->id)->count());
    }

    public function test_a_teacher_cannot_send_a_partner_request(): void
    {
        $this->actingAs($this->teacher)
            ->postJson('/api/partner-requests', [
                'to_user_id' => $this->optedIn->id,
                'classroom_id' => $this->classroom->id,
            ])
            ->assertStatus(403);
    }

    public function test_only_the_recipient_can_answer_a_request(): void
    {
        $requestId = $this->send($this->sender, $this->optedIn)
            ->assertStatus(201)
            ->json('id');

        $this->actingAs($this->sender)
            ->postJson("/api/partner-requests/{$requestId}/respond", ['status' => 'accepted'])
            ->assertStatus(403);

        $this->assertSame('pending', PartnerRequest::findOrFail($requestId)->status);
    }

    public function test_the_recipient_can_accept_or_refuse(): void
    {
        $requestId = $this->send($this->sender, $this->optedIn)
            ->assertStatus(201)
            ->json('id');

        $this->actingAs($this->optedIn)
            ->postJson("/api/partner-requests/{$requestId}/respond", ['status' => 'accepted'])
            ->assertOk()
            ->assertJsonPath('status', 'accepted');

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->sender->id,
            'type' => 'partner_request_answered',
        ]);
    }

    public function test_a_duplicate_pending_request_is_refused_with_409(): void
    {
        $this->send($this->sender, $this->optedIn)->assertStatus(201);
        $this->send($this->sender, $this->optedIn)->assertStatus(409);

        $this->assertSame(1, PartnerRequest::where('from_user_id', $this->sender->id)->count());
    }

    public function test_responses_never_expose_an_email(): void
    {
        $response = $this->actingAs($this->optedIn)
            ->getJson('/api/me/partner-requests')
            ->assertOk();

        $this->assertStringNotContainsString('@ofppt-edu.ma', $response->getContent());
    }
}