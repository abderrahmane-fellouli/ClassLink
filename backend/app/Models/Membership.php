<?php

namespace App\Models;

use App\Enums\MembershipStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Membership extends Model
{
    use HasFactory;

    protected $fillable = [
        'classroom_id',
        'student_id',
        'status',
        'requested_at',
        'decided_at',
        'decided_by',
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isPending(): bool
    {
        return $this->status === MembershipStatus::Pending->value;
    }

    public function isAccepted(): bool
    {
        return $this->status === MembershipStatus::Accepted->value;
    }

    public function isRejected(): bool
    {
        return $this->status === MembershipStatus::Rejected->value;
    }

    /**
     * RG-07 : après un rejet, l'étudiant doit attendre 24 heures avant de
     * redemander l'accès.
     */
    public function isInRejectionCooldown(): bool
    {
        if (! $this->isRejected() || $this->decided_at === null) {
            return false;
        }

        $hours = (int) config('classlink.membership.rejection_cooldown_hours', 24);

        return $this->decided_at->gt(now()->subHours($hours));
    }
}
