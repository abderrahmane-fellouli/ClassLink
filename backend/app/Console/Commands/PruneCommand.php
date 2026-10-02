<?php

namespace App\Console\Commands;

use App\Jobs\PruneExpiredOtpCodes;
use Illuminate\Console\Command;

/** §11 — purge régulière des codes à usage unique et des jetons expirés. */
class PruneCommand extends Command
{
    protected $signature = 'classlink:prune';

    protected $description = 'Supprime les codes OTP et les jetons expirés.';

    public function handle(PruneExpiredOtpCodes $job): int
    {
        /*
         * `handle()` attend OtpService et TokenService : seul le conteneur sait
         * les fournir. Un appel direct `$job->handle()` echouerait, d'ou
         * `app()->call()`, qui imite exactement la resolution du worker.
         */
        $deleted = app()->call([$job, 'handle']);

        $this->info("{$deleted} enregistrement(s) supprimé(s).");

        return self::SUCCESS;
    }
}
