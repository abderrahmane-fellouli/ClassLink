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
        return $this->memberships()
            ->where('student_id', $userId)
            ->where('status', MembershipStatus::Accepted->value)
            ->exists();
    }

    /** RG-04 : seul le propriétaire (ou un admin) gère la classe. */
    public function isOwnedBy(User $user): bool
    {
        return $user->isAdmin() || ($user->isTeacher() && $this->teacher_id === $user->id);
    }

    public function members()
    {
        return $this->memberships()
            ->where('status', MembershipStatus::Accepted->value)
            ->with('student');
    }
}
