<?php

use App\Models\User;
use App\Services\TokenService;
use Illuminate\Contracts\Console\Kernel;

// Local verification harness only. Never call this against an existing school DB.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$database = (string) config('database.connections.sqlite.database');
if (! $app->environment('testing') || config('database.default') !== 'sqlite'
    || ! str_contains($database, '.school-pilot-') || ! str_ends_with($database, 'pilot.sqlite')) {
    throw new RuntimeException('Synthetic pilot fixtures require the isolated local pilot database.');
}
$roles = ['admin', 'teacher1', 'teacher2', 'student1', 'student2'];
$result = [];
foreach ($roles as $label) {
    $role = str_starts_with($label, 'teacher') ? 'teacher' : (str_starts_with($label, 'student') ? 'student' : 'admin');
    $user = new User;
    $user->forceFill(['email' => 'synthetic.pilot.'.$label.'@ofppt-edu.ma', 'display_name' => 'Synthetic '.$label,
        'role' => $role, 'role_locked' => true, 'is_active' => true, 'locale' => 'en'])->save();
    $result[$label] = ['id' => $user->id, 'email' => $user->email, 'display_name' => $user->display_name,
        'token' => app(TokenService::class)->issue($user, 'synthetic-local-pilot')];
}
file_put_contents($argv[1], json_encode($result, JSON_THROW_ON_ERROR));
chmod($argv[1], 0600);
echo "Synthetic local pilot actors prepared; no email sent.\n";
