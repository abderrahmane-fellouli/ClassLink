<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Flashcard extends Model
{
    use HasFactory;

    protected $fillable = ['deck_id', 'front', 'back', 'position'];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    public function deck(): BelongsTo
    {
        return $this->belongsTo(FlashcardDeck::class, 'deck_id');
    }

    /** §11 (F-QUI-08) : état « su » / « à revoir » par étudiant. */
    public function reviews(): HasMany
    {
        return $this->hasMany(FlashcardReview::class, 'flashcard_id');
    }
}
