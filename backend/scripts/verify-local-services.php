<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

// Read-only, secret-safe local diagnostics. Never print exceptions/config values.
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! $app->environment('local')) {
    exit("BLOCKED: this checker only runs with APP_ENV=local.\n");
}

function configured($value): bool
{
    return is_string($value) && $value !== ''
        && ! preg_match('/^(?:<[^>]+>|YOUR_VALUE|MY_CLIENT_ID|MY_NEW_ROTATED_SECRET|MY_NEW_ROTATED_AIVEN_PASSWORD)$/i', $value);
}

$missing = [];
foreach (['AZURE_CLIENT_ID' => 'services.azure.client_id', 'AZURE_CLIENT_SECRET' => 'services.azure.client_secret', 'DB_PASSWORD' => 'database.connections.pgsql.password'] as $variable => $key) {
    if (! configured(config($key))) {
        $missing[] = $variable;
    }
}
echo 'Private values still required: '.($missing ? implode(', ', $missing) : 'none')."\n";
echo 'Callback exact: '.(config('services.azure.redirect') === 'http://localhost:8000/api/auth/microsoft/callback' ? 'YES' : 'NO')."\n";
echo 'Organizational authority configured: '.(config('services.azure.tenant') === 'organizations' ? 'YES' : 'NO')."\n";

if (config('database.default') === 'pgsql' && ! config('database.connections.pgsql.url')) {
    $socket = @stream_socket_client('tcp://'.config('database.connections.pgsql.host').':'.config('database.connections.pgsql.port'), $errorCode, $errorMessage, 5);
    echo 'Aiven TCP endpoint: '.($socket ? 'REACHABLE' : 'BLOCKED (network/DNS check failed)')."\n";
    if ($socket) {
        fclose($socket);
    }
}

if (in_array('AZURE_CLIENT_ID', $missing) || in_array('AZURE_CLIENT_SECRET', $missing)) {
    echo "Microsoft authorization URL: BLOCKED (private values missing)\n";
} else {
    try {
        $state = Str::random(64);
        $redirect = Socialite::driver('azure')->stateless()->with(['state' => $state])->redirect();
        $url = $redirect->getTargetUrl();
        parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $query);
        $valid = parse_url($url, PHP_URL_HOST) === 'login.microsoftonline.com'
            && parse_url($url, PHP_URL_PATH) === '/organizations/oauth2/v2.0/authorize'
            && ($query['redirect_uri'] ?? null) === 'http://localhost:8000/api/auth/microsoft/callback'
            && ($query['state'] ?? null) === $state;
        echo 'Microsoft authorization URL: '.($valid ? 'READY' : 'BLOCKED')."\n";
    } catch (Throwable $e) {
        echo "Microsoft authorization URL: BLOCKED (generation failed; details suppressed)\n";
    }
}

if (config('database.default') !== 'pgsql' || config('database.connections.pgsql.url')) {
    echo "PostgreSQL: BLOCKED (driver mismatch or DB_URL override; values suppressed)\n";
} elseif (! extension_loaded('pdo_pgsql')) {
    echo "PostgreSQL: BLOCKED (pdo_pgsql extension missing)\n";
} elseif (in_array('DB_PASSWORD', $missing)) {
    echo "PostgreSQL: BLOCKED (DB_PASSWORD missing; no connection attempted)\n";
} else {
    try {
        $db = DB::connection('pgsql');
        $db->select('SELECT 1');
        $tls = $db->selectOne('SELECT ssl FROM pg_stat_ssl WHERE pid = pg_backend_pid()');
        echo 'PostgreSQL: '.(in_array($tls->ssl ?? null, [true, 1, '1', 't'], true) ? 'READY (read-only SELECT, TLS confirmed)' : 'BLOCKED (TLS not confirmed)')."\n";
    } catch (Throwable $e) {
        echo "PostgreSQL: BLOCKED (connection/query failed; details suppressed)\n";
    }
}
