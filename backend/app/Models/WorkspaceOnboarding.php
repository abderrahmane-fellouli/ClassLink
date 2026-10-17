<?php

namespace App\Models;

use App\Enums\WorkspaceOnboardingStatus;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

class WorkspaceOnboarding extends Model
{
    use BelongsToWorkspace;

    protected $fillable = ['workspace_id', 'version', 'status', 'current_step', 'draft_answers', 'completed_at', 'completion_origin'];

    protected $attributes = ['version' => 1, 'status' => 'draft'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'status' => WorkspaceOnboardingStatus::class, 'draft_answers' => 'array', 'completed_at' => 'immutable_datetime'];
    }
}
