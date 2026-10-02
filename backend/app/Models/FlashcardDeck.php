<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FlashcardDeck extends Model
{
    use HasFactory;

    protected $fillable = ['classroom_id', 'title', 'source', 'status', 'reviewed'];

    protected function casts(): array
    {
        return ['reviewed' => 'boolean'];
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    public function cards(): HasMany
    {
        // La colonne est `deck_id` et non la clé dérivée `flashcard_deck_id`.
        return $this->hasMany(Flashcard::class, 'deck_id')->orderBy('position');
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }
}
