<?php

namespace App\Models;

use App\Enums\AiJobStatus;
use App\Enums\AiTarget;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiJob extends Model
{
    use HasFactory;

    protected $fillable = [
        'teacher_id',
        'classroom_id',
        'target',
        'file_hash',
        'original_name',
        'file_path',
        'page_count',
        'status',
        'provider',
        'error',
        'quiz_id',
        'deck_id',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => AiJobStatus::class,
            'target' => AiTarget::class,
            'page_count' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }

    public function deck(): BelongsTo
    {
        return $this->belongsTo(FlashcardDeck::class, 'deck_id');
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }
}
