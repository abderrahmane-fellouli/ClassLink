<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FlashcardDeck extends Model
{
    use HasFactory;
    use \App\Models\Concerns\ScopedToOffering;

    protected $fillable = ['classroom_id', 'title', 'source', 'status', 'reviewed', 'reviewed_at'];

    protected function casts(): array
    {
        return [
            'reviewed' => 'boolean',
            'reviewed_at' => 'datetime',
        ];
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

    /**
     * F-IA-03 : même contrat que `Quiz::markReviewed()` — la relecture n'est
     * posée que par une action explicite ou une modification du contenu, pas
     * par la publication.
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
}
