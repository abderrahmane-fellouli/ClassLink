<?php

/**
 * Local-only B1 verification. Boots with NO .env file and explicit SQLite paths.
 * Reads the original SQLite DB in read-only mode; writes only ignored artifacts.
 * Output contains table counts/fingerprints, never account/grade/message values.
 * Run from repository root: php scripts/verify-workspace-foundation.php
 */

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

$root = dirname(__DIR__);
$source = $root.'/backend/database/database.sqlite';
$parent = $root.'/artifacts';
if (! is_dir($parent) || ! is_file($source)) {
    throw new RuntimeException('Existing artifacts directory and local SQLite source are required.');
}
$output = $parent.'/workspace-foundation-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3));
if (! mkdir($output, 0700)) {
    throw new RuntimeException('Cannot create isolated verification directory.');
}

function openSqlite(string $path, bool $readOnly = false): SQLite3
{
    $db = new SQLite3($path, $readOnly ? SQLITE3_OPEN_READONLY : SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
    $db->enableExceptions(true);
    $db->busyTimeout(5000);

    return $db;
}

/** Full table contents are hashed in memory, not written to logs/report. */
function fingerprint(SQLite3 $db, ?array $tables = null): array
{
    $names = $db->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' AND name <> 'migrations' ORDER BY name");
    $result = [];
    while ($row = $names->fetchArray(SQLITE3_ASSOC)) {
        $table = $row['name'];
        if ($tables !== null && ! in_array($table, $tables, true)) {
            continue;
        }
        $quoted = '"'.str_replace('"', '""', $table).'"';
        $hash = hash_init('sha256');
        $rows = $db->query("SELECT * FROM $quoted ORDER BY rowid");
        $count = 0;
        while ($record = $rows->fetchArray(SQLITE3_ASSOC)) {
            hash_update($hash, serialize($record));
            $count++;
        }
        $schema = $db->query('SELECT type, name, sql FROM sqlite_master WHERE tbl_name = '."'".SQLite3::escapeString($table)."' ORDER BY type, name");
        while ($definition = $schema->fetchArray(SQLITE3_ASSOC)) {
            hash_update($hash, serialize($definition));
        }
        $result[$table] = ['rows' => $count, 'sha256' => hash_final($hash)];
    }

    return $result;
}

function ensure(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

$sourceDb = openSqlite($source, true);
$sourceBefore = fingerprint($sourceDb);
$copy = $output.'/existing-copy.sqlite';
$copyDb = openSqlite($copy);
ensure($sourceDb->backup($copyDb), 'SQLite read-only backup failed.');
$copyDb->close();
$sourceDb->close();
$fresh = $output.'/fresh.sqlite';
openSqlite($fresh)->close();

// Do not inherit a remote URL, cached production config or .env values.
foreach ([
    'APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $fresh,
    'DB_URL' => '', 'DATABASE_URL' => '', 'CACHE_STORE' => 'array',
    'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array', 'LOG_CHANNEL' => 'null',
    'APP_CONFIG_CACHE' => $output.'/nonexistent-config.php',
] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
require $root.'/backend/vendor/autoload.php';
$app = require $root.'/backend/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.workspace-verification-does-not-exist');
$app->make(Kernel::class)->bootstrap();

$migration = '2026_10_17_000001_create_workspace_foundation';
$relativePath = 'database/migrations/'.$migration.'.php';
$legacyPaths = array_values(array_filter(glob($root.'/backend/database/migrations/*.php'), fn ($file) => basename($file, '.php') !== $migration));
$foundationTables = ['workspaces', 'workspace_memberships', 'workspace_managers', 'workspace_configurations', 'workspace_capabilities', 'workspace_onboardings'];
$report = ['completed' => false, 'databaseEngine' => 'sqlite', 'productionAccess' => false, 'checks' => []];

foreach (['fresh' => $fresh, 'existingCopy' => $copy] as $label => $database) {
    DB::purge('sqlite');
    config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $database, 'database.connections.sqlite.url' => null, 'database.connections.sqlite.foreign_key_constraints' => true]);
    ensure(DB::connection()->getDriverName() === 'sqlite' && DB::connection()->getDatabaseName() === $database, 'Unexpected database target.');
    if ($label === 'fresh') {
        ensure(Artisan::call('migrate:install') === 0, 'Fresh migration repository failed.');
        app('migrator')->run($legacyPaths, ['step' => false]);
    } else {
        $recorded = DB::table('migrations')->pluck('migration')->all();
        foreach ($legacyPaths as $file) {
            ensure(in_array(basename($file, '.php'), $recorded, true), 'Local copy has pending legacy migrations; stop rather than change legacy schema.');
        }
    }
    $raw = openSqlite($database, true);
    $before = fingerprint($raw);
    $raw->close();
    ensure(! array_intersect(array_keys($before), $foundationTables), 'Target already has workspace schema.');
    $oldMigrationRows = DB::table('migrations')->orderBy('id')->get()->toJson();
    ensure(Artisan::call('migrate', ['--path' => [$relativePath], '--force' => true]) === 0, 'Foundation migration failed.');
    foreach ($foundationTables as $table) {
        ensure(DB::table($table)->count() === 0, 'Foundation must not seed/backfill data.');
    }
    $raw = openSqlite($database, true);
    $after = fingerprint($raw, array_keys($before));
    $foreignKeys = $raw->querySingle('SELECT COUNT(*) FROM pragma_foreign_key_check');
    $raw->close();
    ensure($before === $after, 'Existing rows/IDs/schema changed during foundation migration.');
    ensure($foreignKeys === 0, 'Foreign-key integrity failed after migration.');
    ensure(Artisan::call('migrate:rollback', ['--path' => [$relativePath], '--step' => 1, '--force' => true]) === 0, 'Empty foundation rollback failed.');
    $raw = openSqlite($database, true);
    $rolledBack = fingerprint($raw);
    $raw->close();
    ensure($before === $rolledBack, 'Rollback did not restore the exact legacy table rows and schema.');
    ensure($oldMigrationRows === DB::table('migrations')->orderBy('id')->get()->toJson(), 'Rollback changed preexisting migration rows/IDs.');
    ensure(Artisan::call('migrate', ['--path' => [$relativePath], '--force' => true]) === 0, 'Foundation reapply failed.');
    $raw = openSqlite($database, true);
    ensure($before === fingerprint($raw, array_keys($before)), 'Reapply changed legacy rows/IDs/schema.');
    $raw->close();
    $report['checks'][$label] = ['legacyTables' => count($before), 'legacyRows' => array_sum(array_column($before, 'rows')), 'tablesCreated' => count($foundationTables), 'migration' => 'passed', 'emptyRollback' => 'passed', 'reapply' => 'passed', 'legacyRowsIdsAndSchemaUnchanged' => true, 'foreignKeyViolations' => $foreignKeys];
}
DB::disconnect('sqlite');
$sourceDb = openSqlite($source, true);
ensure($sourceBefore === fingerprint($sourceDb), 'Original local source changed during verification.');
$sourceDb->close();
$report['sourceUnchanged'] = true;
$report['completed'] = true;
file_put_contents($output.'/report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
echo 'Private verification copies/report: '.str_replace($root.'/', '', $output).PHP_EOL;
