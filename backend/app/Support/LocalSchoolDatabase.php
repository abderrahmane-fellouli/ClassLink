<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class LocalSchoolDatabase
{
    public static function configure(string $file): string
    {
        if (PHP_SAPI !== 'cli' || ! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('This operation is CLI-only and cannot run in production.');
        }
        $path = realpath($file);
        if (! $path || ! is_file($path) || str_starts_with($path, '\\\\') || str_starts_with($path, '//')) {
            throw new RuntimeException('An existing local SQLite file is required.');
        }
        $header = file_get_contents($path, false, null, 0, 16);
        if ($header !== "SQLite format 3\0") {
            throw new RuntimeException('The selected file is not an existing SQLite database.');
        }
        // Explicit configuration defeats ignored .env DB_URL and cached remote defaults.
        config(['database.default' => 'sqlite', 'database.connections.sqlite.url' => null,
            'database.connections.sqlite.database' => $path, 'database.connections.sqlite.foreign_key_constraints' => true,
            'mail.default' => 'array', 'queue.default' => 'sync', 'cache.default' => 'array']);
        DB::purge('sqlite');
        $connection = DB::connection('sqlite');
        $actual = $connection->select('PRAGMA database_list')[0]->file ?? '';
        if (realpath($actual) !== $path) {
            throw new RuntimeException('SQLite connection does not match the explicitly selected local file.');
        }

        return $path;
    }

    public static function backup(string $directory): string
    {
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Cannot create local backup directory.');
        }
        $path = realpath($directory).DIRECTORY_SEPARATOR.'classlink-before-import-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3)).'.sqlite';
        // SQLite's own snapshot includes committed WAL content; never copy a live DB blindly.
        DB::connection('sqlite')->unprepared("VACUUM INTO '".str_replace("'", "''", $path)."'");
        chmod($path, 0600);

        return $path;
    }
}
