<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\LocalSchoolAccountImport;
use App\Support\LocalSchoolDatabase;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class LocalSchoolImport extends Command
{
    protected $signature = 'classlink:local-import {--database= : Existing local SQLite file} {--file= : Private JSON roster file} {--backup-dir= : Local backup directory} {--upgrade : Apply existing additive migrations after backup} {--admin-id= : Existing admin ID} {--report= : Private result JSON file}';

    protected $description = 'Back up and transactionally import supplied school accounts into explicitly local SQLite only';

    public function handle(LocalSchoolAccountImport $import): int
    {
        $path = LocalSchoolDatabase::configure((string) $this->option('database'));
        $file = realpath((string) $this->option('file'));
        if (! $file || ! $this->option('backup-dir')) {
            throw new RuntimeException('Private input and backup directory are required.');
        }
        $input = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        $backup = LocalSchoolDatabase::backup((string) $this->option('backup-dir'));
        $this->info('Local SQLite verified. Backup: '.$backup);
        if ($this->option('upgrade') && $this->call('migrate', ['--database' => 'sqlite', '--force' => true, '--no-interaction' => true]) !== 0) {
            return self::FAILURE;
        }
        if (! Schema::hasTable('academic_years')) {
            throw new RuntimeException('Institutional schema missing. Use --upgrade for additive migration after backup.');
        }
        $admin = $this->option('admin-id') ? User::findOrFail((int) $this->option('admin-id')) : User::where('role', 'admin')->where('is_active', true)->orderBy('id')->firstOrFail();
        $stats = $import->run($input, $admin);
        $stats['backup'] = $backup;
        $stats['database'] = $path;
        if ($report = $this->option('report')) {
            file_put_contents($report, json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            chmod($report, 0600);
        }
        $this->line(json_encode(array_diff_key($stats, array_flip(['account_ids', 'matched_ids_preserved'])), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
