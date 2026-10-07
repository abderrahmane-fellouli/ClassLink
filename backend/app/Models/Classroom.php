<?php

namespace App\Models;

use App\Enums\ClassStatus;
use App\Enums\MembershipStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Classroom extends Model
{
    use HasFactory;

    protected $fillable = [
        'teacher_id',
        'name',
        'subject',
        'group_label',
        'school_year',
        'join_code',
        'join_enabled',
        'status',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'is_official' => 'boolean',
            'coordinator_can_manage_roster' => 'boolean',
            'join_enabled' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function materials(): HasMany
    {
        return $this->hasMany(Material::class);
    }

    public function announcements(): HasMany
    {
        return $this->hasMany(Announcement::class);
    }

    public function quizzes(): HasMany
    {
        return $this->hasMany(Quiz::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class);
    }

    public function flashcardDecks(): HasMany
    {
        return $this->hasMany(FlashcardDeck::class);
    }

    // -------------------------------------------------------------------------
    // Aides métier
    // -------------------------------------------------------------------------

    public function isArchived(): bool
    {
        return $this->status === ClassStatus::Archived->value;
    }

    /** RG-10 : une classe archivée refuse toute écriture. */
    public function isReadOnly(): bool
    {
        return ClassStatus::from($this->status)->isReadOnly();
    }

    /** RG-05 : l'étudiant a-t-il une adhésion acceptée à cette classe ? */
    public function hasAcceptedMember(int $userId): bool
    {
        if ($this->is_official && ($scope = request()?->attributes->get('school_offering_id'))) {
            $user = User::find($userId);
            if ($user && \Illuminate\Support\Facades\DB::table('module_offerings')->where('id', $scope)->where('classroom_id', $this->id)->exists()
                && app(\App\Services\SchoolAccess::class)->exceptional($user, $scope)) { return true; }
        }
        return $this->memberships()
            ->where('student_id', $userId)
            ->where('status', MembershipStatus::Accepted->value)
            ->exists();
    }

    /** RG-04 : seul le propriétaire (ou un admin) gère la classe. */
    public function isOwnedBy(User $user): bool
    {
        if ($this->is_official) {
            $offering = request()?->attributes->get('school_offering_id');
            return $offering && \Illuminate\Support\Facades\DB::table('module_offerings')->where('id', $offering)->where('classroom_id', $this->id)->exists()
                && app(\App\Services\SchoolAccess::class)->teaches($user, $offering);
        }
        return $user->isAdmin() || ($user->isTeacher() && $this->teacher_id === $user->id);
    }

    public function members()
    {
        return $this->memberships()
            ->where('status', MembershipStatus::Accepted->value)
            ->with('student');
    }
}
