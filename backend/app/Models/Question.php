<?php

namespace App\Models;

use App\Enums\QuestionType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Question extends Model
{
    use HasFactory;

    protected $fillable = [
        'quiz_id',
        'statement',
        'type',
        'explanation',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'type' => QuestionType::class,
            'position' => 'integer',
        ];
    }

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(Option::class);
    }

    /** @return array<int, int> */
    public function correctOptionIds(): array
    {
        return $this->options->where('is_correct', true)->pluck('id')
            ->map(static fn ($id) => (int) $id)->sort()->values()->all();
    }
}
