<?php

namespace App\Jobs;

use App\Models\AppNotification;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class DeliverSchoolNotifications implements ShouldQueue
{
    use Queueable;

    public $tries = 3;

    public function handle(): void
    {
        $ids = DB::table('school_notification_outbox')->whereNull('delivered_at')->orderBy('id')->limit(200)->pluck('id');
        foreach ($ids as $id) {
            DB::transaction(function () use ($id) {
                $event = DB::table('school_notification_outbox')->where('id', $id)->lockForUpdate()->first();
                if (! $event || $event->delivered_at) {
                    return;
                }
                $user = User::find($event->user_id);
                if ($user?->canAccessApp() && ($user->notification_preferences['types'][$event->type] ?? true)) {
                    AppNotification::firstOrCreate(['school_outbox_id' => $id], ['user_id' => $user->id, 'type' => $event->type, 'payload' => json_decode($event->payload, true)]);
                }
                DB::table('school_notification_outbox')->where('id', $id)->update(['delivered_at' => now(), 'updated_at' => now()]);
            });
        }
    }
}
