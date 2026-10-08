<?php

// CLI-only expiry of a synthetic audit session. Never a production recovery command.
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$database = (string) config('database.connections.sqlite.database');
if (PHP_SAPI !== 'cli' || ! $app->environment('testing') || config('database.default') !== 'sqlite'
    || ! str_contains($database, '.school-pilot-ux-') || ! str_ends_with($database, 'pilot.sqlite')) {
    throw new RuntimeException('Session expiry fixture requires the disposable UX database.');
}
$input = json_decode(file_get_contents('php://stdin'), true, flags: JSON_THROW_ON_ERROR);
$user = User::findOrFail($input['user_id'] ?? 0);
if (! str_starts_with($user->email, 'synthetic.pilot.') || ! is_int($input['token_id'] ?? null)) {
    throw new RuntimeException('Only synthetic audit sessions may be expired.');
}
$updated = DB::table('personal_access_tokens')->where('id', $input['token_id'])->where('tokenable_id', $user->id)
    ->update(['expires_at' => now()->subMinute()]);
if ($updated !== 1) {
    throw new RuntimeException('Synthetic session was not found.');
}
echo "Synthetic session expired; no credential printed.\n";
