<?php

namespace App\Services;

use App\Enums\MembershipStatus;
use App\Models\Announcement;
use App\Models\User;
use Illuminate\Support\Carbon;
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

        $students = User::where('role', 'student')
            ->where('is_active', true)
            ->with(['memberships' => fn ($q) => $q->where('status', MembershipStatus::Accepted->value)])
            ->get();

        foreach ($students as $student) {
            if ($student->memberships->isEmpty()) {
                continue;
            }

            $classIds = $student->memberships->pluck('classroom_id');

            $announcements = Announcement::whereIn('classroom_id', $classIds)
                ->where('created_at', '>=', $since)
                ->with('classroom:id,name')
                ->orderByDesc('pinned')
                ->orderByDesc('created_at')
                ->get();

            if ($announcements->isEmpty()) {
                continue;
            }

            $this->send($student, $announcements);
            $sent++;
        }

        Log::info('Résumé quotidien envoyé', ['emails' => $sent]);

        return $sent;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Announcement>  $announcements
     */
    private function send(User $student, $announcements): void
    {
        $lines = $announcements->map(
            fn (Announcement $a) => "- [{$a->classroom?->name}] {$a->title}"
        )->implode("\n");

        $body = $student->locale === 'en'
            ? "New announcements in your classes:\n\n{$lines}"
            : "Nouvelles annonces dans vos classes :\n\n{$lines}";

        try {
            Mail::raw($body, function ($message) use ($student) {
                $message->to($student->email)->subject('ClassLink — résumé du jour');
            });
        } catch (\Throwable $e) {
            Log::warning('Envoi du résumé impossible', [
                'user_id' => $student->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
