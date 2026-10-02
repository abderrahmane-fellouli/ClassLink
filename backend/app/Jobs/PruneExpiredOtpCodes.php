<?php

namespace App\Jobs;

use App\Services\OtpService;
use App\Services\TokenService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Purge périodique : §11 « index sur expires_at, purge régulière ».
 * §21 : tâche quotidienne appelée par la route protégée /internal/prune.
 */
class PruneExpiredOtpCodes implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly bool $includeTokens = true) {}

    public function handle(OtpService $otp, TokenService $tokens): int
    {
        $deleted = $otp->purgeExpired();

        if ($this->includeTokens) {
            $deleted += $tokens->purgeExpired();
        }

        return $deleted;
    }
}
