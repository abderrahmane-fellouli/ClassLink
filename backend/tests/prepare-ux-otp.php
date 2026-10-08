<?php

// Inject a synthetic verification credential only into the isolated browser audit DB.
// The normal OTP request/verify APIs still run; no real email or credential is used.
use App\Models\OtpCode;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$database = (string) config('database.connections.sqlite.database');
if (PHP_SAPI !== 'cli' || ! $app->environment('testing') || config('database.default') !== 'sqlite'
    || ! str_contains($database, '.school-pilot-ux-') || ! str_ends_with($database, 'pilot.sqlite')) {
    throw new RuntimeException('Synthetic OTP setup requires the disposable local UX database.');
}
$input = json_decode(file_get_contents('php://stdin'), true, flags: JSON_THROW_ON_ERROR);
if (! str_starts_with($input['email'] ?? '', 'synthetic.pilot.') || ! preg_match('/^\d{6}$/D', $input['code'] ?? '')) {
    throw new RuntimeException('Only synthetic audit identities and credentials are allowed.');
}
$record = OtpCode::where('email', $input['email'])->whereNull('consumed_at')->latest('id')->firstOrFail();
$record->update(['code_hash' => OtpCode::hash($input['code']), 'expires_at' => now()->addMinutes(10), 'attempts' => 0]);
echo "Synthetic OTP fixture prepared; credential not printed.\n";
