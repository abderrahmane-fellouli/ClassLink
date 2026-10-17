<?php

/** B2 local-only four-target rehearsal. Never loads .env or targets production. */
declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMigrationRun;
use App\Services\OfpptCompatibilityBootstrap;
use App\Workspace\LegacyWorkspaceScope;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$root = dirname(__DIR__);
if (! is_dir($root.'/artifacts') || ! is_file($root.'/backend/database/database.sqlite')) {
    throw new RuntimeException('Existing artifacts parent and local SQLite source required.');
}
$output = $root.'/artifacts/ofppt-compatibility-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3));
mkdir($output, 0700);
$local = new SQLite3($root.'/backend/database/database.sqlite', SQLITE3_OPEN_READONLY);
$local->enableExceptions(true);
$sourceHash = function (SQLite3 $db): string {
    $hash = hash_init('sha256');
    $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
    while ($table = $tables->fetchArray(SQLITE3_ASSOC)) {
        $rows = $db->query('SELECT * FROM "'.str_replace('"', '""', $table['name']).'" ORDER BY rowid');
        while ($row = $rows->fetchArray(SQLITE3_ASSOC)) {
            hash_update($hash, serialize([$table['name'], $row]));
        }
    }

    return hash_final($hash);
};
$original = $sourceHash($local);
$copy = new SQLite3($output.'/copy.sqlite');
$local->backup($copy);
$copy->close();
(new SQLite3($output.'/fresh.sqlite'))->close();

// Explicitly attest the pre-created isolated PostgreSQL targets before booting Laravel.
foreach (['classlink_b2_fresh_test', 'classlink_b2_copy_test'] as $name) {
    $pg = new PDO('pgsql:host=127.0.0.1;port=15439;dbname='.$name.';sslmode=disable;connect_timeout=5', 'classlink_rehearsal', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $identity = $pg->query("SELECT host(inet_server_addr()) AS host, inet_server_port() AS port, current_user AS actor, current_setting('data_directory') AS directory")->fetch(PDO::FETCH_ASSOC);
    if ($identity['host'] !== '127.0.0.1' || (int) $identity['port'] !== 15439 || $identity['actor'] !== 'classlink_rehearsal' || basename(str_replace('\\', '/', $identity['directory'])) !== 'classlink-workspace-b11-pg') {
        throw new RuntimeException('Refuse any non-rehearsal PostgreSQL target.');
    }
    $pg = null;
}
foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $output.'/fresh.sqlite', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '15439', 'DB_USERNAME' => 'classlink_rehearsal', 'DB_PASSWORD' => '', 'DB_URL' => '', 'DATABASE_URL' => '', 'DB_SSLMODE' => 'disable', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array', 'LOG_CHANNEL' => 'null', 'APP_CONFIG_CACHE' => $output.'/nonexistent-config.php'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
require $root.'/backend/vendor/autoload.php';
$app = require $root.'/backend/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.ofppt-rehearsal-does-not-exist');
$app->make(Kernel::class)->bootstrap();
$migration = '2026_10_17_000002_add_compatibility_workspace_scope';
$relative = 'database/migrations/'.$migration.'.php';
$baseline = array_values(array_filter(glob($root.'/backend/database/migrations/*.php'), fn ($file) => basename($file, '.php') < $migration));

function verifyB2(bool $condition, string $code): void
{
    if (! $condition) {
        throw new RuntimeException($code);
    }
}

function rowsB2(array $tables): array
{
    $result = [];
    foreach ($tables as $table) {
        $records = [];
        foreach (DB::table($table)->get() as $row) {
            unset($row->workspace_id);
            $attributes = (array) $row;
            ksort($attributes);
            $records[] = json_encode($attributes, JSON_THROW_ON_ERROR);
        }
        sort($records);
        $result[$table] = ['rows' => count($records), 'hash' => hash('sha256', serialize($records))];
    }

    return $result;
}

$targets = [
    'freshSQLite' => ['sqlite', $output.'/fresh.sqlite'],
    'populatedSQLiteCopy' => ['sqlite', $output.'/copy.sqlite'],
    'freshPostgreSQL' => ['pgsql', 'classlink_b2_fresh_test'],
    'populatedPostgreSQLCopy' => ['pgsql', 'classlink_b2_copy_test'],
];
$report = ['completed' => false, 'productionAccess' => false, 'originalLocalDatabaseModified' => false, 'targets' => []];
foreach ($targets as $label => [$driver, $database]) {
    DB::purge('sqlite');
    DB::purge('pgsql');
    Schema::clearResolvedInstance('db.schema');
    config(['database.default' => $driver, 'database.connections.'.$driver.'.database' => $database, 'database.connections.'.$driver.'.url' => null, 'workspace_compatibility.apply_enabled' => true]);
    app('migrator')->setConnection($driver);
    verifyB2(DB::getDriverName() === $driver && DB::connection()->getDatabaseName() === $database, 'unexpected_target');
    if (! Schema::hasTable('migrations')) {
        verifyB2(Artisan::call('migrate:install', ['--database' => $driver]) === 0, 'install_failed');
    }
    verifyB2(! Schema::hasTable('workspace_migration_runs'), 'refuse_existing_B2_target');
    app('migrator')->run($baseline, ['step' => false]);
    $admin = User::where('role', 'admin')->where('is_active', true)->where('role_locked', true)->first();
    if (! $admin) {
        verifyB2(str_starts_with($label, 'fresh'), 'populated_target_requires_explicit_local_candidate');
        $admin = User::factory()->admin()->create(['email' => 'b2.synthetic.admin@example.invalid']);
    }
    $legacyTables = array_values(array_filter(Schema::getTableListing(null, false), fn ($table) => ! str_starts_with($table, 'workspace') && $table !== 'migrations' && ! str_starts_with($table, 'sqlite_')));
    $before = rowsB2($legacyTables);
    $triggers = $driver === 'sqlite' ? DB::table('sqlite_master')->where('type', 'trigger')->where('name', 'like', 'school_%')->orderBy('name')->pluck('sql', 'name')->all() : [];
    verifyB2(Artisan::call('migrate', ['--database' => $driver, '--path' => [$relative], '--force' => true]) === 0, 'B2_migrate_failed');
    verifyB2($before === rowsB2($legacyTables), 'schema_apply_changed_legacy_rows');
    verifyB2(Artisan::call('migrate:rollback', ['--database' => $driver, '--path' => [$relative], '--step' => 1, '--force' => true]) === 0, 'empty_rollback_failed');
    $rollbackRows = rowsB2($legacyTables);
    if ($before !== $rollbackRows) {
        file_put_contents($output.'/'.$label.'-rollback-difference.json', json_encode(['before' => $before, 'after' => $rollbackRows], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        $different = array_keys(array_filter($before, fn ($snapshot, $table) => $snapshot !== $rollbackRows[$table], ARRAY_FILTER_USE_BOTH));
        throw new RuntimeException('rollback_changed_legacy_rows:'.implode(',', $different).'; private evidence: '.$output);
    }
    if ($driver === 'sqlite') {
        verifyB2($triggers === DB::table('sqlite_master')->where('type', 'trigger')->where('name', 'like', 'school_%')->orderBy('name')->pluck('sql', 'name')->all(), 'rollback_lost_institutional_triggers');
    }
    verifyB2(Artisan::call('migrate', ['--database' => $driver, '--path' => [$relative], '--force' => true]) === 0, 'B2_reapply_failed');
    $bootstrap = app(OfpptCompatibilityBootstrap::class);
    $identity = ['user_id' => $admin->id, 'identity_sha256' => hash('sha256', mb_strtolower(trim($admin->email)))];
    $manifest = ['operation' => 'ofppt-compatibility', 'version' => 1, 'environment' => 'testing', 'target_hash' => $bootstrap->targetHash(), 'approval_reference' => 'B2-local-rehearsal-only', 'actor' => $identity, 'managers' => [$identity], 'owner' => null, 'allow_demo_managers' => true];
    file_put_contents($output.'/'.$label.'-private-manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    $dry = $bootstrap->dryRun($manifest);
    verifyB2($dry['conflict_count'] === 0 && Workspace::count() === 0, 'dry_run_changed_domain_or_found_conflicts');
    $failing = new class extends OfpptCompatibilityBootstrap
    {
        protected function afterStage(string $stage): void
        {
            if ($stage === 'root:classrooms') {
                throw new RuntimeException('synthetic_halfway_failure');
            }
        }
    };
    try {
        $failing->apply($manifest, $dry['manifest_hash'], $dry['target_hash'], $dry['inventory_hash'], true);
        throw new RuntimeException('fault_not_reached');
    } catch (RuntimeException $error) {
        verifyB2($error->getMessage() === 'synthetic_halfway_failure', 'unexpected_apply_failure');
    }
    $run = WorkspaceMigrationRun::where('mode', 'apply')->sole();
    verifyB2($run->state === 'failed' && isset($run->checkpoints['root:classrooms']), 'failure_checkpoint_missing');
    $retry = $bootstrap->apply($manifest, $dry['manifest_hash'], $dry['target_hash'], $dry['inventory_hash'], true);
    verifyB2($retry['conflict_count'] === 0 && $run->fresh()->state === 'completed', 'retry_failed');
    $metadataTables = ['workspaces', 'workspace_memberships', 'workspace_managers', 'workspace_configurations', 'workspace_capabilities', 'workspace_onboardings'];
    // Metadata comparison must retain workspace IDs, unlike legacy-content comparison.
    $metadata = [];
    foreach ($metadataTables as $table) {
        $metadata[$table] = DB::table($table)->orderBy('id')->get()->toJson();
    }
    $again = $bootstrap->apply($manifest, $retry['manifest_hash'], $retry['target_hash'], $retry['inventory_hash'], true);
    verifyB2($again['no_op'], 'rerun_not_noop');
    foreach ($metadataTables as $table) {
        verifyB2($metadata[$table] === DB::table($table)->orderBy('id')->get()->toJson(), 'rerun_changed_metadata');
    }
    $workspace = Workspace::sole();
    verifyB2($workspace->owner_membership_id === null && $workspace->status->value === 'active', 'unexpected_owner_or_state');
    verifyB2($workspace->capabilities()->where('enabled', true)->count() === 22 && $workspace->capabilities()->where('enabled', false)->count() === 4, 'capability_snapshot_wrong');
    verifyB2($workspace->memberships()->count() === DB::table('users')->count() && DB::table('workspace_managers')->count() === 1, 'authority_count_wrong');
    foreach (LegacyWorkspaceScope::ROOTS as $table) {
        verifyB2(! DB::table($table)->whereNull('workspace_id')->orWhere('workspace_id', '!=', $workspace->id)->exists(), 'orphan_root_scope');
    }
    verifyB2($before === rowsB2($legacyTables), 'bootstrap_changed_legacy_content_or_auth');
    if ($driver === 'sqlite') {
        verifyB2(! DB::select('PRAGMA foreign_key_check'), 'foreign_key_violation');
    }
    file_put_contents($output.'/'.$label.'-before-after.json', json_encode(['before' => $before, 'after' => rowsB2($legacyTables)], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    $report['targets'][$label] = ['legacyTables' => count($legacyTables), 'legacyRows' => array_sum(array_column($before, 'rows')), 'nullableRootColumns' => 11, 'emptyRollbackReapply' => 'passed', 'dryRun' => 'passed', 'injectedFailureAndRetry' => 'passed', 'applyAttempts' => $run->fresh()->attempts, 'rerun' => 'verified_noop', 'compatibilityWorkspaces' => 1, 'enabledCapabilities' => 22, 'futureDisabledCapabilities' => 4, 'managerGrants' => 1, 'owner' => null, 'legacyRowsIdsAuthAndPrivateContentUnchanged' => true, 'unscopedRoots' => 0];
}
verifyB2($sourceHash($local) === $original, 'original_local_source_changed');
$local->close();
DB::disconnect();
$report['completed'] = true;
file_put_contents($output.'/report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
echo 'Private evidence: '.$output.PHP_EOL;
