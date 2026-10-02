<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * F-NOT-01 — notification applicative.
 *
 * La table est celle du dictionnaire §11 (user_id, type, payload, read_at)
 * et non la table par défaut de Laravel, dont la forme ne correspond pas.
 */
class AppNotification extends Model
{
    use HasFactory;

    protected $table = 'notifications';

    protected $fillable = ['user_id', 'type', 'payload', 'read_at'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'read_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }
}
