<?php
// Synthetic CI queue job using an existing maintenance job; no application edits.
require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try {
    if ($app->environment('production')) {
        throw new RuntimeException('Queue probe is only for disposable CI/local databases.');
    }
    $user = App\Models\User::create(['email' => 'queue-probe@example.invalid', 'display_name' => 'Queue Probe', 'role' => 'student', 'locale' => 'en', 'is_active' => true]);
    Illuminate\Support\Facades\DB::table('otp_codes')->insert(['user_id' => $user->id, 'email' => $user->email, 'code_hash' => 'synthetic-hash', 'attempts' => 0, 'expires_at' => now()->subHour(), 'created_at' => now(), 'updated_at' => now()]);
    Illuminate\Support\Facades\Bus::dispatch(new App\Jobs\PruneExpiredOtpCodes);
    for ($i = 0; $i < 15; $i++) {
        if (!Illuminate\Support\Facades\DB::table('otp_codes')->where('user_id', $user->id)->exists()) {
            $user->delete();
            echo "Independent queue worker consumed and pruned the synthetic OTP.\n";
            exit(0);
        }
        sleep(1);
    }
    throw new RuntimeException('Worker did not consume the synthetic maintenance job.');
} catch (Throwable $e) {
    fwrite(STDERR, "Queue probe failed.\n");
    exit(1);
}
