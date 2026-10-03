<?php

namespace App\Console\Commands;

use App\Services\QuizGradingService;
use Illuminate\Console\Command;

class FinalizeExpiredAttemptsCommand extends Command
{
    protected $signature = 'classlink:finalize-attempts';
    protected $description = 'Grade expired attempts using only saved answers';

    public function handle(QuizGradingService $grading): int
    {
        $this->info((string) $grading->finalizeExpired());
        return self::SUCCESS;
    }
}
