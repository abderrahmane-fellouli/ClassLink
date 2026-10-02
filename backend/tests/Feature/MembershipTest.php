<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Classroom;
use App\Models\Membership;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * T-07 a T-10 — Adhesions (F-REQ-01, §7 RG-06 a RG-10).
 */
class MembershipTest extends TestCase
{
    use RefreshDatabase;

    private Classroom $classroom;

    private User $teacher;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = $this->teacher();
        $this->student = $this->student();
        $this->classroom = Classroom::factory()->create([
            'teacher_id' => $this->teacher->id,
            'join_code' => 'TDI2025A',
            'join_enabled' => true,
            'status' => 'active',
        ]);
    }

    // -- T-07 : code valide -> pending + notification a l'enseignant -----------

    public function test_t07_valid_code_creates_a_pending_request_and_notifies_the_teacher(): void
    {
        $response = $this->actingAs($this->student)
            ->postJson('/api/join-requests', ['code' => 'TDI2025A']);

        $response->assertStatus(201);

        $this->assertDatabaseHas('memberships', [
            'classroom_id' => $this->classroom->id,
            'student_id' => $this->student->id,
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->teacher->id,
            'type' => NotificationService::JOIN_REQUESTED,
        ]);
    }

    public function test_join_code_is_case_and_space_insensitive(): void
    {
        $this->actingAs($this->student)
            ->postJson('/api/join-requests', ['code' => '  tdi2025a  '])
            ->assertStatus(201);

        $this->assertDatabaseHas('memberships', [
            'classroom_id' => $this->classroom->id,
            'status' => 'pending',
        ]);
    }

    // -- T-08 : deuxieme demande -> 409, aucune duplication --------------------

    public function test_t08_second_request_for_the_same_class_returns_409(): void
    {
        $this->actingAs($this->student)
            ->postJson('/api/join-requests', ['code' => 'TDI2025A'])
            ->assertStatus(201);

        $this->actingAs($this->student)
            ->postJson('/api/join-requests', ['code' => 'TDI2025A'])
            ->assertStatus(409);

        // Aucune ligne dupliquee.
        $this->assertSame(1, Membership::where('student_id', $this->student->id)->count());
    }

    public function test_t08_accepted_member_cannot_request_again(): void
    {
        Membership::create([
            'classroom_id' => $this->classroom->id,
            'student_id' => $this->student->id,
            'status' => 'accepted',
            'requested_at' => now(),
            'decided_at' => now(),
            'decided_by' => $this->teacher->id,
        ]);

        $this->actingAs($this->student)
            ->postJson('/api/join-requests', ['code' => 'TDI2025A'])
            ->assertStatus(409);
    }

    // -- T-09 : nouvelle demande 1 h apres un rejet -> 429 ----------------------

    public function test_t09_new_request_one_hour_after_rejection_returns_429(): void
    {
        Membership::create([
            'classroom_id' => $this->classroom->id,
            'student_id' => $this->student->id,
            'status' => 'rejected',
            'requested_at' => Carbon::now()->subDays(2),
            'decided_at' => Carbon::now()->subHour(), // RG-07 : il y a 1 h
            'decided_by' => $this->teacher->id,
        ]);

        $this->actingAs($this->student)
            ->postJson('/api/join-requests', ['code' => 'TDI2025A'])
            ->assertStatus(429);
    }

    public function test_request_after_the_cooldown_is_allowed(): void
    {
        Membership::create([
            'classroom_id' => $this->classroom->id,
            'student_id' => $this->student->id,
            'status' => 'rejected',
            'requested_at' => Carbon::now()->subDays(2),
            'decided_at' => Carbon::now()->subHours(25), // > 24 h
            'decided_by' => $this->teacher->id,
        ]);

        $this->actingAs($this->student)
            ->postJson('/api/join-requests', ['code' => 'TDI2025A'])
            ->assertStatus(201);

        $this->assertDatabaseHas('memberships', [
            'classroom_id' => $this->classroom->id,
            'student_id' => $this->student->id,
            'status' => 'pending',
        ]);
    }

    // -- T-10 : code desactive -> 404 ------------------------------------------

    public function test_t10_disabled_code_returns_404_invalid_code(): void
    {
        $this->classroom->update(['join_enabled' => false]);

        $this->actingAs($this->student)
            ->postJson('/api/join-requests', ['code' => 'TDI2025A'])
            ->assertStatus(404);
    }

    public function test_t10_unknown_code_returns_404(): void
    {
        // Code bien forme (8 caracteres) mais inconnu de la base.
        $this->actingAs($this->student)
            ->postJson('/api/join-requests', ['code' => 'INCONNU1'])
            ->assertStatus(404);
    }

    public function test_malformed_code_is_a_validation_error(): void
    {
        // Un code qui n'a pas la longueur reglementaire est rejete avant
        // meme d'atteindre la regle metier.
        $this->actingAs($this->student)
            ->postJson('/api/join-requests', ['code' => 'COURT'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    public function test_t10_archived_class_refuses_new_requests(): void
    {
        // RG-10 : une classe archivée n'accepte plus de nouvelle adhésion.
        $this->classroom->update(['status' => 'archived', 'archived_at' => now()]);

        $this->actingAs($this->student)
            ->postJson('/api/join-requests', ['code' => 'TDI2025A'])
            ->assertStatus(404);
    }

    // -- RG-07 : le cooldown est annonce a l'etudiant -------------------------

    public function test_rejection_notification_announces_the_cooldown(): void
    {
        $membership = Membership::create([
            'classroom_id' => $this->classroom->id,
            'student_id' => $this->student->id,
            'status' => 'pending',
            'requested_at' => now(),
        ]);

        $this->actingAs($this->teacher)
            ->postJson("/api/join-requests/{$membership->id}/reject")
            ->assertStatus(200);

        $notification = AppNotification::where('user_id', $this->student->id)
            ->where('type', NotificationService::MEMBERSHIP_REJECTED)
            ->firstOrFail();

        $this->assertSame(24, $notification->payload['cooldown_hours'] ?? null);
    }

    // -- Acceptation -----------------------------------------------------------

    public function test_teacher_can_accept_and_student_is_notified(): void
    {
        $membership = Membership::create([
            'classroom_id' => $this->classroom->id,
            'student_id' => $this->student->id,
            'status' => 'pending',
            'requested_at' => now(),
        ]);

        $this->actingAs($this->teacher)
            ->postJson("/api/join-requests/{$membership->id}/accept")
            ->assertStatus(200);

        $this->assertDatabaseHas('memberships', [
            'id' => $membership->id,
            'status' => 'accepted',
            'decided_by' => $this->teacher->id,
        ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->student->id,
            'type' => NotificationService::MEMBERSHIP_ACCEPTED,
        ]);

        $this->assertDatabaseHas('audit_logs', ['action' => 'membership.accept']);
    }

    public function test_another_teacher_cannot_accept_the_request(): void
    {
        $other = $this->teacher();

        $membership = Membership::create([
            'classroom_id' => $this->classroom->id,
            'student_id' => $this->student->id,
            'status' => 'pending',
            'requested_at' => now(),
        ]);

        $this->actingAs($other)
            ->postJson("/api/join-requests/{$membership->id}/accept")
            ->assertStatus(403);

        $this->assertDatabaseHas('memberships', ['id' => $membership->id, 'status' => 'pending']);
    }

    public function test_accept_all_only_accepts_pending_requests(): void
    {
        Membership::create([
            'classroom_id' => $this->classroom->id,
            'student_id' => $this->student->id,
            'status' => 'pending',
            'requested_at' => now(),
        ]);

        $accepted = Membership::create([
            'classroom_id' => $this->classroom->id,
            'student_id' => $this->student()->id,
            'status' => 'accepted',
            'requested_at' => Carbon::now()->subDay(),
            'decided_at' => now(),
            'decided_by' => $this->teacher->id,
        ]);

        $this->actingAs($this->teacher)
            ->postJson("/api/classes/{$this->classroom->id}/join-requests/accept-all")
            ->assertStatus(200);

        $this->assertDatabaseHas('memberships', [
            'student_id' => $this->student->id,
            'status' => 'accepted',
        ]);

        // La ligne deja acceptee n'est pas deconseillee.
        $this->assertDatabaseHas('memberships', [
            'id' => $accepted->id,
            'status' => 'accepted',
        ]);
    }

    // -- RG-08 : retrait immediat, resultats conserves --------------------------

    public function test_removal_blocks_access_immediately_but_keeps_attempts(): void
    {
        $quiz = $this->publishedQuiz($this->classroom, $this->teacher);

        Membership::create([
            'classroom_id' => $this->classroom->id,
            'student_id' => $this->student->id,
            'status' => 'accepted',
            'requested_at' => Carbon::now()->subDays(3),
            'decided_at' => Carbon::now()->subDays(2),
            'decided_by' => $this->teacher->id,
        ]);

        $attempt = \App\Models\Attempt::create([
            'quiz_id' => $quiz->id,
            'student_id' => $this->student->id,
            'attempt_no' => 1,
            'max_score' => 2,
            'score' => 1,
            'started_at' => now()->subHour(),
            'submitted_at' => now(),
        ]);

        $this->actingAs($this->teacher)
            ->deleteJson("/api/classes/{$this->classroom->id}/members/{$this->student->id}")
            ->assertStatus(204);

        $this->assertDatabaseHas('memberships', [
            'classroom_id' => $this->classroom->id,
            'student_id' => $this->student->id,
            'status' => 'removed',
        ]);

        // Le resultat n'est pas supprime.
        $this->assertDatabaseHas('attempts', ['id' => $attempt->id, 'score' => 1]);

        // L'acces au contenu de la classe est coupe.
        $this->actingAs($this->student)
            ->getJson("/api/classes/{$this->classroom->id}")
            ->assertStatus(403);
    }

    // -- Role : seul un etudiant peut demander a rejoindre ---------------------

    public function test_a_teacher_cannot_request_to_join(): void
    {
        $this->actingAs($this->teacher)
            ->postJson('/api/join-requests', ['code' => 'TDI2025A'])
            ->assertStatus(403);
    }
}
