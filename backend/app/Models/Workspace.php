<?php

namespace App\Models;

use App\Enums\WorkspaceKind;
use App\Enums\WorkspaceMembershipStatus;
use App\Enums\WorkspaceOwnershipState;
use App\Enums\WorkspaceStatus;
use App\Workspace\WorkspaceEligibility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Workspace extends Model
{
    public const COMPATIBILITY_KEY = 'ofppt-compatibility';

    protected $fillable = ['public_key', 'name', 'kind', 'timezone', 'status', 'ownership_state', 'owner_membership_id'];

    protected $attributes = ['kind' => 'personal', 'timezone' => 'UTC', 'status' => 'provisioning', 'ownership_state' => 'owned', 'version' => 1];

    protected function casts(): array
    {
        return ['kind' => WorkspaceKind::class, 'status' => WorkspaceStatus::class, 'ownership_state' => WorkspaceOwnershipState::class, 'version' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $workspace) => $workspace->public_key ??= (string) Str::uuid());
        static::saving(function (self $workspace) {
            if ($workspace->exists && $workspace->isDirty('public_key')) {
                throw ValidationException::withMessages(['public_key' => 'Workspace public identity is immutable.']);
            }
            if (! trim($workspace->name ?? '') || ! in_array($workspace->timezone, timezone_identifiers_list(), true)) {
                throw ValidationException::withMessages(['workspace' => 'A name and valid timezone are required.']);
            }
            if ($workspace->owner_membership_id && $workspace->ownership_state !== WorkspaceOwnershipState::RecoveryRequired) {
                $owner = WorkspaceMembership::find($workspace->owner_membership_id);
                if (! $owner || $owner->workspace_id !== $workspace->id || $owner->status !== WorkspaceMembershipStatus::Active || ! WorkspaceEligibility::canManage($owner->user)) {
                    throw ValidationException::withMessages(['owner_membership_id' => 'Owner must be an eligible active member of this workspace.']);
                }
            }
        });
        static::updating(fn (self $workspace) => $workspace->version = $workspace->getRawOriginal('version') + 1);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(WorkspaceMembership::class);
    }

    public function ownerMembership(): BelongsTo
    {
        return $this->belongsTo(WorkspaceMembership::class, 'owner_membership_id');
    }

    public function configuration(): HasOne
    {
        return $this->hasOne(WorkspaceConfiguration::class);
    }

    public function capabilities(): HasMany
    {
        return $this->hasMany(WorkspaceCapability::class);
    }

    public function onboarding(): HasOne
    {
        return $this->hasOne(WorkspaceOnboarding::class);
    }

    /** New configuration responsibility only: never used by existing policies/guards. */
    public function canBeConfiguredBy(User $user): bool
    {
        $workspace = $this->fresh();
        $user = $user->fresh();
        if (! $workspace || ! $user || ! WorkspaceEligibility::canManage($user)
            || $workspace->status === WorkspaceStatus::Archived || $workspace->ownership_state === WorkspaceOwnershipState::RecoveryRequired) {
            return false;
        }
        $membership = $workspace->memberships()->where('user_id', $user->id)->where('status', WorkspaceMembershipStatus::Active->value)->first();

        return $membership && ($workspace->owner_membership_id === $membership->id || $membership->managerGrants()->whereNull('revoked_at')->exists());
    }
}
