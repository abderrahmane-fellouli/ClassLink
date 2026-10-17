<?php

namespace App\Models;

use App\Enums\WorkspaceMembershipStatus;
use App\Workspace\WorkspaceEligibility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

/** Append grants/revocations; no global role and no independent workspace_id. */
class WorkspaceManager extends Model
{
    protected $fillable = ['membership_id', 'granted_by', 'granted_at', 'revoked_at', 'revoked_by'];

    protected function casts(): array
    {
        return ['granted_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $grant) {
            if ($grant->exists && ($grant->isDirty(['membership_id', 'granted_by', 'granted_at']) || $grant->getRawOriginal('revoked_at') !== null)) {
                throw ValidationException::withMessages(['manager' => 'Grant provenance and completed revocation are immutable.']);
            }
            if (! $grant->exists) {
                $membership = WorkspaceMembership::find($grant->membership_id);
                if (! $membership || $membership->status !== WorkspaceMembershipStatus::Active || ! WorkspaceEligibility::canManage($membership->user)) {
                    throw ValidationException::withMessages(['membership_id' => 'Manager must be an eligible active member.']);
                }
            }
        });
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(WorkspaceMembership::class);
    }

    public function grantor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }
}
