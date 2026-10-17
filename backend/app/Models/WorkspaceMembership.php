<?php

namespace App\Models;

use App\Enums\WorkspaceMembershipStatus;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class WorkspaceMembership extends Model
{
    use BelongsToWorkspace;

    protected $fillable = ['workspace_id', 'user_id', 'status', 'joined_at', 'ended_at'];

    protected $attributes = ['status' => 'active', 'version' => 1];

    protected function casts(): array
    {
        return ['status' => WorkspaceMembershipStatus::class, 'joined_at' => 'immutable_datetime', 'ended_at' => 'immutable_datetime', 'version' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $membership) {
            if ($membership->isDirty('user_id')) {
                throw ValidationException::withMessages(['user_id' => 'Membership account identity is immutable.']);
            }
            $membership->version = $membership->getRawOriginal('version') + 1;
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function managerGrants(): HasMany
    {
        return $this->hasMany(WorkspaceManager::class, 'membership_id');
    }
}
