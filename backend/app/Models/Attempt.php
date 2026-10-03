<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Attempt extends Model
{
    use HasFactory;

    protected $fillable = [
        'quiz_id',
        'student_id',
        'attempt_no',
        'question_order',
        'score',
        'max_score',
        'started_at',
        'submitted_at',
        'expired',
    ];

    protected function casts(): array
    {
        return [
            'question_order' => 'array',
            'score' => 'float',
            'max_score' => 'float',
            'started_at' => 'datetime',
            'submitted_at' => 'datetime',
            'expired' => 'boolean',
        ];
    }

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(AttemptAnswer::class);
    }

    public function isSubmitted(): bool
    {
        return $this->submitted_at !== null;
    }

    /**
     * RG-12 : une tentative dont le temps est écoulé est soumise
     * automatiquement. Le serveur corrige ce qui a déjà été enregistré.
     */
    public function hasTimeExpired(): bool
    {
        if ($this->isSubmitted() || $this->quiz->time_limit_min === null) {
            return false;
        }

        return now()->gte($this->started_at->copy()->addMinutes($this->quiz->time_limit_min));
    }

    public function remainingSeconds(): ?int
    {
        if ($this->isSubmitted() || $this->quiz->time_limit_min === null) {
            return null;
        }

        $deadline = $this->started_at->copy()->addMinutes($this->quiz->time_limit_min);

        return max(0, (int) now()->diffInSeconds($deadline, false));
    }

    public function percentage(): float
    {
        if (! $this->isSubmitted() || ! $this->max_score) {
            return 0.0;
        }

        return round(((float) $this->score / (float) $this->max_score) * 100, 1);
    }
}
