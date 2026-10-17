<?php

namespace App\Services;

use App\Enums\WorkspaceKind;
use App\Enums\WorkspaceMembershipStatus;
use App\Enums\WorkspaceOwnershipState;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceConfiguration;
use App\Models\WorkspaceManager;
use App\Models\WorkspaceMembership;
use App\Workspace\Capability;
use App\Workspace\CapabilityRegistry;
use App\Workspace\Preset;
use App\Workspace\TerminologyPreset;
use App\Workspace\WorkspaceEligibility;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Internal foundation only. No route, observer, seeder or existing workflow calls this service. */
final class WorkspaceFoundation
{
    /** Creates a draft only, never applies a recommended preset or activates a workspace. */
    public function createOwnedDraft(User $creator, string $name, WorkspaceKind $kind = WorkspaceKind::Personal): Workspace
    {
        return DB::transaction(function () use ($creator, $name, $kind) {
            $creator = User::whereKey($creator->id)->lockForUpdate()->firstOrFail();
            if (! WorkspaceEligibility::canManage($creator)) {
                throw ValidationException::withMessages(['creator' => 'An approved active Teacher or Admin is required.']);
            }
            $workspace = Workspace::create(['name' => $name, 'kind' => $kind]);
            $owner = $workspace->memberships()->create(['user_id' => $creator->id, 'joined_at' => now()]);
            $workspace->update(['owner_membership_id' => $owner->id]);
            $workspace->configuration()->create(['preset_id' => Preset::Custom, 'terminology_preset_id' => TerminologyPreset::Generic]);
            $workspace->onboarding()->create(['current_step' => 'teaching_profile', 'draft_answers' => []]);

            return $workspace->fresh();
        });
    }

    /**
     * Only this boundary coordinates configuration and capability snapshot revisions.
     * Registry metadata is not executable authorization. Existing policies are untouched.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, bool>  $capabilityChanges
     */
    public function reviseConfiguration(Workspace $workspace, User $actor, int $expectedRevision, array $attributes = [], array $capabilityChanges = []): WorkspaceConfiguration
    {
        return DB::transaction(function () use ($workspace, $actor, $expectedRevision, $attributes, $capabilityChanges) {
            $workspace = Workspace::whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            $actor = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            if (! $workspace->canBeConfiguredBy($actor)) {
                throw ValidationException::withMessages(['actor' => 'Workspace configuration authority is required.']);
            }
            $configuration = $workspace->configuration()->lockForUpdate()->firstOrFail();
            if ($configuration->revision !== $expectedRevision) {
                throw ValidationException::withMessages(['revision' => 'Workspace configuration changed; reload before saving.']);
            }
            $allowed = ['preset_id', 'preset_version', 'terminology_preset_id', 'terminology_version', 'terminology_overrides', 'preferences'];
            if (array_diff(array_keys($attributes), $allowed)) {
                throw ValidationException::withMessages(['configuration' => 'Unknown or immutable configuration fields.']);
            }
            Validator::make($attributes, [
                'preset_id' => ['sometimes', Rule::enum(Preset::class)],
                'preset_version' => ['sometimes', 'integer', 'min:1'],
                'terminology_preset_id' => ['sometimes', Rule::enum(TerminologyPreset::class)],
                'terminology_version' => ['sometimes', 'integer', 'min:1'],
                'terminology_overrides' => ['sometimes', 'nullable', 'array'],
                'preferences' => ['sometimes', 'nullable', 'array'],
            ])->validate();
            $states = $workspace->capabilities()->pluck('enabled', 'capability')->all();
            foreach ($capabilityChanges as $id => $enabled) {
                if (! Capability::tryFrom($id) || ! is_bool($enabled)) {
                    throw ValidationException::withMessages(['capabilities' => 'Known capability IDs and boolean states are required.']);
                }
                $states[$id] = $enabled;
            }
            $enabled = [];
            foreach ($states as $id => $state) {
                if ($state) {
                    $enabled[] = Capability::from($id);
                }
            }
            CapabilityRegistry::validateEnabled($enabled);
            foreach ($capabilityChanges as $id => $state) {
                $workspace->capabilities()->updateOrCreate(['capability' => $id], ['enabled' => $state]);
            }
            $configuration->fill($attributes)->forceFill(['revision' => $expectedRevision + 1])->save();

            return $configuration->fresh();
        });
    }

    /** Owned-workspace grants only. Compatibility bootstrap requires a later explicit manifest. */
    public function grantManager(Workspace $workspace, User $owner, WorkspaceMembership $target): WorkspaceManager
    {
        return DB::transaction(function () use ($workspace, $owner, $target) {
            $workspace = Workspace::whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            $target = WorkspaceMembership::whereKey($target->id)->lockForUpdate()->firstOrFail();
            if (! $workspace->canBeConfiguredBy($owner) || $workspace->ownership_state !== WorkspaceOwnershipState::Owned
                || $workspace->ownerMembership->user_id !== $owner->id || $target->workspace_id !== $workspace->id
                || $target->status !== WorkspaceMembershipStatus::Active || ! WorkspaceEligibility::canManage($target->user)) {
                throw ValidationException::withMessages(['manager' => 'Only the owner may grant management to this workspace member.']);
            }

            return $target->managerGrants()->firstOrCreate(['revoked_at' => null], ['granted_by' => $owner->id, 'granted_at' => now()]);
        });
    }

    public function revokeManager(WorkspaceManager $grant, User $actor): void
    {
        DB::transaction(function () use ($grant, $actor) {
            $workspace = Workspace::whereKey($grant->membership->workspace_id)->lockForUpdate()->firstOrFail();
            $grant = WorkspaceManager::whereKey($grant->id)->lockForUpdate()->firstOrFail();
            $ownerActor = $workspace->ownerMembership?->user_id === $actor->id;
            if (! $workspace->canBeConfiguredBy($actor) || (! $ownerActor && $grant->membership->user_id !== $actor->id)) {
                throw ValidationException::withMessages(['manager' => 'Only the owner or the departing manager may revoke this grant.']);
            }
            if ($grant->revoked_at) {
                return;
            }
            $otherAuthority = $workspace->ownerMembership;
            $hasOwner = $otherAuthority && $otherAuthority->status === WorkspaceMembershipStatus::Active && WorkspaceEligibility::canManage($otherAuthority->user);
            $hasManager = $workspace->memberships()->where('status', WorkspaceMembershipStatus::Active->value)
                ->whereHas('user', fn ($q) => $q->where('is_active', true)->where('role_locked', true)->whereIn('role', ['teacher', 'admin']))
                ->whereHas('managerGrants', fn ($q) => $q->whereNull('revoked_at')->where('id', '!=', $grant->id))->exists();
            if (! $hasOwner && ! $hasManager) {
                throw ValidationException::withMessages(['manager' => 'The last eligible configuration authority cannot depart.']);
            }
            $grant->update(['revoked_at' => now(), 'revoked_by' => $actor->id]);
        });
    }
}
