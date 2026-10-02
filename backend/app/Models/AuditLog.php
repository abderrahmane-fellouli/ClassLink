<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AuditLog extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'action', 'context', 'ip'];

    protected function casts(): array
    {
        return ['context' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * RG-20 : le journal d'audit est strictement append-only.
     *
     * Une ligne déjà écrite ne doit jamais pouvoir être réécrite ni effacée,
     * même par un forgotten bug interne : toute tentative lève une exception
     * avant d'atteindre la base.
     */
    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('Le journal d\'audit est immuable : mise à jour refusée.');
        });

        static::deleting(function () {
            throw new LogicException('Le journal d\'audit est immuable : suppression refusée.');
        });
    }

    /**
     * RG-20 : écriture du journal d'audit.
     * Actions sensibles : connexion, déconnexion, décision d'adhésion,
     * changement de rôle, configuration IA.
     */
    public static function record(?User $user, string $action, array $context = []): self
    {
        return static::create([
            'user_id' => $user?->id,
            'action' => $action,
            'context' => $context === [] ? null : $context,
            'ip' => request()?->ip(),
        ]);
    }
}
