<?php

namespace App\Services;

use App\Enums\MembershipStatus;
use App\Models\Announcement;
use App\Models\AppNotification;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * F-NOT-02 / F-CON-05 — résumé quotidien par email (Brevo).
 *
 * §17.11 : « Le résumé quotidien est déclenché par une route protégée
 * appelée par une tâche planifiée GitHub Actions. »
 */
class EmailDigestService
{
    /**
     * Envoie le résumé des annonces de la veille aux étudiants actifs.
     *
     * @return int nombre d'emails envoyés
     */
    public function sendDailyDigest(): int
    {
        $since = Carbon::now()->subDay();
        $sent = 0;

        $students = User::whereIn('role', ['student', 'teacher', 'admin'])
            ->where('is_active', true)
            ->with(['memberships' => fn ($q) => $q->where('status', MembershipStatus::Accepted->value)])
            ->get();

        foreach ($students as $student) {
            $preferences = $student->notification_preferences ?? [];
            if (($preferences['email_digest'] ?? true) === false) {
                continue;
            }
            $classIds = $student->isStudent() ? $student->memberships->pluck('classroom_id') : collect();

            $announcements = Announcement::whereIn('classroom_id', $classIds)
                ->where('created_at', '>=', $since)
                ->with('classroom:id,name')
                ->orderByDesc('pinned')
                ->orderByDesc('created_at')
                ->get();
            if (($preferences['types'][NotificationService::ANNOUNCEMENT_PUBLISHED] ?? true) === false) {
                $announcements = collect();
            }

            $lines = $announcements->map(fn (Announcement $a) => "- [{$a->classroom?->name}] {$a->title}");
            $notifications = AppNotification::where('user_id', $student->id)->where('created_at', '>=', $since)->get();
            foreach ($notifications as $notification) {
                if (($preferences['types'][$notification->type] ?? true) === false
                    || ($student->isStudent() && $notification->type === NotificationService::ANNOUNCEMENT_PUBLISHED)) {
                    continue;
                }
                $label = __('api.digest.types.'.$notification->type, [], $student->locale);
                $lines->push('- '.$label.': '.($notification->payload['title'] ?? $notification->payload['classroom_name'] ?? 'ClassLink'));
            }
            if ($lines->isEmpty()) {
                continue;
            }

            // Reserve before transport: ambiguous mail failures are not retried today.
            $claimed = User::whereKey($student->id)->where(function ($query) {
                $query->whereNull('last_digest_at')->orWhere('last_digest_at', '<', now()->startOfDay());
            })->update(['last_digest_at' => now()]);
            if ($claimed && $this->send($student, $lines)) {
                $sent++;
            }
        }

        Log::info('Résumé quotidien envoyé', ['emails' => $sent]);

        return $sent;
    }

    /**
     * @param  Collection<int, string>  $lines
     */
    private function send(User $student, $lines): bool
    {
        $lines = $lines->implode("\n");

        $body = __('api.digest.heading', [], $student->locale)."\n\n{$lines}\n\n"
            .rtrim(config('classlink.frontend_url'), '/').'/app';

        try {
            Mail::raw($body, function ($message) use ($student) {
                $message->to($student->email)->subject(__('api.digest.subject', [], $student->locale));
            });

            return true;
        } catch (\Throwable $e) {
            Log::warning('Envoi du résumé impossible', [
                'user_id' => $student->id,
                'exception_type' => get_class($e),
            ]);

            return false;
        }
    }
}
