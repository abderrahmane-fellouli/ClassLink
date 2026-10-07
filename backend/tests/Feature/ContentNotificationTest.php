<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Classroom;
use App\Models\Membership;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * F-CON-04 / F-DEV-01 / RG-06 — la publication d'une annonce ou d'un devoir
 * notifie les membres acceptés de la classe.
 *
 * Avant correction, ni `POST /classes/{id}/announcements` ni
 * `POST /classes/{id}/assignments` ne notifiait qui que ce soit : un devoir
 * créé en classe n'était visible que si l'élève consultait la liste au hasard.
 * `POST /quizzes/{id}/publish` notifiait déjà (QUIZ_PUBLISHED).
 */
class ContentNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Classroom $classroom;

    private User $teacher;

    private User $accepted;

    /** @var array<int, User> */
    private array $others = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = $this->teacher();
        $this->classroom = Classroom::factory()->create([
            'teacher_id' => $this->teacher->id,
            'join_code' => 'TDI2025A',
            'join_enabled' => true,
            'status' => 'active',
        ]);

        $this->accepted = $this->student();

        $this->membership($this->accepted, 'accepted');
    }

    // -- Annonces ------------------------------------------------------------

    public function test_creating_an_announcement_notifies_accepted_members(): void
    {
        $this->actingAs($this->teacher)
            ->postJson("/api/classes/{$this->classroom->id}/announcements", [
                'title' => 'Consignes du TP',
                'body' => 'À rendre vendredi.',
            ])
            ->assertStatus(201);

        $notification = AppNotification::where('user_id', $this->accepted->id)
            ->where('type', NotificationService::ANNOUNCEMENT_PUBLISHED)
            ->sole();

        $this->assertSame('Consignes du TP', $notification->payload['title']);
        $this->assertSame($this->classroom->id, $notification->payload['classroom_id']);
        $this->assertSame($this->classroom->name, $notification->payload['classroom_name']);
        $this->assertNotNull($notification->payload['announcement_id']);
        $this->assertNull($notification->read_at, 'La notification doit arriver non lue.');
    }

    public function test_creating_an_assignment_notifies_accepted_members(): void
    {
        $this->actingAs($this->teacher)
            ->postJson("/api/classes/{$this->classroom->id}/assignments", [
                'title' => 'TP 3',
                'due_at' => now()->addWeek()->toIso8601String(),
            ])
            ->assertStatus(201);

        $notification = AppNotification::where('user_id', $this->accepted->id)
            ->where('type', NotificationService::ASSIGNMENT_PUBLISHED)
            ->sole();

        $this->assertSame('TP 3', $notification->payload['title']);
        $this->assertNotNull($notification->payload['assignment_id']);
        $this->assertNotNull($notification->payload['due_at']);
    }

    public function test_creating_a_resource_notifies_accepted_members(): void
    {
        $this->actingAs($this->teacher)
            ->postJson("/api/classes/{$this->classroom->id}/materials", [
                'title' => 'Synthetic reference',
                'type' => 'link',
                'url' => 'https://example.test/reference',
            ])
            ->assertStatus(201);

        $notification = AppNotification::where('user_id', $this->accepted->id)
            ->where('type', NotificationService::RESOURCE_PUBLISHED)
            ->sole();

        $this->assertSame('Synthetic reference', $notification->payload['title']);
        $this->assertSame($this->classroom->id, $notification->payload['classroom_id']);
        $this->assertSame($this->classroom->name, $notification->payload['classroom_name']);
        $this->assertNotNull($notification->payload['material_id']);
        $this->assertSame(
            0,
            AppNotification::where('user_id', $this->teacher->id)->where('type', NotificationService::RESOURCE_PUBLISHED)->count(),
            "L'auteur ne doit pas être notifié de sa propre ressource."
        );
    }

    public function test_changing_a_deadline_notifies_accepted_members_exactly_once_per_change(): void
    {
        $due = now()->addWeek()->toIso8601String();
        $id = $this->actingAs($this->teacher)
            ->postJson("/api/classes/{$this->classroom->id}/assignments", ['title' => 'TP date', 'due_at' => $due])
            ->assertStatus(201)->json('id');

        // Re-PATCH avec la même date : aucun changement effectif, aucune notification.
        $this->actingAs($this->teacher)
            ->patchJson("/api/assignments/$id", ['due_at' => $due])
            ->assertOk();
        $this->assertSame(
            0,
            AppNotification::where('user_id', $this->accepted->id)->where('type', NotificationService::DEADLINE_CHANGED)->count(),
            'Une resauvegarde sans changement de date ne doit rien notifier.'
        );

        $newDue = now()->addDays(2)->toIso8601String();
        $this->actingAs($this->teacher)
            ->patchJson("/api/assignments/$id", ['due_at' => $newDue])
            ->assertOk();

        $notification = AppNotification::where('user_id', $this->accepted->id)
            ->where('type', NotificationService::DEADLINE_CHANGED)
            ->sole();
        $this->assertSame($newDue, $notification->payload['due_at']);
        $this->assertSame($id, $notification->payload['assignment_id']);
        $this->assertSame('/app/assignments/'.$id, $notification->payload['url']);

        // Une mise à jour de titre (sans changement de date) ne re-notifie pas.
        $this->actingAs($this->teacher)
            ->patchJson("/api/assignments/$id", ['title' => 'TP date finale'])
            ->assertOk();
        $this->assertSame(
            1,
            AppNotification::where('user_id', $this->accepted->id)->where('type', NotificationService::DEADLINE_CHANGED)->count()
        );
    }

    // -- RG-06 : seuls les membres acceptes ---------------------------------

    public function test_pending_and_rejected_students_are_not_notified(): void
    {
        $pending = $this->student();
        $rejected = $this->student();
        $removed = $this->student();

        $this->membership($pending, 'pending');
        $this->membership($rejected, 'rejected');
        $this->membership($removed, 'removed');

        $this->actingAs($this->teacher)
            ->postJson("/api/classes/{$this->classroom->id}/announcements", [
                'title' => 'Annonce',
                'body' => 'Contenu',
            ])
            ->assertStatus(201);

        $this->actingAs($this->teacher)
            ->postJson("/api/classes/{$this->classroom->id}/assignments", ['title' => 'Devoir'])
            ->assertStatus(201);

        foreach ([$pending, $rejected, $removed] as $student) {
            $this->assertSame(
                0,
                AppNotification::where('user_id', $student->id)->count(),
                'Un membre non accepté ne doit jamais être notifié.'
            );
        }
    }

    public function test_students_of_another_class_are_not_notified(): void
    {
        $otherTeacher = $this->teacher();
        $otherClass = Classroom::factory()->create([
            'teacher_id' => $otherTeacher->id,
            'join_code' => 'TDI2025B',
            'join_enabled' => true,
            'status' => 'active',
        ]);
        $outsider = $this->student();
        $this->membership($outsider, 'accepted', $otherClass);

        $this->actingAs($this->teacher)
            ->postJson("/api/classes/{$this->classroom->id}/announcements", [
                'title' => 'Annonce',
                'body' => 'Contenu',
            ])
            ->assertStatus(201);

        $this->assertSame(0, AppNotification::where('user_id', $outsider->id)->count());
    }

    public function test_the_author_and_the_teacher_are_not_notified_of_their_own_content(): void
    {
        $this->actingAs($this->teacher)
            ->postJson("/api/classes/{$this->classroom->id}/announcements", [
                'title' => 'Annonce',
                'body' => 'Contenu',
            ])
            ->assertStatus(201);

        $this->assertSame(0, AppNotification::where('user_id', $this->teacher->id)->count());
    }

    public function test_every_accepted_member_receives_exactly_one_notification(): void
    {
        foreach (range(1, 3) as $_) {
            $this->membership($this->student(), 'accepted');
        }

        $this->actingAs($this->teacher)
            ->postJson("/api/classes/{$this->classroom->id}/announcements", [
                'title' => 'Annonce',
                'body' => 'Contenu',
            ])
            ->assertStatus(201);

        $this->assertSame(
            4,
            AppNotification::where('type', NotificationService::ANNOUNCEMENT_PUBLISHED)->count()
        );
    }

    // -- L'ecriture reste protegee -------------------------------------------

    public function test_a_student_cannot_publish_an_announcement_and_nobody_is_notified(): void
    {
        $this->actingAs($this->accepted)
            ->postJson("/api/classes/{$this->classroom->id}/announcements", [
                'title' => 'Annonce piratee',
                'body' => 'Contenu',
            ])
            ->assertStatus(403);

        $this->assertSame(0, AppNotification::count());
        $this->assertDatabaseMissing('announcements', ['title' => 'Annonce piratee']);
    }

    public function test_a_student_cannot_create_an_assignment_and_nobody_is_notified(): void
    {
        $this->actingAs($this->accepted)
            ->postJson("/api/classes/{$this->classroom->id}/assignments", ['title' => 'Devoir pirate'])
            ->assertStatus(403);

        $this->assertSame(0, AppNotification::count());
        $this->assertDatabaseMissing('assignments', ['title' => 'Devoir pirate']);
    }

    public function test_a_rejected_validation_sends_no_notification(): void
    {
        $this->actingAs($this->teacher)
            ->postJson("/api/classes/{$this->classroom->id}/announcements", ['body' => 'Sans titre'])
            ->assertStatus(422);

        $this->actingAs($this->teacher)
            ->postJson("/api/classes/{$this->classroom->id}/assignments", [])
            ->assertStatus(422);

        $this->assertSame(0, AppNotification::count());
    }

    // -- Le secretariat n'est pas divulgue ------------------------------------

    public function test_the_payload_does_not_leak_the_author_id_or_the_body(): void
    {
        $this->actingAs($this->teacher)
            ->postJson("/api/classes/{$this->classroom->id}/announcements", [
                'title' => 'Annonce',
                'body' => 'Contenu confidentiel',
            ])
            ->assertStatus(201);

        $payload = AppNotification::sole()->payload;

        $this->assertArrayNotHasKey('author_id', $payload);
        $this->assertArrayNotHasKey('body', $payload);
    }

    // -- Le comptage non lu reste coherent -----------------------------------

    public function test_the_notification_increases_the_unread_count(): void
    {
        $before = app(NotificationService::class)->unreadCount($this->accepted->id);

        $this->actingAs($this->teacher)
            ->postJson("/api/classes/{$this->classroom->id}/assignments", ['title' => 'Devoir'])
            ->assertStatus(201);

        $this->assertSame($before + 1, app(NotificationService::class)->unreadCount($this->accepted->id));
    }

    private function membership(User $student, string $status, ?Classroom $classroom = null): Membership
    {
        return Membership::create([
            'classroom_id' => ($classroom ?? $this->classroom)->id,
            'student_id' => $student->id,
            'status' => $status,
            'requested_at' => now(),
            'decided_at' => $status === 'pending' ? null : now(),
            'decided_by' => $status === 'pending' ? null : $this->teacher->id,
        ]);
    }
}