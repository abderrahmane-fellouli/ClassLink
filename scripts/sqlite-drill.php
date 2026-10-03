<?php

// Requires an isolated throwaway database supplied by sqlite-drill.mjs.
require __DIR__.'/../backend/vendor/autoload.php';
$app = require __DIR__.'/../backend/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
if ($app->environment('production') || config('database.default') !== 'sqlite'
    || !str_contains(config('database.connections.sqlite.database'), 'classlink-infra-')) {
    throw new RuntimeException('Refusing to run fixtures outside the disposable drill database.');
}
try {
if (($argv[1] ?? '') === 'setup') {
    if ($kernel->call('migrate', ['--force' => true]) !== 0) {
        throw new RuntimeException('Migration failed.');
    }
    $user = App\Models\User::create(['email' => 'fixture@example.invalid', 'display_name' => 'Infra Fixture', 'role' => 'student', 'locale' => 'en', 'is_active' => true]);
    Illuminate\Support\Facades\DB::table('otp_codes')->insert(['user_id' => $user->id, 'email' => $user->email, 'code_hash' => 'synthetic-hash', 'attempts' => 0, 'expires_at' => now()->subHour(), 'created_at' => now(), 'updated_at' => now()]);
    Illuminate\Support\Facades\Bus::dispatch(new App\Jobs\PruneExpiredOtpCodes);
    if (Illuminate\Support\Facades\DB::table('jobs')->count() !== 1) {
        throw new RuntimeException('Job was not persisted in the real database queue.');
    }
} else {
    foreach (['jobs', 'failed_jobs', 'otp_codes'] as $table) {
        if (Illuminate\Support\Facades\DB::table($table)->count() !== 0) {
            throw new RuntimeException("Drill failed: $table not empty.");
        }
    }
}
echo "Database queue drill OK\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage()."\n");
    exit(1);
}
