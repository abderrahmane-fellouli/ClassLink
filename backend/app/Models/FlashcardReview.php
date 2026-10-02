<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * §11 (F-QUI-08) — état de révision d'une carte par un étudiant.
 *
 * `known = true` -> « su », `known = false` -> « à revoir ».
 */
class FlashcardReview extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'flashcard_id', 'known'];

    protected function casts(): array
    {
        return ['known' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function flashcard(): BelongsTo
    {
        return $this->belongsTo(Flashcard::class);
    }
}