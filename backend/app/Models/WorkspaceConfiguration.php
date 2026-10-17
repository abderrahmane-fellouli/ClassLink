<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use App\Workspace\Preset;
use App\Workspace\TerminologyPreset;
use Illuminate\Database\Eloquent\Model;

class WorkspaceConfiguration extends Model
{
    use BelongsToWorkspace;

    protected $fillable = ['workspace_id', 'preset_id', 'preset_version', 'terminology_preset_id', 'terminology_version', 'terminology_overrides', 'preferences'];

    protected $attributes = ['preset_version' => 1, 'terminology_version' => 1, 'revision' => 1];

    protected function casts(): array
    {
        return ['preset_id' => Preset::class, 'terminology_preset_id' => TerminologyPreset::class, 'preset_version' => 'integer', 'terminology_version' => 'integer', 'revision' => 'integer', 'terminology_overrides' => 'array', 'preferences' => 'array'];
    }
}
