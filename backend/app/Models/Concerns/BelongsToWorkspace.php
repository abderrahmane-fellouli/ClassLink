<?php

namespace App\Models\Concerns;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

/** Relationship and identity guard only. Deliberately NOT a tenant query scope. */
trait BelongsToWorkspace
{
    public static function bootBelongsToWorkspace(): void
    {
        static::updating(function ($record) {
            if ($record->isDirty('workspace_id')) {
                throw ValidationException::withMessages(['workspace_id' => 'Workspace containment is immutable.']);
            }
        });
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
