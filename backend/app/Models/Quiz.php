<?php

namespace App\Models;

use App\Enums\QuestionType;
use App\Enums\QuizStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Quiz extends Model
{
    use HasFactory;

    protected $fillable = [
        'classroom_id',
        'created_by',
        'title',
        'status',
        'source',
        'reviewed',
        'reviewed_at',
        'time_limit_min',
        'max_attempts',
        'shuffle',
        'show_answers',
        'published_at',
        'due_at',
    ];

    protected function casts(): array
    {
        return [
            'reviewed' => 'boolean',
            'reviewed_at' => 'datetime',
            'shuffle' => 'boolean',
            'show_answers' => 'boolean',
            'published_at' => 'datetime',
            'due_at' => 'datetime',
            'time_limit_min' => 'integer',
            'max_attempts' => 'integer',
        ];
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class)->orderBy('position');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(Attempt::class);
    }

    // -------------------------------------------------------------------------
    // RG-11 : seuls les quiz publiés sont visibles des étudiants.
    // -------------------------------------------------------------------------

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', QuizStatus::Published->value);
    }

    public function isPublished(): bool
    {
        return $this->status === QuizStatus::Published->value;
    }

    public function isAiGenerated(): bool
    {
        return $this->source === 'ai';
    }

/**
     * RG-11 / F-IA-03 : « Un quiz généré par l'IA ne peut être publié
     * qu'après relecture par l'enseignant. »
     */
    public function canBePublished(): bool
    {
        return ! $this->isAiGenerated() || ($this->reviewed && $this->reviewed_at !== null);
    }

    /**
     * F-IA-03 : pose la relecture et sa date.
     *
     * Appelée uniquement depuis une action explicite de l'enseignant
     * (`POST /quizzes/{id}/review`) ou depuis une modification réelle du
     * contenu : avoir édité les questions prouve qu'elles ont été relues.
     * Jamais depuis `publish()` — sinon publier validerait la relecture.
     */
    public function markReviewed(): bool
    {
        if ($this->reviewed && $this->reviewed_at !== null) {
            return false;
        }

        $this->forceFill([
            'reviewed' => true,
            'reviewed_at' => now(),
        ])->save();

        return true;
    }

    /** Barème : 1 point par question. */
    public function maxScore(): int
    {
        return $this->questions()->count();
    }

    public function types(): array
    {
        return QuestionType::values();
    }
}
