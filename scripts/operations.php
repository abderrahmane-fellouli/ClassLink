<?php

// Operator CLI and generic HTTP readiness; never expose credentials or payloads.
$mode = PHP_SAPI === 'cli' ? ($argv[1] ?? 'health') : 'health';
try {
    $root = is_file(__DIR__.'/../artisan') ? __DIR__.'/..' : __DIR__.'/../backend';
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if ($mode === 'preflight') {
        if ($app->environment('production')) {
            $key = config('app.key');
            $bytes = str_starts_with((string) $key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;
            if (!is_string($bytes) || strlen($bytes) !== 32 || config('app.debug') || config('classlink.dev_auth.enabled')) {
                throw new RuntimeException('Invalid production key/debug/auth configuration.');
            }
            if (config('database.default') !== 'pgsql' || config('queue.default') !== 'database' || config('cache.default') !== 'database' || config('filesystems.default') !== 's3') {
                throw new RuntimeException('Production requires PostgreSQL, database queue/cache and private S3.');
            }
            if (!in_array(config('database.connections.pgsql.sslmode'), ['require', 'verify-ca', 'verify-full'], true)) {
                throw new RuntimeException('Production PostgreSQL requires TLS.');
            }
            foreach (['key', 'secret', 'bucket', 'region'] as $field) {
                if (!config('filesystems.disks.s3.'.$field)) {
                    throw new RuntimeException('Missing required S3 configuration.');
                }
            }
            $endpoint = config('filesystems.disks.s3.endpoint');
            if ($endpoint && parse_url($endpoint, PHP_URL_SCHEME) !== 'https') {
                throw new RuntimeException('Production S3 endpoint must use HTTPS.');
            }
            if (config('mail.default') !== 'smtp' || !config('mail.mailers.smtp.password')) {
                throw new RuntimeException('Configure SMTP before serving production OTP login.');
            }
        }
        if ((int) config('queue.connections.database.retry_after') <= 900) {
            throw new RuntimeException('DB_QUEUE_RETRY_AFTER must exceed worker timeout (900s).');
        }
    } elseif ($mode === 'schedule') {
        // Shared lock avoids double digest/quota resets during rolling deploys.
        $lock = Illuminate\Support\Facades\Cache::lock('infra:scheduler-lock', 900);
        if ($lock->get()) {
            try {
                if (Illuminate\Support\Facades\Cache::add('infra:scheduled-minute:'.intdiv(time(), 60), 1, 120)) {
                    $process = new Symfony\Component\Process\Process([PHP_BINARY, $root.'/artisan', 'schedule:run', '--no-interaction'], $root, null, null, 840);
                    $process->mustRun(function ($type, $output) { echo $output; });
                    Illuminate\Support\Facades\Cache::put('infra:scheduler-heartbeat', time(), 3600);
                }
            } finally {
                $lock->release();
            }
        }
    } elseif ($mode === 'capacity' && PHP_SAPI === 'cli') {
        $nearLimit = Illuminate\Support\Facades\DB::table('ai_providers')->where('enabled', true)
            ->where('daily_limit', '>', 0)->whereRaw('used_today >= daily_limit * 0.9')->count();
        echo "AI providers at >=90% quota: $nearLimit\n";
        if ($nearLimit > 0) {
            throw new RuntimeException('AI capacity needs review; manual authoring remains available.');
        }
    } elseif ($mode === 'health') {
        Illuminate\Support\Facades\DB::select('select 1');
        $stamp = Illuminate\Support\Facades\Cache::get('infra:scheduler-heartbeat', 0);
        if (time() - $stamp > 900) {
            throw new RuntimeException('Scheduler heartbeat stale.');
        }
        $oldest = Illuminate\Support\Facades\DB::table('jobs')->min('created_at');
        $failed = Illuminate\Support\Facades\DB::table('failed_jobs')->count();
        if (($oldest && time() - $oldest > 1800) || $failed > 0) {
            throw new RuntimeException('Queue backlog or failed jobs require operator review.');
        }
    } else {
        throw new RuntimeException('Unknown operations mode.');
    }
    echo "Infrastructure $mode OK\n";
} catch (Throwable $e) {
    // Driver exceptions can contain credentials; keep them out of monitor output.
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Infrastructure check failed ($mode). Review private service logs and runbook.\n");
    } else {
        http_response_code(503);
        echo "Infrastructure unavailable\n";
    }
    exit(1);
}
