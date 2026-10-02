<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerProfile extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'opt_in', 'skills', 'availability'];

    protected function casts(): array
    {
        return [
            'opt_in' => 'boolean',
            'skills' => 'array',
            'availability' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
