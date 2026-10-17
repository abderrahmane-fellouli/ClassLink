<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use App\Workspace\Capability;
use App\Workspace\CapabilityRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class WorkspaceCapability extends Model
{
    use BelongsToWorkspace;

    protected $fillable = ['workspace_id', 'capability', 'enabled'];

    protected $attributes = ['enabled' => false];

    protected function casts(): array
    {
        return ['capability' => Capability::class, 'enabled' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $state) {
            if ($state->enabled && ! CapabilityRegistry::isImplemented($state->capability)) {
                throw ValidationException::withMessages(['capability' => 'Future capabilities cannot be enabled.']);
            }
            if ($state->exists && $state->isDirty('capability')) {
                throw ValidationException::withMessages(['capability' => 'Capability identity is immutable.']);
            }
        });
    }
}
