<?php

namespace Tests\Feature;

use App\Models\Workspace;
use App\Models\WorkspaceManager;
use App\Services\WorkspaceFoundation;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The same raw-write/deletion contract is executed on both SQLite and PostgreSQL. */
class WorkspaceDatabaseIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function rejects(callable $write, string $postgresState): void
    {
        try {
            DB::transaction($write);
            $this->fail('Invalid raw database operation succeeded.');
        } catch (QueryException $e) {
            if (DB::getDriverName() === 'pgsql') {
                $this->assertSame($postgresState, $e->errorInfo[0]);
            } else {
                $this->assertSame('23000', $e->errorInfo[0]);
            }
        }
    }

    public function test_membership_manager_and_owner_foreign_keys_are_enforced_at_database_boundary(): void
    {
        $owner = $this->teacher();
        $first = app(WorkspaceFoundation::class)->createOwnedDraft($owner, 'First');
        $second = app(WorkspaceFoundation::class)->createOwnedDraft($this->teacher(), 'Second');
        $this->rejects(fn () => DB::table('workspaces')->where('id', $first->id)->update(['owner_membership_id' => $second->owner_membership_id]), '23503');
        $this->rejects(fn () => DB::table('workspace_managers')->insert(['membership_id' => 999999, 'granted_by' => $owner->id, 'granted_at' => now()]), '23503');
        $this->rejects(fn () => DB::table('workspace_managers')->insert(['membership_id' => $first->owner_membership_id, 'granted_by' => 999999, 'granted_at' => now()]), '23503');
        $this->rejects(fn () => DB::table('workspace_memberships')->insert(['workspace_id' => $first->id, 'user_id' => 999999, 'joined_at' => now()]), '23503');
        $this->assertSame($owner->id, $first->fresh()->ownerMembership->user_id);
    }

    public function test_owner_membership_user_and_workspace_deletion_are_restrictive_not_cascading(): void
    {
        $owner = $this->teacher();
        $workspace = app(WorkspaceFoundation::class)->createOwnedDraft($owner, 'Retained');
        $this->rejects(fn () => DB::table('workspace_memberships')->where('id', $workspace->owner_membership_id)->delete(), '23503');
        $this->rejects(fn () => DB::table('users')->where('id', $owner->id)->delete(), '23503');
        $this->rejects(fn () => DB::table('workspaces')->where('id', $workspace->id)->delete(), '23503');
        $this->assertDatabaseHas('users', ['id' => $owner->id, 'role' => 'teacher']);
        $this->assertSame($workspace->id, Workspace::sole()->id);
        $this->assertSame(1, $workspace->configuration()->count());
        $this->assertSame(1, $workspace->onboarding()->count());
    }

    public function test_active_manager_uniqueness_allows_new_grant_only_after_recorded_revocation(): void
    {
        $owner = $this->teacher();
        $workspace = app(WorkspaceFoundation::class)->createOwnedDraft($owner, 'Grants');
        $row = ['membership_id' => $workspace->owner_membership_id, 'granted_by' => $owner->id, 'granted_at' => now()->subMinute()];
        $grant = WorkspaceManager::create($row);
        $this->rejects(fn () => DB::table('workspace_managers')->insert($row), '23505');
        $this->rejects(fn () => DB::table('workspace_managers')->where('id', $grant->id)->update(['revoked_at' => now(), 'revoked_by' => null]), '23514');
        $this->rejects(fn () => DB::table('workspace_managers')->where('id', $grant->id)->update(['revoked_at' => now()->subHour(), 'revoked_by' => $owner->id]), '23514');
        $grant->update(['revoked_at' => now(), 'revoked_by' => $owner->id]);
        $newGrant = WorkspaceManager::create($row);
        $this->assertNotSame($grant->id, $newGrant->id);
        $this->assertSame(2, $workspace->ownerMembership->managerGrants()->count());
        $this->assertNotNull($grant->fresh()->revoked_at);
    }

    public function test_capability_membership_and_one_to_one_configuration_onboarding_uniqueness(): void
    {
        $owner = $this->teacher();
        $workspace = app(WorkspaceFoundation::class)->createOwnedDraft($owner, 'Uniqueness');
        $workspace->capabilities()->create(['capability' => 'resources', 'enabled' => false]);
        $this->rejects(fn () => DB::table('workspace_capabilities')->insert(['workspace_id' => $workspace->id, 'capability' => 'resources', 'enabled' => true]), '23505');
        $this->rejects(fn () => DB::table('workspace_memberships')->insert(['workspace_id' => $workspace->id, 'user_id' => $owner->id, 'joined_at' => now()]), '23505');
        $this->rejects(fn () => DB::table('workspace_configurations')->insert(['workspace_id' => $workspace->id, 'preset_id' => 'custom', 'terminology_preset_id' => 'generic']), '23505');
        $this->rejects(fn () => DB::table('workspace_onboardings')->insert(['workspace_id' => $workspace->id]), '23505');
    }

    public function test_lifecycle_version_and_completion_checks_cannot_be_bypassed_with_raw_updates(): void
    {
        $workspace = app(WorkspaceFoundation::class)->createOwnedDraft($this->teacher(), 'Checks');
        foreach ([['status' => 'invalid'], ['kind' => 'invalid'], ['version' => 0], ['ownership_state' => 'unassigned_compatibility', 'owner_membership_id' => null]] as $changes) {
            $this->rejects(fn () => DB::table('workspaces')->where('id', $workspace->id)->update($changes), '23514');
        }
        $this->rejects(fn () => DB::table('workspace_memberships')->where('id', $workspace->owner_membership_id)->update(['status' => 'left', 'ended_at' => now()->subYear()]), '23514');
        $this->rejects(fn () => DB::table('workspace_configurations')->where('workspace_id', $workspace->id)->update(['revision' => 0]), '23514');
        $this->rejects(fn () => DB::table('workspace_onboardings')->where('workspace_id', $workspace->id)->update(['status' => 'completed', 'completed_at' => now(), 'completion_origin' => 'implicit']), '23514');
        $this->assertSame('draft', $workspace->onboarding()->sole()->status->value);
    }
}
