<?php

use App\Services\AiService;
use App\Services\EmailDigestService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Tâches planifiées - §17.11 / §21
|--------------------------------------------------------------------------
| Le résumé quotidien est déclenché par une route protégée appelée par
| GitHub Actions (voir .github/workflows/daily-digest.yml). Les commandes
| ci-dessous sont appelées par cette route interne.
*/

Schedule::command('classlink:daily-digest')->dailyAt('07:00');
Schedule::command('classlink:prune')->dailyAt('03:00');
Schedule::command('classlink:finalize-attempts')->everyMinute()->withoutOverlapping();
Schedule::job(new \App\Jobs\DeliverSchoolNotifications)->everyMinute()->withoutOverlapping();
Schedule::call(fn () => \Illuminate\Support\Facades\DB::table('class_delegates')->whereNotNull('active_slot')->where('ends_at', '<=', now())->update(['active_slot' => null, 'updated_at' => now()]))->name('school:end-expired-delegates')->everyMinute()->withoutOverlapping();
Schedule::call(fn (AiService $ai) => $ai->resetDailyQuotas())->dailyAt('00:05');
