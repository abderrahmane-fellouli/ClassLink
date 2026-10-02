<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OtpCode extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'email',
        'code_hash',
        'attempts',
        'expires_at',
        'consumed_at',
        'ip',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    /** §11 : le code n'est jamais stocké en clair. */
    public static function hash(string $code): string
    {
        return hash('sha256', $code);
    }

    public function isExpired(): bool
    {
        return now()->gt($this->expires_at);
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    /** F-AUTH-02 : 5 essais maximum, le 6e est refusé sans jeton (T-06). */
    public function hasAttemptsLeft(): bool
    {
        return $this->attempts < (int) config('classlink.otp.max_attempts', 5);
    }
}
