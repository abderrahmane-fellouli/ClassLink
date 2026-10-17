<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkspaceMigrationRun extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'attempts' => 'integer', 'counts' => 'array', 'checkpoints' => 'array', 'history' => 'array', 'started_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime', 'failed_at' => 'immutable_datetime'];
    }
}
