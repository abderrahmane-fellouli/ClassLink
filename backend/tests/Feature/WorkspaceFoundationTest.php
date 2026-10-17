<?php

namespace Tests\Feature;

use App\Enums\WorkspaceKind;
use App\Enums\WorkspaceMembershipStatus;
use App\Enums\WorkspaceOnboardingStatus;
use App\Enums\WorkspaceOwnershipState;
use App\Enums\WorkspaceStatus;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceManager;
use App\Models\WorkspaceMembership;
use App\Services\OfficialGradeService;
use App\Services\SchoolCommunicationService;
use App\Services\WorkspaceFoundation;
use App\Workspace\Capability;
use App\Workspace\CapabilityRegistry;
use App\Workspace\Preset;
use App\Workspace\PresetRegistry;
use App\Workspace\TerminologyPreset;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\SchoolFixture;
use Tests\TestCase;

class WorkspaceFoundationTest extends TestCase
{
    use RefreshDatabase, SchoolFixture;

    private function foundation(): WorkspaceFoundation
    {
        return app(WorkspaceFoundation::class);
    }

    private function member(Workspace $workspace, User $user): WorkspaceMembership
    {
        return $workspace->memberships()->create(['user_id' => $user->id, 'joined_at' => now()]);
    }

    private function assertDatabaseRejects(callable $write): void
    {
        try {
            DB::transaction($write);
            $this->fail('Database must reject invalid workspace integrity.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }

    public function test_migration_is_dormant_and_existing_routes_do_not_create_workspaces(): void
    {
        $teacher = $this->teacher();
        $this->actingAs($teacher)->getJson('/api/school')->assertOk();
        $this->actingAs($teacher)->getJson('/api/me')->assertOk()->assertJsonPath('role', 'teacher');
        $this->assertSame(0, Workspace::count());
        $this->assertSame(0, WorkspaceMembership::count());
        $this->assertTrue(Schema::hasColumn('classrooms', 'workspace_id'));
        $this->assertSame(0, DB::table('classrooms')->whereNotNull('workspace_id')->count());
        $this->assertFalse(Schema::hasColumn('users', 'workspace_id'));
        $this->assertFalse(Schema::hasTable('learners'));
        $this->assertFalse(Schema::hasTable('teaching_contexts'));
        foreach (app('router')->getRoutes() as $route) {
            $this->assertStringNotContainsString('workspaces', $route->uri());
        }
    }

    public function test_registry_metadata_translation_keys_resolve_in_both_existing_locales(): void
    {
        foreach (['fr', 'en'] as $locale) {
            foreach (CapabilityRegistry::all() as $definition) {
                $this->assertTrue(app('translator')->hasForLocale($definition->labelKey(), $locale));
                $this->assertTrue(app('translator')->hasForLocale($definition->descriptionKey(), $locale));
            }
            foreach (PresetRegistry::all() as $preset) {
                $this->assertTrue(app('translator')->hasForLocale($preset->labelKey(), $locale));
            }
        }
    }

    public function test_owned_draft_has_explicit_owner_without_granting_global_admin(): void
    {
        $teacher = $this->teacher();
        $before = $teacher->fresh()->getAttributes();
        $workspace = $this->foundation()->createOwnedDraft($teacher, 'Synthetic teaching space');
        $this->assertSame(WorkspaceStatus::Provisioning, $workspace->status);
        $this->assertSame(WorkspaceOwnershipState::Owned, $workspace->ownership_state);
        $this->assertSame($teacher->id, $workspace->ownerMembership->user_id);
        $this->assertTrue($workspace->canBeConfiguredBy($teacher));
        $this->assertFalse($teacher->fresh()->isAdmin());
        $this->assertEquals($before, $teacher->fresh()->getAttributes());
        $this->assertSame(Preset::Custom, $workspace->configuration->preset_id);
        $this->assertSame(0, $workspace->capabilities()->count());
        $this->assertSame(WorkspaceOnboardingStatus::Draft, $workspace->onboarding->status);
        $this->assertSame($workspace->id, $teacher->workspaceMemberships()->sole()->workspace->id);
        $this->actingAs($teacher)->getJson('/api/admin/users')->assertForbidden();
    }

    public function test_global_admin_can_create_draft_but_does_not_own_other_workspaces(): void
    {
        $admin = $this->admin();
        $teacherWorkspace = $this->foundation()->createOwnedDraft($this->teacher(), 'Synthetic personal');
        $own = $this->foundation()->createOwnedDraft($admin, 'Synthetic organization', WorkspaceKind::Organization);
        $this->assertTrue($own->canBeConfiguredBy($admin));
        $this->assertFalse($teacherWorkspace->canBeConfiguredBy($admin));
        $this->assertTrue($admin->fresh()->isAdmin());
    }

    public function test_ineligible_creators_leave_no_partial_workspace_records(): void
    {
        foreach ([['role' => 'student'], ['role' => 'pending'], ['role' => 'denied'], ['is_active' => false], ['role_locked' => false]] as $attributes) {
            $user = $this->teacher($attributes);
            try {
                $this->foundation()->createOwnedDraft($user, 'Must not exist');
                $this->fail('Ineligible creator accepted.');
            } catch (ValidationException) {
                $this->assertSame(0, Workspace::count());
                $this->assertSame(0, WorkspaceMembership::count());
            }
        }
    }

    public function test_database_rejects_owner_from_another_workspace_and_ownerless_activation(): void
    {
        $first = $this->foundation()->createOwnedDraft($this->teacher(), 'First');
        $second = $this->foundation()->createOwnedDraft($this->teacher(), 'Second');
        $this->assertDatabaseRejects(fn () => DB::table('workspaces')->where('id', $first->id)->update(['owner_membership_id' => $second->owner_membership_id]));
        $pending = Workspace::create(['name' => 'Incomplete draft']);
        $this->assertDatabaseRejects(fn () => DB::table('workspaces')->where('id', $pending->id)->update(['status' => 'active']));
        $this->assertDatabaseRejects(fn () => DB::table('workspaces')->where('id', $pending->id)->update(['ownership_state' => 'unassigned_compatibility']));
        $first->update(['status' => WorkspaceStatus::Active]);
        $this->assertSame(WorkspaceStatus::Active, $first->fresh()->status);
    }

    public function test_unassigned_compatibility_state_is_explicit_not_an_inferred_owner(): void
    {
        $this->admin();
        $workspace = Workspace::create(['name' => 'Synthetic compatibility', 'public_key' => Workspace::COMPATIBILITY_KEY, 'kind' => WorkspaceKind::Institutional, 'ownership_state' => WorkspaceOwnershipState::UnassignedCompatibility, 'status' => WorkspaceStatus::Active, 'timezone' => 'Africa/Casablanca']);
        $this->assertNull($workspace->owner_membership_id);
        $this->assertSame(0, $workspace->memberships()->count());
        $this->assertSame(0, WorkspaceManager::count());
    }

    public function test_account_can_join_multiple_workspaces_without_changing_global_role(): void
    {
        $student = $this->student();
        $first = $this->foundation()->createOwnedDraft($this->teacher(), 'First');
        $second = $this->foundation()->createOwnedDraft($this->teacher(), 'Second');
        $member = $this->member($first, $student);
        $this->member($second, $student);
        $this->assertSame(2, $student->workspaceMemberships()->count());
        $this->assertFalse($first->canBeConfiguredBy($student));
        $this->assertSame('student', $student->fresh()->role);
        $this->assertDatabaseRejects(fn () => DB::table('workspace_memberships')->insert(['workspace_id' => $first->id, 'user_id' => $student->id, 'joined_at' => now()]));
        $member->update(['status' => WorkspaceMembershipStatus::Left, 'ended_at' => now()]);
        $this->assertSame(2, $member->fresh()->version);
        $this->assertSame($student->id, $member->fresh()->user->id);
    }

    public function test_manager_grant_is_scoped_revocable_and_not_global_admin_or_classroom_ownership(): void
    {
        $owner = $this->teacher();
        $manager = $this->teacher();
        $workspace = $this->foundation()->createOwnedDraft($owner, 'Workspace');
        $membership = $this->member($workspace, $manager);
        $grant = $this->foundation()->grantManager($workspace, $owner, $membership);
        $this->assertSame($owner->id, $grant->grantor->id);
        $this->assertTrue($workspace->canBeConfiguredBy($manager));
        $this->assertFalse($manager->fresh()->isAdmin());
        [$classroom] = $this->classWithMember($owner);
        $this->assertFalse(Gate::forUser($manager)->allows('update', $classroom));
        $this->assertSame($grant->id, $this->foundation()->grantManager($workspace, $owner, $membership)->id);
        $this->foundation()->revokeManager($grant, $owner);
        $this->assertFalse($workspace->canBeConfiguredBy($manager));
        $this->assertNotNull($grant->fresh()->revoked_at);
        $this->assertSame($owner->id, $grant->fresh()->revoker->id);
        $this->assertSame(1, $membership->managerGrants()->count());
    }

    public function test_manager_cannot_promote_a_member_and_foreign_membership_cannot_be_granted(): void
    {
        $owner = $this->teacher();
        $manager = $this->teacher();
        $workspace = $this->foundation()->createOwnedDraft($owner, 'Workspace');
        $managerMember = $this->member($workspace, $manager);
        $this->foundation()->grantManager($workspace, $owner, $managerMember);
        $target = $this->member($workspace, $this->teacher());
        try {
            $this->foundation()->grantManager($workspace, $manager, $target);
            $this->fail('Manager promoted another member.');
        } catch (ValidationException) {
            $this->assertSame(0, $target->managerGrants()->count());
        }
        $foreign = $this->foundation()->createOwnedDraft($this->teacher(), 'Foreign')->ownerMembership;
        $this->expectException(ValidationException::class);
        $this->foundation()->grantManager($workspace, $owner, $foreign);
    }

    public function test_ineligible_target_cannot_be_a_manager_and_raw_duplicates_are_rejected(): void
    {
        $owner = $this->teacher();
        $workspace = $this->foundation()->createOwnedDraft($owner, 'Workspace');
        $student = $this->member($workspace, $this->student());
        try {
            $this->foundation()->grantManager($workspace, $owner, $student);
            $this->fail('Student became manager.');
        } catch (ValidationException) {
            $this->assertSame(0, $student->managerGrants()->count());
        }
        $manager = $this->member($workspace, $this->teacher());
        $this->foundation()->grantManager($workspace, $owner, $manager);
        $this->assertDatabaseRejects(fn () => DB::table('workspace_managers')->insert(['membership_id' => $manager->id, 'granted_by' => $owner->id, 'granted_at' => now()]));
    }

    public function test_unassigned_compatibility_last_manager_cannot_revoke_its_only_authority(): void
    {
        $admin = $this->admin();
        $workspace = Workspace::create(['name' => 'Synthetic compatibility', 'public_key' => Workspace::COMPATIBILITY_KEY, 'kind' => WorkspaceKind::Institutional, 'ownership_state' => WorkspaceOwnershipState::UnassignedCompatibility]);
        $membership = $this->member($workspace, $admin);
        // Synthetic explicit bootstrap assignment, not production seeding or owner inference.
        $grant = $membership->managerGrants()->create(['granted_by' => $admin->id, 'granted_at' => now()]);
        try {
            $this->foundation()->revokeManager($grant, $admin);
            $this->fail('Last compatibility manager departed.');
        } catch (ValidationException) {
            $this->assertNull($grant->fresh()->revoked_at);
            $this->assertTrue($workspace->canBeConfiguredBy($admin));
            $this->assertNull($workspace->fresh()->owner_membership_id);
        }
    }

    public function test_model_relationship_identities_cannot_be_reassigned(): void
    {
        $owner = $this->teacher();
        $first = $this->foundation()->createOwnedDraft($owner, 'First');
        $second = $this->foundation()->createOwnedDraft($this->teacher(), 'Second');
        foreach ([
            [$first->configuration, ['workspace_id' => $second->id]],
            [$first->ownerMembership, ['user_id' => $second->ownerMembership->user_id]],
            [$first, ['public_key' => 'replace-identity']],
        ] as [$model, $changes]) {
            try {
                $model->update($changes);
                $this->fail('Immutable relationship was reassigned.');
            } catch (ValidationException) {
                $this->assertSame($owner->id, $first->fresh()->ownerMembership->user_id);
            }
        }
    }

    public function test_current_global_and_membership_state_restricts_only_new_configuration_authority(): void
    {
        $owner = $this->teacher();
        $workspace = $this->foundation()->createOwnedDraft($owner, 'Workspace');
        $owner->update(['is_active' => false]);
        $this->assertFalse($workspace->canBeConfiguredBy($owner));
        $owner->update(['is_active' => true]);
        $workspace->ownerMembership->update(['status' => WorkspaceMembershipStatus::Suspended]);
        $this->assertFalse($workspace->canBeConfiguredBy($owner));
        $workspace->ownerMembership->update(['status' => WorkspaceMembershipStatus::Active]);
        $workspace->update(['status' => WorkspaceStatus::Archived]);
        $this->assertFalse($workspace->canBeConfiguredBy($owner));
    }

    public function test_capability_changes_and_configuration_revision_are_atomic_and_reject_stale_writes(): void
    {
        $owner = $this->teacher();
        $workspace = $this->foundation()->createOwnedDraft($owner, 'Workspace');
        $configuration = $this->foundation()->reviseConfiguration($workspace, $owner, 1, ['preset_id' => 'language_teacher', 'terminology_preset_id' => 'language_school', 'preferences' => ['organize' => 'both']], ['assignments' => true, 'submissions' => true]);
        $this->assertSame(2, $configuration->revision);
        $this->assertSame(Preset::LanguageTeacher, $configuration->preset_id);
        $this->assertSame(TerminologyPreset::LanguageSchool, $configuration->terminology_preset_id);
        $this->assertSame(['organize' => 'both'], $configuration->preferences);
        $this->assertTrue($workspace->capabilities()->where('capability', 'submissions')->sole()->enabled);
        try {
            $this->foundation()->reviseConfiguration($workspace, $owner, 1, [], ['resources' => true]);
            $this->fail('Stale configuration accepted.');
        } catch (ValidationException) {
            $this->assertSame(2, $workspace->configuration()->sole()->revision);
            $this->assertFalse($workspace->capabilities()->where('capability', 'resources')->exists());
        }
        $this->foundation()->reviseConfiguration($workspace, $owner, 2, [], ['assignments' => false, 'submissions' => false]);
        $this->assertSame(2, $workspace->capabilities()->count());
        $this->assertFalse($workspace->capabilities()->where('capability', 'assignments')->sole()->enabled);
    }

    public function test_future_unknown_and_dependency_breaking_states_cannot_be_saved_by_service(): void
    {
        $owner = $this->teacher();
        $workspace = $this->foundation()->createOwnedDraft($owner, 'Workspace');
        foreach ([['attendance' => true], ['progress_tracking' => true], ['certificates' => true], ['lesson_scheduling' => true], ['unknown' => true], ['submissions' => true], ['grade_import' => true], ['resources' => 'yes']] as $changes) {
            try {
                $this->foundation()->reviseConfiguration($workspace, $owner, 1, [], $changes);
                $this->fail('Invalid capability snapshot accepted.');
            } catch (ValidationException) {
                $this->assertSame(1, $workspace->configuration()->sole()->revision);
                $this->assertSame(0, $workspace->capabilities()->count());
            }
        }
        $disabled = $workspace->capabilities()->create(['capability' => Capability::Attendance, 'enabled' => false]);
        $this->assertFalse($disabled->fresh()->enabled);
        $this->expectException(ValidationException::class);
        $disabled->update(['enabled' => true]);
    }

    public function test_configurator_cannot_mutate_other_workspace_or_rewrite_configuration_identity(): void
    {
        $owner = $this->teacher();
        $workspace = $this->foundation()->createOwnedDraft($owner, 'Workspace');
        $foreignOwner = $this->teacher();
        try {
            $this->foundation()->reviseConfiguration($workspace, $foreignOwner, 1, [], ['resources' => true]);
            $this->fail('Foreign user configured workspace.');
        } catch (ValidationException) {
            $this->assertSame(1, $workspace->configuration()->sole()->revision);
        }
        $this->expectException(ValidationException::class);
        $this->foundation()->reviseConfiguration($workspace, $owner, 1, ['workspace_id' => 999]);
    }

    public function test_onboarding_draft_and_completion_are_persisted_without_triggering_existing_users(): void
    {
        $workspace = $this->foundation()->createOwnedDraft($this->teacher(), 'Workspace');
        $onboarding = $workspace->onboarding;
        $onboarding->update(['current_step' => 'evaluation', 'draft_answers' => ['organization' => 'individuals', 'evaluation' => ['none']]]);
        $this->assertSame(['none'], $onboarding->fresh()->draft_answers['evaluation']);
        $this->assertDatabaseRejects(fn () => DB::table('workspace_onboardings')->where('id', $onboarding->id)->update(['status' => 'completed']));
        $onboarding->update(['status' => WorkspaceOnboardingStatus::Completed, 'completed_at' => now(), 'completion_origin' => 'user']);
        $this->assertNotNull($onboarding->fresh()->completed_at);
        $this->assertSame(1, $onboarding->fresh()->version);
        $this->assertSame('user', $onboarding->fresh()->completion_origin);
        $this->assertSame(1, Workspace::count());
    }

    public function test_raw_status_version_period_and_foreign_key_invariants(): void
    {
        $workspace = $this->foundation()->createOwnedDraft($this->teacher(), 'Workspace');
        $this->assertDatabaseRejects(fn () => DB::table('workspaces')->where('id', $workspace->id)->update(['status' => 'anything']));
        $this->assertDatabaseRejects(fn () => DB::table('workspace_configurations')->where('workspace_id', $workspace->id)->update(['revision' => 0]));
        $this->assertDatabaseRejects(fn () => DB::table('workspace_memberships')->where('id', $workspace->owner_membership_id)->update(['status' => 'left', 'ended_at' => null]));
        $this->assertDatabaseRejects(fn () => DB::table('workspace_capabilities')->insert(['workspace_id' => 999999, 'capability' => 'resources', 'enabled' => true]));
        $workspace->capabilities()->create(['capability' => Capability::Resources]);
        $this->assertDatabaseRejects(fn () => DB::table('workspace_capabilities')->insert(['workspace_id' => $workspace->id, 'capability' => 'resources', 'enabled' => false]));
    }

    public function test_owner_and_manager_do_not_gain_official_grade_or_private_thread_access(): void
    {
        [, $group, $teachers, $offerings, $students] = $this->schoolFixture();
        $assessment = app(OfficialGradeService::class)->create($teachers[0], $offerings[0], [
            'title' => 'Private official assessment', 'type' => 'exam', 'assessed_on' => now()->toDateString(), 'maximum_score' => 20, 'coefficient' => 1,
        ]);
        $thread = app(SchoolCommunicationService::class)->create($students[0], [
            'classroom_id' => $group->id, 'offering_id' => $offerings[0], 'kind' => 'private_question',
            'subject' => 'Private question', 'body' => 'Synthetic private content', 'participant_ids' => [$teachers[0]->id],
        ]);
        $owner = $this->teacher();
        $workspace = $this->foundation()->createOwnedDraft($owner, 'Unrelated generic draft');
        $manager = $this->teacher();
        $this->foundation()->grantManager($workspace, $owner, $this->member($workspace, $manager));
        // Existing endpoints/policies still determine access, independently of workspace grants.
        $this->asToken($this->tokenFor($owner))->getJson('/api/school/threads/'.$thread)->assertNotFound();
        $this->asToken($this->tokenFor($manager))->getJson('/api/school/assessments/'.$assessment)->assertForbidden();
        $this->getJson('/api/admin/users')->assertForbidden();
        $this->assertSame('teacher', $owner->fresh()->role);
        $this->assertSame('teacher', $manager->fresh()->role);
    }

    public function test_populated_foundation_rollback_refuses_before_dropping_any_table(): void
    {
        $workspace = $this->foundation()->createOwnedDraft($this->teacher(), 'Must survive');
        try {
            (require database_path('migrations/2026_10_17_000001_create_workspace_foundation.php'))->down();
            $this->fail('Populated workspace schema was dropped.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('requires empty tables', $e->getMessage());
            $this->assertSame($workspace->id, Workspace::sole()->id);
            $this->assertTrue(Schema::hasTable('workspace_managers'));
            $this->assertTrue(Schema::hasTable('workspace_onboardings'));
        }
    }
}
