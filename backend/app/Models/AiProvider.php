<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AiProvider extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'priority', 'enabled', 'daily_limit', 'used_today', 'last_reset_at'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'priority' => 'integer',
            'daily_limit' => 'integer',
            'used_today' => 'integer',
            'last_reset_at' => 'datetime',
        ];
    }

    /** RG-14 / §15.3 : quota quotidien par fournisseur. */
    public function hasQuotaLeft(): bool
    {
        return $this->used_today < $this->daily_limit;
    }

    /** La clé secrète vit dans l'environnement, jamais en base (§15.2). */
    public function secretKey(): ?string
    {
        return config("services.ai.{$this->name}.key")
            ?: config("services.ai.{$this->name}.api_key");
    }

    public function endpoint(): ?string
    {
        return config("services.ai.{$this->name}.base_url");
    }

    public function model(): ?string
    {
        return config("services.ai.{$this->name}.model");
    }
}
