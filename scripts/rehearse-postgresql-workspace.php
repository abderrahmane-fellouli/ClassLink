<?php

/**
 * B1.1: isolated PostgreSQL 16 rehearsal, never a production/backfill executor.
 * Requires a NEW local cluster named classlink-workspace-b11-pg on 127.0.0.1:15439,
 * role classlink_rehearsal, and empty classlink_b11_fresh / classlink_b11_legacy DBs.
 * Reconstructs native legacy schema, copies read-only LOCAL SQLite rows, then clones
 * that PostgreSQL database before testing B1. No .env is loaded. Private evidence
 * stays in ignored artifacts; stdout has counts/flags only, never record contents.
 */

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

$root = dirname(__DIR__);
if (! is_dir($root.'/artifacts') || ! is_file($root.'/backend/database/database.sqlite')) {
    throw new RuntimeException('Existing artifacts parent and local SQLite source required.');
}
$output = $root.'/artifacts/workspace-postgresql-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3));
mkdir($output, 0700);
$migration = '2026_10_17_000001_create_workspace_foundation';
$foundation = ['workspaces', 'workspace_memberships', 'workspace_managers', 'workspace_configurations', 'workspace_capabilities', 'workspace_onboardings'];
$legacyPaths = array_values(array_filter(glob($root.'/backend/database/migrations/*.php'), fn ($p) => basename($p, '.php') < $migration));
$stage = 'safety';

function expectRehearsal(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function quoteIdentifier(string $value): string
{
    return '"'.str_replace('"', '""', $value).'"';
}

function pgConnection(string $database): PDO
{
    expectRehearsal(in_array($database, ['postgres', 'classlink_b11_fresh', 'classlink_b11_legacy', 'classlink_b11_copy'], true), 'Unapproved database name.');
    $pdo = new PDO('pgsql:host=127.0.0.1;port=15439;dbname='.$database.';sslmode=disable;connect_timeout=5', 'classlink_rehearsal', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $identity = $pdo->query("SELECT host(inet_server_addr()) AS host, inet_server_port() AS port, current_user AS actor, current_setting('data_directory') AS directory")->fetch(PDO::FETCH_ASSOC);
    expectRehearsal($identity['host'] === '127.0.0.1' && (int) $identity['port'] === 15439 && $identity['actor'] === 'classlink_rehearsal', 'Refuse non-rehearsal PostgreSQL endpoint.');
    expectRehearsal(basename(str_replace('\\', '/', $identity['directory'])) === 'classlink-workspace-b11-pg', 'Refuse another PostgreSQL cluster.');

    return $pdo;
}

function pgTables(PDO $db): array
{
    return $db->query("SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename <> 'migrations' ORDER BY tablename")->fetchAll(PDO::FETCH_COLUMN);
}

function pgSnapshot(PDO $db, ?array $tables = null): array
{
    $result = [];
    foreach ($tables ?? pgTables($db) as $table) {
        $quoted = quoteIdentifier($table);
        $hash = hash_init('sha256');
        $rows = $db->query("SELECT to_jsonb(t)::text FROM public.$quoted t ORDER BY to_jsonb(t)::text");
        $count = 0;
        while (($row = $rows->fetchColumn()) !== false) {
            hash_update($hash, $row."\n");
            $count++;
        }
        $schema = [];
        foreach ([
            "SELECT a.attname, format_type(a.atttypid,a.atttypmod) AS type, a.attnotnull, pg_get_expr(d.adbin,d.adrelid) AS default_value FROM pg_attribute a LEFT JOIN pg_attrdef d ON d.adrelid=a.attrelid AND d.adnum=a.attnum WHERE a.attrelid='public.$quoted'::regclass AND a.attnum>0 AND NOT a.attisdropped ORDER BY a.attnum",
            "SELECT conname, pg_get_constraintdef(oid,true) AS definition FROM pg_constraint WHERE conrelid='public.$quoted'::regclass ORDER BY conname",
            "SELECT indexname, indexdef FROM pg_indexes WHERE schemaname='public' AND tablename=".$db->quote($table).' ORDER BY indexname',
            "SELECT tgname, pg_get_triggerdef(oid,true) AS definition FROM pg_trigger WHERE tgrelid='public.$quoted'::regclass AND NOT tgisinternal ORDER BY tgname",
        ] as $query) {
            $schema[] = $db->query($query)->fetchAll(PDO::FETCH_ASSOC);
        }
        $result[$table] = ['rows' => $count, 'rowHash' => hash_final($hash), 'schemaHash' => hash('sha256', serialize($schema))];
    }

    return $result;
}

function sqliteSnapshot(SQLite3 $db): array
{
    $result = [];
    $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' AND name<>'migrations' ORDER BY name");
    while ($table = $tables->fetchArray(SQLITE3_ASSOC)) {
        $name = $table['name'];
        $rows = $db->query('SELECT * FROM '.quoteIdentifier($name).' ORDER BY rowid');
        $hash = hash_init('sha256');
        $count = 0;
        while ($row = $rows->fetchArray(SQLITE3_ASSOC)) {
            hash_update($hash, serialize($row));
            $count++;
        }
        $result[$name] = ['rows' => $count, 'hash' => hash_final($hash)];
    }

    return $result;
}

/** Read all native FK columns, including composite constraints, for ordering/validation. */
function foreignKeys(PDO $db): array
{
    return $db->query("SELECT c.conname, c.conrelid::regclass::text AS child, c.confrelid::regclass::text AS parent, string_agg(quote_ident(a.attname), ',' ORDER BY k.ord) AS child_columns, string_agg(quote_ident(b.attname), ',' ORDER BY k.ord) AS parent_columns FROM pg_constraint c CROSS JOIN LATERAL unnest(c.conkey,c.confkey) WITH ORDINALITY k(child_att,parent_att,ord) JOIN pg_attribute a ON a.attrelid=c.conrelid AND a.attnum=k.child_att JOIN pg_attribute b ON b.attrelid=c.confrelid AND b.attnum=k.parent_att WHERE c.contype='f' AND c.connamespace='public'::regnamespace GROUP BY c.oid ORDER BY c.conname")->fetchAll(PDO::FETCH_ASSOC);
}

function validateForeignKeys(PDO $db): int
{
    $checks = 0;
    foreach (foreignKeys($db) as $fk) {
        $left = explode(',', $fk['child_columns']);
        $right = explode(',', $fk['parent_columns']);
        $nonnull = [];
        $match = [];
        foreach ($left as $i => $column) {
            $nonnull[] = 'c.'.$column.' IS NOT NULL';
            $match[] = 'p.'.$right[$i].'=c.'.$column;
        }
        $sql = 'SELECT count(*) FROM '.$fk['child'].' c WHERE '.implode(' AND ', $nonnull).' AND NOT EXISTS (SELECT 1 FROM '.$fk['parent'].' p WHERE '.implode(' AND ', $match).')';
        expectRehearsal((int) $db->query($sql)->fetchColumn() === 0, 'Foreign-key violation: '.$fk['conname']);
        $checks++;
    }

    return $checks;
}

try {
    $admin = pgConnection('postgres');
    expectRehearsal(! $admin->query("SELECT 1 FROM pg_database WHERE datname='classlink_b11_copy'")->fetchColumn(), 'Refuse to overwrite an existing native copy.');
    $version = $admin->query('SELECT version()')->fetchColumn();
    foreach (['classlink_b11_fresh', 'classlink_b11_legacy'] as $name) {
        $check = pgConnection($name);
        expectRehearsal($check->query("SELECT count(*) FROM pg_tables WHERE schemaname='public'")->fetchColumn() === 0, 'Rehearsal targets must start empty.');
        $check = null;
    }
    $local = new SQLite3($root.'/backend/database/database.sqlite', SQLITE3_OPEN_READONLY);
    $local->enableExceptions(true);
    $sourceBefore = sqliteSnapshot($local);
    expectRehearsal(! array_intersect(array_keys($sourceBefore), $foundation), 'Local source already contains workspace tables.');
    $adminEvidence = [];
    $admins = $local->query("SELECT id, email, display_name, role, role_locked, is_active, microsoft_verified_at FROM users WHERE role='admin' ORDER BY id");
    while ($row = $admins->fetchArray(SQLITE3_ASSOC)) {
        $adminEvidence[] = ['localUserId' => $row['id'], 'globalRole' => $row['role'], 'active' => (bool) $row['is_active'], 'roleLocked' => (bool) $row['role_locked'], 'microsoftVerified' => $row['microsoft_verified_at'] !== null, 'matchesDemoSeederIdentity' => strtolower($row['email']) === 'admin.classlink@ofppt-edu.ma'];
    }

    foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '15439', 'DB_DATABASE' => 'classlink_b11_fresh', 'DB_USERNAME' => 'classlink_rehearsal', 'DB_PASSWORD' => '', 'DB_SSLMODE' => 'disable', 'DB_URL' => '', 'DATABASE_URL' => '', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array', 'LOG_CHANNEL' => 'null', 'APP_CONFIG_CACHE' => $output.'/nonexistent-config.php'] as $key => $value) {
        putenv($key.'='.$value);
        $_ENV[$key] = $_SERVER[$key] = $value;
    }
    require $root.'/backend/vendor/autoload.php';
    $app = require $root.'/backend/bootstrap/app.php';
    $app->loadEnvironmentFrom('.env.workspace-rehearsal-does-not-exist');
    $app->make(Kernel::class)->bootstrap();
    $selectDatabase = function (string $name): void {
        DB::purge('pgsql');
        config(['database.default' => 'pgsql', 'database.connections.pgsql.database' => $name, 'database.connections.pgsql.url' => null]);
        expectRehearsal(DB::connection()->getDatabaseName() === $name && DB::connection()->getDriverName() === 'pgsql', 'Unexpected Laravel target.');
    };
    foreach (['classlink_b11_fresh', 'classlink_b11_legacy'] as $name) {
        $stage = 'legacy-schema:'.$name;
        $selectDatabase($name);
        expectRehearsal(Artisan::call('migrate:install') === 0, 'Migration repository failed.');
        app('migrator')->run($legacyPaths, ['step' => false]);
    }

    $stage = 'native-local-copy';
    $native = pgConnection('classlink_b11_legacy');
    $tables = pgTables($native);
    expectRehearsal($tables === array_keys($sourceBefore), 'SQLite/native legacy table inventory differs.');
    $remaining = $tables;
    $order = [];
    $fks = foreignKeys($native);
    while ($remaining) {
        $progress = false;
        foreach ($remaining as $index => $table) {
            $blocked = false;
            foreach ($fks as $fk) {
                if ($fk['child'] !== $table || ($table === 'official_assessments' && str_contains($fk['child_columns'], 'revision_id'))) {
                    continue;
                }
                if (in_array($fk['parent'], $remaining, true)) {
                    $blocked = true;
                }
            }
            if (! $blocked) {
                $order[] = $table;
                unset($remaining[$index]);
                $progress = true;
            }
        }
        expectRehearsal($progress, 'Unexpected legacy FK cycle: review rather than disable constraints.');
    }
    $native->beginTransaction();
    // Target is a newly migrated, isolated DB; remove migration-created singleton defaults.
    $native->exec('TRUNCATE '.implode(',', array_map(quoteIdentifier(...), $tables)).' RESTART IDENTITY');
    $deferredPointers = [];
    foreach ($order as $table) {
        $stage = 'copy-table:'.$table;
        $types = $native->query("SELECT column_name,data_type FROM information_schema.columns WHERE table_schema='public' AND table_name=".$native->quote($table))->fetchAll(PDO::FETCH_KEY_PAIR);
        $rows = $local->query('SELECT * FROM '.quoteIdentifier($table).' ORDER BY rowid');
        while ($row = $rows->fetchArray(SQLITE3_ASSOC)) {
            foreach ($row as $column => &$value) {
                expectRehearsal(isset($types[$column]), 'Column mismatch during local-copy reconstruction.');
                if ($types[$column] === 'boolean' && $value !== null) {
                    $value = $value ? 'true' : 'false';
                }
            }
            unset($value);
            if ($table === 'official_assessments') {
                $deferredPointers[] = [$row['draft_revision_id'], $row['published_revision_id'], $row['id']];
                $row['draft_revision_id'] = $row['published_revision_id'] = null;
            }
            $insert = $native->prepare('INSERT INTO '.quoteIdentifier($table).' ('.implode(',', array_map(quoteIdentifier(...), array_keys($row))).') VALUES ('.implode(',', array_fill(0, count($row), '?')).')');
            $insert->execute(array_values($row));
        }
        $sequence = isset($types['id']) ? $native->query('SELECT pg_get_serial_sequence('.$native->quote($table).", 'id')")->fetchColumn() : null;
        if ($sequence) {
            $native->exec('SELECT setval('.$native->quote($sequence).', COALESCE((SELECT max(id) FROM '.quoteIdentifier($table).'),1), EXISTS(SELECT 1 FROM '.quoteIdentifier($table).'))');
        }
    }
    foreach ($deferredPointers as $pointer) {
        $native->prepare('UPDATE official_assessments SET draft_revision_id=?,published_revision_id=? WHERE id=?')->execute($pointer);
    }
    $native->commit();
    foreach ($tables as $table) {
        expectRehearsal((int) $native->query('SELECT count(*) FROM '.quoteIdentifier($table))->fetchColumn() === $sourceBefore[$table]['rows'], 'Copied row count mismatch.');
        $sourceIds = [];
        $sourceRows = $local->query('SELECT * FROM '.quoteIdentifier($table).' ORDER BY rowid');
        $targetRows = $native->query('SELECT * FROM '.quoteIdentifier($table))->fetchAll(PDO::FETCH_ASSOC);
        // Compare source/target primary identities where present; full native contents are
        // fingerprinted before/after B1, without conflating SQLite/Pg type representations.
        while ($row = $sourceRows->fetchArray(SQLITE3_ASSOC)) {
            if (array_key_exists('id', $row)) {
                $sourceIds[] = (string) $row['id'];
            }
        }
        $targetIds = array_map('strval', array_column($targetRows, 'id'));
        sort($sourceIds);
        sort($targetIds);
        expectRehearsal($sourceIds === $targetIds, 'Copied primary IDs differ.');
    }
    $sourceRoles = [];
    $roles = $local->query('SELECT id,role FROM users ORDER BY id');
    while ($row = $roles->fetchArray(SQLITE3_ASSOC)) {
        $sourceRoles[(string) $row['id']] = $row['role'];
    }
    expectRehearsal($sourceRoles === $native->query('SELECT id,role FROM users ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR), 'Copied global roles differ.');
    $legacyFkCount = validateForeignKeys($native);
    $nativeBaseline = pgSnapshot($native);
    file_put_contents($output.'/native-legacy-baseline.json', json_encode($nativeBaseline, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    unset($insert);
    $native = null;
    DB::purge('pgsql');
    $stage = 'native-template-copy';
    $admin->exec('CREATE DATABASE classlink_b11_copy TEMPLATE classlink_b11_legacy');

    $report = ['completed' => false, 'postgresVersion' => $version, 'endpoint' => '127.0.0.1:15439', 'productionAccess' => false, 'copySource' => 'read-only local SQLite reconstructed natively, then PostgreSQL TEMPLATE clone', 'localAdminEvidence' => $adminEvidence, 'legacyForeignKeysValidated' => $legacyFkCount, 'checks' => []];
    foreach (['fresh' => 'classlink_b11_fresh', 'representativeNativeCopy' => 'classlink_b11_copy'] as $label => $name) {
        $stage = 'B1:'.$label;
        $selectDatabase($name);
        $db = pgConnection($name);
        $before = pgSnapshot($db);
        if ($label !== 'fresh') {
            expectRehearsal($before === $nativeBaseline, 'Native clone differs from copied legacy source.');
        }
        $oldMigrations = DB::table('migrations')->orderBy('id')->get()->toJson();
        file_put_contents($output.'/'.$label.'-before.json', json_encode($before, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        expectRehearsal(Artisan::call('migrate', ['--path' => ['database/migrations/'.$migration.'.php'], '--force' => true]) === 0, 'B1 migration failed.');
        $after = pgSnapshot($db, array_keys($before));
        expectRehearsal($before === $after, 'B1 changed legacy rows, IDs or schema.');
        $added = array_values(array_diff(pgTables($db), array_keys($before)));
        sort($added);
        $expected = $foundation;
        sort($expected);
        expectRehearsal($added === $expected, 'B1 must add exactly six workspace tables.');
        foreach ($added as $table) {
            expectRehearsal((int) $db->query('SELECT count(*) FROM '.quoteIdentifier($table))->fetchColumn() === 0, 'B1 seeded data.');
        }
        $fkCount = validateForeignKeys($db);
        file_put_contents($output.'/'.$label.'-after.json', json_encode($after, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        expectRehearsal(Artisan::call('migrate:rollback', ['--path' => ['database/migrations/'.$migration.'.php'], '--step' => 1, '--force' => true]) === 0, 'B1 rollback failed.');
        expectRehearsal($before === pgSnapshot($db), 'Rollback changed legacy rows/schema or left new tables.');
        expectRehearsal($oldMigrations === DB::table('migrations')->orderBy('id')->get()->toJson(), 'Rollback changed old migration IDs/rows.');
        expectRehearsal(Artisan::call('migrate', ['--path' => ['database/migrations/'.$migration.'.php'], '--force' => true]) === 0, 'B1 reapply failed.');
        expectRehearsal($before === pgSnapshot($db, array_keys($before)), 'Reapply changed legacy state.');
        $report['checks'][$label] = ['legacyTablesBefore' => count($before), 'tablesAfter' => count(pgTables($db)), 'legacyRows' => array_sum(array_column($before, 'rows')), 'tablesAdded' => $added, 'migrate' => 'passed', 'emptyRollback' => 'passed', 'reapply' => 'passed', 'legacyRowsIdsRolesAndSchemaUnchanged' => true, 'foreignKeysValidated' => $fkCount];
        $db = null;
    }
    expectRehearsal($sourceBefore === sqliteSnapshot($local), 'Local SQLite source changed.');
    $local->close();
    $report['localSourceUnchanged'] = true;
    $report['backfillExecuted'] = false;
    $report['completed'] = true;
    file_put_contents($output.'/report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
    echo 'Private evidence: '.str_replace($root.'/', '', $output).PHP_EOL;
} catch (Throwable $error) {
    $reason = $error::class === RuntimeException::class ? $error->getMessage() : 'Inspect isolated rehearsal failure; raw database values are not printed.';
    file_put_contents($output.'/failure.json', json_encode(['stage' => $stage, 'exception' => $error::class, 'code' => $error->getCode(), 'reason' => $reason], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    fwrite(STDERR, 'Rehearsal stopped at '.$stage.' ('.$error::class.', code '.$error->getCode().'): '.$reason.' Private evidence: '.$output.PHP_EOL);
    exit(1);
}
