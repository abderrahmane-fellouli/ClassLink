<?php

namespace App\Services;

use App\Enums\ClassStatus;
use App\Enums\MembershipStatus;
use App\Enums\Role;
use App\Exceptions\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\Classroom;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Règles d'adhésion — §7 RG-06 à RG-10, Figure 4.
 *
 * T-07 : code valide -> statut pending + notification à l'enseignant
 * T-08 : deuxième demande pour la même classe -> 409, aucune duplication
 * T-09 : nouvelle demande 1 h après un rejet -> 429
 * T-10 : code désactivé -> 404 « Code invalide »
 */
class MembershipService
{
    public function __construct(
        private readonly NotificationService $notifications,
    ) {}

    /**
     * F-REQ-01 — envoie une demande d'adhésion à partir d'un code.
     *
     * @throws BusinessRuleException
     */
    public function request(User $student, string $rawCode): Membership
    {
        $code = JoinCodeService::normalize($rawCode);

        // RG-08 : un code désactivé, ou appartenant à une classe archivée
        // (RG-10), refuse toute nouvelle demande.
        $classroom = Classroom::where('join_code', $code)
            ->where('join_enabled', true)
            ->where('status', ClassStatus::Active->value)
            ->first();

        if (! $classroom) {
            throw new BusinessRuleException('Code invalide', 404);
        }

        $membership = Membership::where('classroom_id', $classroom->id)
            ->where('student_id', $student->id)
            ->first();

        // RG-06 : une seule demande en attente par classe. Déjà membre -> 409.
        if ($membership && in_array($membership->status, [
            MembershipStatus::Pending->value,
            MembershipStatus::Accepted->value,
        ], true)) {
            throw new BusinessRuleException(
                $membership->isAccepted()
                    ? 'Vous êtes déjà membre de cette classe.'
                    : 'Demande déjà envoyée.',
                409
            );
        }

        // RG-07 : 24 h de délai après un rejet. T-09 vérifie 1 h après.
        if ($membership && $membership->isInRejectionCooldown()) {
            throw new BusinessRuleException(
                'Réessayez plus tard.',
                429,
                ['retry_after_hours' => (int) config('classlink.membership.rejection_cooldown_hours', 24)]
            );
        }

        $membership = Membership::updateOrCreate(
            ['classroom_id' => $classroom->id, 'student_id' => $student->id],
            [
                'status' => MembershipStatus::Pending->value,
                'requested_at' => Carbon::now(),
                'decided_at' => null,
                'decided_by' => null,
            ]
        );

        // F-REQ-10 : notification à l'enseignant.
        $this->notifications->notify($classroom->teacher, NotificationService::JOIN_REQUESTED, [
            'membership_id' => $membership->id,
            'classroom_id' => $classroom->id,
            'classroom_name' => $classroom->name,
            'student_name' => $student->display_name,
        ]);

        return $membership->fresh();
    }

    /** F-REQ-04 — accepter une demande. */
    public function accept(User $teacher, Membership $membership): Membership
    {
        $this->assertOwner($teacher, $membership);
        $this->assertPending($membership, 'accepter');

        $membership->update([
            'status' => MembershipStatus::Accepted->value,
            'decided_at' => Carbon::now(),
            'decided_by' => $teacher->id,
        ]);

        $this->notifications->notify($membership->student, NotificationService::MEMBERSHIP_ACCEPTED, [
            'membership_id' => $membership->id,
            'classroom_id' => $membership->classroom_id,
            'classroom_name' => $membership->classroom->name,
        ]);

        AuditLog::record($teacher, 'membership.accept', [
            'membership_id' => $membership->id,
            'classroom_id' => $membership->classroom_id,
        ]);

        return $membership->fresh();
    }

    /** F-REQ-04 — rejeter une demande. */
    public function reject(User $teacher, Membership $membership): Membership
    {
        $this->assertOwner($teacher, $membership);
        $this->assertPending($membership, 'rejeter');

        $membership->update([
            'status' => MembershipStatus::Rejected->value,
            'decided_at' => Carbon::now(),
            'decided_by' => $teacher->id,
        ]);

        $this->notifications->notify($membership->student, NotificationService::MEMBERSHIP_REJECTED, [
            'membership_id' => $membership->id,
            'classroom_id' => $membership->classroom_id,
            'classroom_name' => $membership->classroom->name,
            // RG-07 : le délai de 24 h est annoncé à l'étudiant.
            'cooldown_hours' => (int) config('classlink.membership.rejection_cooldown_hours', 24),
        ]);

        AuditLog::record($teacher, 'membership.reject', [
            'membership_id' => $membership->id,
            'classroom_id' => $membership->classroom_id,
        ]);

        return $membership->fresh();
    }

    /** F-REQ-05 — accepter toutes les demandes en attente. */
    public function acceptAll(User $teacher, Classroom $classroom): int
    {
        $this->assertClassOwner($teacher, $classroom);

        $pending = $classroom->memberships()
            ->where('status', MembershipStatus::Pending->value)
            ->with('student')
            ->get();

        $count = 0;

        foreach ($pending as $membership) {
            $this->accept($teacher, $membership);
            $count++;
        }

        return $count;
    }

    /**
     * F-REQ-08 / RG-09 — retirer un étudiant.
     * « Un étudiant retiré perd l'accès immédiatement, ses résultats restent
     * conservés. » Aucune donnée de résultat n'est supprimée.
     */
    public function remove(User $teacher, Classroom $classroom, User $student): Membership
    {
        $this->assertClassOwner($teacher, $classroom);

        $membership = Membership::where('classroom_id', $classroom->id)
            ->where('student_id', $student->id)
            ->firstOrFail();

        $membership->update([
            'status' => MembershipStatus::Removed->value,
            'decided_at' => Carbon::now(),
            'decided_by' => $teacher->id,
        ]);

        $this->notifications->notify($student, NotificationService::MEMBERSHIP_REMOVED, [
            'membership_id' => $membership->id,
            'classroom_id' => $classroom->id,
            'classroom_name' => $classroom->name,
        ]);

        AuditLog::record($teacher, 'membership.remove', [
            'membership_id' => $membership->id,
            'classroom_id' => $classroom->id,
            'student_id' => $student->id,
        ]);

        return $membership->fresh();
    }

    /**
     * F-REQ-09 (Could) — import CSV de la liste officielle, approbation
     * automatique. Le rôle est ici décidé par l'enseignant : RG-04.
     */
    public function importAccepted(User $teacher, Classroom $classroom, array $rows): array
    {
        $this->assertClassOwner($teacher, $classroom);

        return DB::transaction(function () use ($teacher, $classroom, $rows) {
            $count = 0;
            $accepted = [];
            $errors = [];
            $seen = [];

            foreach ($rows as $index => $row) {
                $number = $row['_row'] ?? $index + 2;
                $email = strtolower(trim((string) ($row['email'] ?? '')));
                if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = ['row' => $number, 'reason' => 'invalid_email'];
                    continue;
                }
                if (isset($seen[$email])) {
                    $errors[] = ['row' => $number, 'reason' => 'duplicate_email'];
                    continue;
                }
                $seen[$email] = true;

                $student = User::where('email', $email)->first();

                if (! $student || ! $student->isStudent() || ! $student->is_active) {
                    $errors[] = ['row' => $number, 'reason' => 'active_student_not_found'];
                    continue;
                }

                Membership::updateOrCreate(
                    ['classroom_id' => $classroom->id, 'student_id' => $student->id],
                    [
                        'status' => MembershipStatus::Accepted->value,
                        'requested_at' => Carbon::now(),
                        'decided_at' => Carbon::now(),
                        'decided_by' => $teacher->id,
                    ]
                );

                $count++;
                $accepted[] = ['row' => $number, 'student_id' => $student->id, 'display_name' => $student->display_name];
            }

            AuditLog::record($teacher, 'membership.import', ['classroom_id' => $classroom->id, 'imported' => $count]);
            return ['imported' => $count, 'accepted' => $accepted, 'errors' => $errors];
        });
    }

    private function assertOwner(User $teacher, Membership $membership): void
    {
        $membership->loadMissing('classroom');

        if (! $membership->classroom->isOwnedBy($teacher)) {
            throw new BusinessRuleException('Action non autorisée.', 403);
        }
        $this->assertClassOwner($teacher, $membership->classroom);
    }

    /**
     * RG-06 / Figure 4 — une décision ne porte que sur une demande *en attente*.
     *
     * Sans cette garde, `accept`/`reject` étaient réversibles : un enseignant
     * pouvait « rejeter » un étudiant déjà accepté — c'est-à-dire le retirer
     * par le mauvais chemin, avec la notification `MEMBERSHIP_REJECTED` et le
     * délai de 24 h au lieu de `MEMBERSHIP_REMOVED` — ou ré-accepter un étudiant
     * retiré. Chaque décision émettait aussi une seconde notification et une
     * seconde ligne d'audit pour un état déjà décidé.
     *
     * Le retrait reste le seul moyen de rompre une adhésion : `remove()`.
     */
    private function assertPending(Membership $membership, string $action): void
    {
        if ($membership->isPending()) {
            return;
        }

        throw new BusinessRuleException(
            sprintf('Seule une demande en attente peut être %s.', $action),
            409,
            ['status' => $membership->status]
        );
    }

    private function assertClassOwner(User $teacher, Classroom $classroom): void
    {
        if (! $classroom->isOwnedBy($teacher)) {
            throw new BusinessRuleException('Action non autorisée.', 403);
        }
        if ($classroom->isReadOnly()) {
            throw new BusinessRuleException('Archived classes are read-only.', 409);
        }
    }

    /** Rappel : un admin n'est pas déduit de Role::Admin côté contrôleur. */
    public static function isTeacher(User $user): bool
    {
        return $user->role === Role::Teacher->value || $user->role === Role::Admin->value;
    }
}
