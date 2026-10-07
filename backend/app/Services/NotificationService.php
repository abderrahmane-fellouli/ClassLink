<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * F-NOT-01 — notifications dans l'application.
 *
 * Les notifications sont écrites dans la table `notifications` (§11) et
 * exposées par GET /api/notifications. Aucun email n'est contenu dans le
 * payload : RG-18 / F-PAR-04 interdisent d'exposer une adresse à un autre
 * utilisateur.
 */
class NotificationService
{
    public const JOIN_REQUESTED = 'join_requested';
    public const MEMBERSHIP_ACCEPTED = 'membership_accepted';
    public const MEMBERSHIP_REJECTED = 'membership_rejected';
    public const MEMBERSHIP_REMOVED = 'membership_removed';
    public const QUIZ_PUBLISHED = 'quiz_published';
    public const GRADED = 'graded';
    public const PARTNER_REQUEST_RECEIVED = 'partner_request_received';
    public const PARTNER_REQUEST_ANSWERED = 'partner_request_answered';
    public const AI_JOB_FINISHED = 'ai_job_finished';
    public const ANNOUNCEMENT_PUBLISHED = 'announcement_published';
    public const ASSIGNMENT_PUBLISHED = 'assignment_published';
    public const OFFICIAL_GRADE_PUBLISHED = 'official_grade_published';
    public const SCHOOL_MESSAGE_RECEIVED = 'school_message_received';
    public const TEACHING_ASSIGNMENT_CHANGED = 'teaching_assignment_changed';
    public const ASSIGNMENT_REQUEST_RECEIVED = 'assignment_request_received';
    public const DELEGATE_CHANGED = 'delegate_changed';
    public const RESOURCE_PUBLISHED = 'resource_published';
    public const DEADLINE_CHANGED = 'deadline_changed';

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function types(): array
    {
        return [self::JOIN_REQUESTED, self::MEMBERSHIP_ACCEPTED, self::MEMBERSHIP_REJECTED,
            self::MEMBERSHIP_REMOVED, self::QUIZ_PUBLISHED, self::GRADED,
            self::PARTNER_REQUEST_RECEIVED, self::PARTNER_REQUEST_ANSWERED, self::AI_JOB_FINISHED,
            self::ANNOUNCEMENT_PUBLISHED, self::ASSIGNMENT_PUBLISHED,
            self::OFFICIAL_GRADE_PUBLISHED, self::SCHOOL_MESSAGE_RECEIVED,
            self::TEACHING_ASSIGNMENT_CHANGED, self::ASSIGNMENT_REQUEST_RECEIVED, self::DELEGATE_CHANGED,
            self::RESOURCE_PUBLISHED, self::DEADLINE_CHANGED];
    }

    public function notify(User $user, string $type, array $payload = []): ?AppNotification
    {
        if (($user->notification_preferences['types'][$type] ?? true) === false) {
            return null;
        }
        return AppNotification::create([
            'user_id' => $user->id,
            'type' => $type,
            'payload' => $payload,
        ]);
    }

    /**
     * Notifie plusieurs utilisateurs sans qu'un échec unitaire interrompe la
     * série (classe supprimée entre-temps, compte désactivé…).
     *
     * @param  iterable<int, User>  $users
     * @param  array<string, mixed>  $payload
     */
    public function notifyMany(iterable $users, string $type, array $payload = []): void
    {
        foreach ($users as $user) {
            try {
                $this->notify($user, $type, $payload);
            } catch (\Throwable $e) {
                Log::warning('Notification failed', [
                    'user_id' => $user->id,
                    'type' => $type,
                    'exception_class' => $e::class,
                ]);
            }
        }
    }

    public function unreadCount(int $userId): int
    {
        return AppNotification::where('user_id', $userId)
            ->whereNull('read_at')
            ->count();
    }
}
