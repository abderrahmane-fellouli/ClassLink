<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Classroom;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * T-11 a T-14 et T-22 — Confidentialite et autorisation (§7 RG-05, RG-17,
 * RG-18 ; §16 « controle d'acces cote serveur »).
 */
class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    // -- T-11 : un etudiant non membre ne voit pas la classe -------------------

    public function test_t11_outsider_cannot_read_a_classroom(): void
    {
        [$classroom] = $this->classWithMember();
        $outsider = $this->student();

        $this->actingAs($outsider)
            ->getJson("/api/classes/{$classroom->id}")
            ->assertStatus(403);
    }

    public function test_t11_member_can_read_the_classroom(): void
    {
        [$classroom, , $student] = $this->classWithMember();

        $this->actingAs($student)
            ->getJson("/api/classes/{$classroom->id}")
            ->assertOk();
    }

    public function test_a_removed_student_loses_access_immediately(): void
    {
        [$classroom, , $student] = $this->classWithMember();

        $this->actingAs($student)->getJson("/api/classes/{$classroom->id}")->assertOk();

        $classroom->memberships()->update(['status' => 'removed']);

        $this->actingAs($student)
            ->getJson("/api/classes/{$classroom->id}")
            ->assertStatus(403);
    }

    public function test_a_pending_student_cannot_read_the_classroom(): void
    {
        [$classroom, , $student] = $this->classWithMember();

        $classroom->memberships()->update(['status' => 'pending']);

        $this->actingAs($student)
            ->getJson("/api/classes/{$classroom->id}")
            ->assertStatus(403);
    }

    // -- T-11 : contenu d'une classe inaccessible aux tiers --------------------

    public function test_t11_outsider_cannot_list_content_of_a_classroom(): void
    {
        [$classroom] = $this->classWithMember();
        $outsider = $this->student();

        $this->actingAs($outsider)
            ->getJson("/api/classes/{$classroom->id}/materials")
            ->assertStatus(403);

        $this->actingAs($outsider)
            ->getJson("/api/classes/{$classroom->id}/announcements")
            ->assertStatus(403);

        $this->actingAs($outsider)
            ->getJson("/api/classes/{$classroom->id}/quizzes")
            ->assertStatus(403);
    }

    // -- T-12 : un enseignant ne gere pas la classe d'un autre ------------------

    public function test_t12_other_teacher_cannot_manage_the_classroom(): void
    {
        [$classroom] = $this->classWithMember();
        $intruder = $this->teacher();

        $this->actingAs($intruder)
            ->patchJson("/api/classes/{$classroom->id}", ['name' => 'Piratée'])
            ->assertStatus(403);

        $this->actingAs($intruder)
            ->getJson("/api/classes/{$classroom->id}/members")
            ->assertStatus(403);

        $this->actingAs($intruder)
            ->postJson("/api/classes/{$classroom->id}/archive")
            ->assertStatus(403);
    }

    public function test_owner_teacher_can_manage_the_classroom(): void
    {
        [$classroom, $teacher] = $this->classWithMember();

        $this->actingAs($teacher)
            ->patchJson("/api/classes/{$classroom->id}", ['name' => 'Nouveau nom'])
            ->assertOk();

        $this->assertDatabaseHas('classrooms', [
            'id' => $classroom->id,
            'name' => 'Nouveau nom',
        ]);
    }

    // -- T-13 : le super admin peut tout piloter, un enseignant non ------------

    public function test_t13_admin_can_see_and_archive_any_classroom(): void
    {
        [$classroom] = $this->classWithMember();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->getJson('/api/admin/classes')
            ->assertOk();

        $this->actingAs($admin)
            ->postJson("/api/admin/classes/{$classroom->id}/archive")
            ->assertOk();

        $this->assertDatabaseHas('classrooms', ['id' => $classroom->id, 'status' => 'archived']);
    }

    public function test_t13_teacher_cannot_reach_admin_endpoints(): void
    {
        $teacher = $this->teacher();
        $student = $this->student();

        foreach (['/api/admin/users', '/api/admin/classes', '/api/admin/stats', '/api/admin/audit-logs'] as $uri) {
            $this->actingAs($teacher)->getJson($uri)->assertStatus(403);
            $this->actingAs($student)->getJson($uri)->assertStatus(403);
        }
    }

    public function test_t13_student_cannot_create_a_classroom(): void
    {
        $this->actingAs($this->student())
            ->postJson('/api/classes', ['name' => 'Classe pirate', 'subject' => 'X'])
            ->assertStatus(403);
    }

    public function test_t13_teacher_cannot_create_a_classroom_without_being_checked(): void
    {
        // Un enseignant peut creer sa classe : le role est verifie, pas l'origine.
        $this->actingAs($this->teacher())
            ->postJson('/api/classes', [
                'name' => 'Ma classe',
                'subject' => 'Algorithmique',
                'group_label' => 'TDI 1',
                'school_year' => '2025-2026',
            ])
            ->assertStatus(201);
    }

    // -- T-14 : RG-03 — le super admin est le seul a fixer un role -------------

    public function test_t14_teacher_cannot_change_any_role(): void
    {
        $teacher = $this->teacher();
        $target = $this->student();

        $this->actingAs($teacher)
            ->patchJson("/api/admin/users/{$target->id}", ['role' => 'admin'])
            ->assertStatus(403);

        $this->assertDatabaseHas('users', ['id' => $target->id, 'role' => 'student']);
    }

    public function test_t14_admin_can_promote_a_user_and_the_role_is_locked(): void
    {
        $admin = $this->admin();
        $target = $this->student();

        $this->actingAs($admin)
            ->patchJson("/api/admin/users/{$target->id}", ['role' => 'teacher'])
            ->assertOk();

        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'role' => 'teacher',
            'role_locked' => true,
        ]);
    }

    public function test_t14_admin_can_deactivate_a_user_and_access_is_cut(): void
    {
        $admin = $this->admin();
        $target = $this->student();

        $this->actingAs($target)->getJson('/api/me')->assertOk();

        $this->actingAs($admin)
            ->patchJson("/api/admin/users/{$target->id}", ['is_active' => false])
            ->assertOk();

        // Le middleware `active` coupe immediatement l'acces.
        // `fresh()` : en production chaque requête relit l'utilisateur en
        // base, alors que `actingAs()` réutilise l'instance en mémoire.
        $this->actingAs($target->fresh())
            ->getJson('/api/me')
            ->assertStatus(403);
    }

    public function test_a_role_cannot_be_self_assigned_by_the_browser(): void
    {
        $student = $this->student();

        // Les champs protégés sont rejetés explicitement (422) plutôt que
        // silencieusement ignorés : le client sait que rien n'a été appliqué.
        $this->actingAs($student)
            ->patchJson('/api/me', ['display_name' => 'Nouveau nom', 'role' => 'admin'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('role');

        $this->actingAs($student)
            ->patchJson('/api/me', ['is_active' => true, 'email' => 'pirate@ofppt-edu.ma'])
            ->assertStatus(422);

        $this->assertDatabaseHas('users', [
            'id' => $student->id,
            'role' => 'student',
            'email' => $student->email,
        ]);
    }

    public function test_a_user_cannot_change_their_own_avatar_or_locale_out_of_range(): void
    {
        $student = $this->student();

        // F-UI-01 : seuls le français et l'anglais sont proposals.
        $this->actingAs($student)
            ->patchJson('/api/me', ['locale' => 'ar'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('locale');
    }

    // -- T-22 : aucun email dans les listes vues par un etudiant ---------------

    public function test_t22_member_list_seen_by_a_student_contains_no_email(): void
    {
        [$classroom, $teacher, $student] = $this->classWithMember();

        $peer = $this->student();
        Membership::create([
            'classroom_id' => $classroom->id,
            'student_id' => $peer->id,
            'status' => 'accepted',
            'requested_at' => now(),
            'decided_at' => now(),
            'decided_by' => $teacher->id,
        ]);

        $response = $this->actingAs($student)
            ->getJson("/api/classes/{$classroom->id}/members")
            ->assertOk();

        $this->assertStringNotContainsString('@ofppt-edu.ma', $response->getContent());
        $this->assertStringNotContainsString('email', $response->getContent());

        foreach ($response->json('data') as $member) {
            $this->assertArrayNotHasKey('email', $member);
        }
    }

    public function test_t22_teacher_sees_the_email_of_their_own_students(): void
    {
        [$classroom, $teacher, $student] = $this->classWithMember();

        $response = $this->actingAs($teacher)
            ->getJson("/api/classes/{$classroom->id}/members")
            ->assertOk();

        $this->assertStringContainsString($student->email, $response->getContent());
    }

    public function test_t22_a_student_sees_their_own_email_on_their_profile(): void
    {
        $student = $this->student();

        $response = $this->actingAs($student)->getJson('/api/me')->assertOk();

        $this->assertSame($student->email, $response->json('email'));
    }

    public function test_t22_a_student_cannot_read_another_students_profile(): void
    {
        [$classroom, $teacher, $student] = $this->classWithMember();
        $peer = $this->student();

        Membership::create([
            'classroom_id' => $classroom->id,
            'student_id' => $peer->id,
            'status' => 'accepted',
            'requested_at' => now(),
            'decided_at' => now(),
            'decided_by' => $teacher->id,
        ]);

        $this->actingAs($student)
            ->getJson('/api/me')
            ->assertOk();

        // Pas de route d'acces direct au profil d'un autre utilisateur.
        $this->actingAs($student)
            ->getJson("/api/users/{$peer->id}")
            ->assertStatus(404);
    }

    public function test_no_email_leaks_through_the_quiz_results_export(): void
    {
        [$classroom, $teacher, $student] = $this->classWithMember();
        $quiz = $this->publishedQuiz($classroom, $teacher);

        $start = $this->actingAs($student)
            ->postJson("/api/quizzes/{$quiz->id}/attempts")
            ->assertStatus(201)
            ->json();

        $this->actingAs($student)
            ->postJson("/api/attempts/{$start['attempt_id']}/submit", [
                'answers' => $quiz->questions->map(fn ($q) => [
                    'question_id' => $q->id,
                    'option_ids' => [$q->options->firstWhere('is_correct', true)->id],
                ])->all(),
            ])->assertOk();

        $csv = $this->actingAs($teacher)
            ->get("/api/quizzes/{$quiz->id}/results/export")
            ->assertOk();

        $this->assertStringNotContainsString($student->email, $csv->streamedContent());
    }

    public function test_audit_log_never_contains_email_addresses(): void
    {
        $admin = $this->admin();
        $student = $this->student();

        $this->actingAs($student)->getJson('/api/me')->assertOk();

        $response = $this->actingAs($admin)->getJson('/api/admin/audit-logs')->assertOk();

        $this->assertStringNotContainsString($student->email, $response->getContent());
    }

    // -- RG-20 : les evenements sensibles sont journalises ----------------------

    public function test_sensitive_actions_are_audited(): void
    {
        $admin = $this->admin();
        $target = $this->student();

        $this->actingAs($admin)
            ->patchJson("/api/admin/users/{$target->id}", ['role' => 'teacher'])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'user.role_change']);

        $this->actingAs($admin)
            ->postJson('/api/auth/logout')
            ->assertStatus(204);

        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.logout']);
    }

    public function test_deactivation_is_audited(): void
    {
        $admin = $this->admin();
        $target = $this->student();

        $this->actingAs($admin)
            ->patchJson("/api/admin/users/{$target->id}", ['is_active' => false])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'user.activation_change']);
    }

    public function test_audit_log_records_the_actor_and_not_the_password(): void
    {
        $teacher = $this->teacher();

        $this->actingAs($teacher)
            ->postJson('/api/classes', [
                'name' => 'Classe auditee',
                'subject' => 'Reseaux',
                'group_label' => 'TDI 9',
                'school_year' => '2025-2026',
            ])
            ->assertStatus(201);

        $log = AuditLog::latest('id')->first();

        $this->assertSame($teacher->id, $log->user_id);
        $this->assertArrayNotHasKey('password', $log->payload ?? []);
    }

    // -- F-DEV-01 : création d'un devoir dans sa propre classe -----------------
    //
    // `AssignmentController::store()` appelle `authorize('create', $class)` ;
    // l'absence de cette ability renvoyait 403 à l'enseignant légitime.

    public function test_f_dev_01_owner_teacher_can_create_an_assignment(): void
    {
        [$classroom, $teacher] = $this->classWithMember();

        $this->actingAs($teacher)
            ->postJson("/api/classes/{$classroom->id}/assignments", [
                'title' => 'Devoir sur le cours',
                'instructions' => 'Rendre un PDF.',
                'due_at' => now()->addWeek()->toDateTimeString(),
            ])
            ->assertCreated()
            ->assertJsonPath('title', 'Devoir sur le cours');

        $this->assertDatabaseHas('assignments', [
            'classroom_id' => $classroom->id,
            'created_by' => $teacher->id,
            'title' => 'Devoir sur le cours',
        ]);
    }

    public function test_f_dev_01_another_teacher_cannot_create_an_assignment(): void
    {
        [$classroom] = $this->classWithMember();
        $other = $this->teacher();

        $this->actingAs($other)
            ->postJson("/api/classes/{$classroom->id}/assignments", ['title' => 'Intrus'])
            ->assertForbidden();

        $this->assertDatabaseMissing('assignments', ['title' => 'Intrus']);
    }

    public function test_f_dev_01_a_student_cannot_create_an_assignment(): void
    {
        [$classroom, , $student] = $this->classWithMember();

        $this->actingAs($student)
            ->postJson("/api/classes/{$classroom->id}/assignments", ['title' => 'Devoir etudiant'])
            ->assertForbidden();

        $this->assertDatabaseMissing('assignments', ['title' => 'Devoir etudiant']);
    }

    public function test_f_dev_01_an_archived_classroom_refuses_new_assignments(): void
    {
        [$classroom, $teacher] = $this->classWithMember();

        $classroom->update(['status' => 'archived', 'archived_at' => now()]);

        $this->actingAs($teacher)
            ->postJson("/api/classes/{$classroom->id}/assignments", ['title' => 'Trop tard'])
            ->assertForbidden();

        $this->assertDatabaseMissing('assignments', ['title' => 'Trop tard']);
    }

    /**
     * §12.6 : le super-administrateur ne fait pas partie des capacités
     * documentées pour le contenu pédagogique — il ne crée pas de devoir à la
     * place d'un enseignant.
     */
    public function test_f_dev_01_admin_does_not_create_assignments(): void
    {
        [$classroom] = $this->classWithMember();

        $this->actingAs($this->admin())
            ->postJson("/api/classes/{$classroom->id}/assignments", ['title' => 'Devoir admin'])
            ->assertForbidden();

        $this->assertDatabaseMissing('assignments', ['title' => 'Devoir admin']);
    }

    public function test_a_forbidden_response_is_translated(): void
    {
        [$classroom] = $this->classWithMember();
        $outsider = $this->student();

        $response = $this->actingAs($outsider)
            ->getJson("/api/classes/{$classroom->id}/assignments")
            ->assertForbidden();

        // Un 403 ne doit jamais renvoyer le libelle anglais de Laravel.
        $this->assertSame('Accès refusé.', $response->json('message'));
    }

    // -- RG-04 : l'admin ne gere pas les ressources d'un enseignant ------------
    //
    // `Classroom::isOwnedBy()` inclut l'admin pour l'administration des
    // classes ; la gestion des ressources reste au propriétaire.

    public function test_an_admin_cannot_download_a_teachers_material(): void
    {
        [$classroom, $teacher] = $this->classWithMember();
        $material = $this->material($classroom, $teacher);

        $this->actingAs($this->admin())
            ->getJson("/api/materials/{$material->id}/download")
            ->assertForbidden();
    }

    public function test_an_admin_cannot_delete_a_teachers_material(): void
    {
        [, , $material] = $this->materialWithOwner();

        $this->actingAs($this->admin())
            ->deleteJson("/api/materials/{$material->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('materials', ['id' => $material->id]);
    }

    public function test_an_admin_cannot_update_a_teachers_material(): void
    {
        [, , $material] = $this->materialWithOwner();

        $this->actingAs($this->admin())
            ->patchJson("/api/materials/{$material->id}", ['title' => 'Récupéré'])
            ->assertForbidden();

        $this->assertDatabaseHas('materials', ['id' => $material->id, 'title' => $material->title]);
    }

    public function test_the_owning_teacher_keeps_full_control_of_their_material(): void
    {
        [$classroom, $teacher, $student] = $this->classWithMember();
        $material = $this->material($classroom, $teacher);

        $this->actingAs($teacher)
            ->patchJson("/api/materials/{$material->id}", ['title' => 'Cours chapitre 2'])
            ->assertOk()
            ->assertJsonPath('title', 'Cours chapitre 2');

        $this->actingAs($student)
            ->getJson("/api/materials/{$material->id}/download")
            ->assertOk();

        $this->actingAs($teacher)->deleteJson("/api/materials/{$material->id}")->assertNoContent();
    }

    private function materialWithOwner(): array
    {
        [$classroom, $teacher] = $this->classWithMember();

        return [$classroom, $teacher, $this->material($classroom, $teacher)];
    }

    private function material(Classroom $classroom, User $teacher): \App\Models\Material
    {
        return \App\Models\Material::create([
            'classroom_id' => $classroom->id,
            'title' => 'Cours chapitre 1',
            'type' => 'link',
            'path_or_url' => 'https://example.org/cours',
            'uploaded_by' => $teacher->id,
        ]);
    }

    // -- Un compte archive ne peut plus etre modifie ---------------------------

    public function test_archived_classroom_is_read_only(): void
    {
        [$classroom, $teacher] = $this->classWithMember();

        $classroom->update(['status' => 'archived', 'archived_at' => now()]);

        $this->actingAs($teacher)
            ->patchJson("/api/classes/{$classroom->id}", ['name' => 'Renommage'])
            ->assertStatus(409);
    }
}
