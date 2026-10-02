<?php

namespace App\Console\Commands;

use App\Services\EmailDigestService;
use Illuminate\Console\Command;

/** F-NOT-02 — résumé quotidien par email (Brevo). */
class DailyDigestCommand extends Command
{
    protected $signature = 'classlink:daily-digest';

    protected $description = 'Envoie le résumé quotidien des annonces par email.';

    public function handle(EmailDigestService $digest): int
    {
        $sent = $digest->sendDailyDigest();

        $this->info("{$sent} email(s) envoyé(s).");

        return self::SUCCESS;
    }
}
