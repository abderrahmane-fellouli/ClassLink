<?php

namespace App\Models;

use App\Enums\MaterialType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Material extends Model
{
    use HasFactory;
    use \App\Models\Concerns\ScopedToOffering;

    protected $fillable = [
        'category',
        'classroom_id',
        'title',
        'chapter',
        'type',
        'path_or_url',
        'file_name',
        'mime_type',
        'file_size',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => MaterialType::class,
            'file_size' => 'integer',
        ];
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isLink(): bool
    {
        return $this->type === MaterialType::Link;
    }
}
