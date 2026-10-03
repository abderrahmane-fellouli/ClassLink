<?php

namespace App\Models;

use App\Enums\Role;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'email',
        'display_name',
        'role',
        'role_locked',
        'locale',
        'is_active',
        'last_login_at',
    ];

    protected $hidden = [
        // RG-18 / §16 : l'email n'est jamais renvoyé par défaut. Il est
        // exposé explicitement, et uniquement, par UserResource aux
        //	contextes légitimes (le concerned, son enseignant, l'admin).
        'email',
    ];

    protected function casts(): array
    {
        return [
            'role_locked' => 'boolean',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'notification_preferences' => 'array',
            'last_digest_at' => 'datetime',
        ];
    }

    // -------------------------------------------------------------------------
    // Raccourcis de rôle — évite de comparer des chaînes partout.
    // -------------------------------------------------------------------------

    public function isStudent(): bool
    {
        return $this->role === Role::Student->value;
    }

    public function isTeacher(): bool
    {
        return $this->role === Role::Teacher->value;
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin->value;
    }

    /** RG-05 : un compte « en attente » ou refusé n'accède à rien. */
    public function canAccessApp(): bool
    {
        return $this->is_active && Role::from($this->role)->canLogin();
    }

    /** F-AUTH-09 : initiales du nom d'affichage. */
    public function initials(): string
    {
        $parts = preg_split('/\s+/u', trim($this->display_name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $letters = array_map(
            static fn (string $part): string => mb_substr($part, 0, 1),
            array_slice($parts, 0, 2)
        );

        return mb_strtoupper(implode('', $letters)) ?: '?';
    }

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function taughtClassrooms(): HasMany
    {
        return $this->hasMany(Classroom::class, 'teacher_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class, 'student_id');
    }

    /** Classes dont l'adhésion est « accepted » (RG-05). */
    public function acceptedClassrooms()
    {
        return Classroom::whereIn('id', $this->memberships()
            ->where('status', \App\Enums\MembershipStatus::Accepted->value)
            ->select('classroom_id'));
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(Attempt::class, 'student_id');
    }

    public function appNotifications(): HasMany
    {
        return $this->hasMany(AppNotification::class);
    }

    public function partnerProfile(): HasOne
    {
        return $this->hasOne(PartnerProfile::class);
    }
}
