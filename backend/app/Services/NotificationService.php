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

    /**
     * @param  array<string, mixed>  $payload
     */
    public function notify(User $user, string $type, array $payload = []): AppNotification
    {
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
                    'error' => $e->getMessage(),
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
