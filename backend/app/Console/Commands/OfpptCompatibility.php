<?php

namespace App\Console\Commands;

use App\Services\OfpptCompatibilityBootstrap;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class OfpptCompatibility extends Command
{
    protected $signature = 'classlink:ofppt-compatibility {--manifest= : Private reviewed JSON manifest} {--dry-run : Inspect only (default)} {--apply : Explicitly apply} {--confirm-manifest=} {--confirm-target=} {--confirm-inventory=} {--writers-quiesced : Operator acknowledgement; does not stop writers automatically}';

    protected $description = 'Bounded OFPPT compatibility inspection/bootstrap; never automatically invoked.';

    public function handle(OfpptCompatibilityBootstrap $bootstrap): int
    {
        $report = [];
        try {
            $path = $this->option('manifest');
            if (! is_string($path) || ! is_file($path) || filesize($path) > 65536 || ($this->option('apply') && $this->option('dry-run'))) {
                $this->error('A bounded reviewed manifest and one mode are required.');

                return self::FAILURE;
            }
            $manifest = json_decode(file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
            if (! is_array($manifest)) {
                throw new \RuntimeException('invalid_manifest');
            }
            $report = $this->option('apply') ? $bootstrap->apply($manifest, (string) $this->option('confirm-manifest'), (string) $this->option('confirm-target'), (string) $this->option('confirm-inventory'), (bool) $this->option('writers-quiesced')) : $bootstrap->dryRun($manifest);
        } catch (Throwable $error) {
            $report = ['state' => 'refused_or_failed', 'code' => $error instanceof ValidationException ? $error->errors() : 'operation_failed'];
        }
        $directory = storage_path('app/private/ofppt-compatibility');
        File::ensureDirectoryExists($directory, 0700);
        $reportPath = $directory.'/'.Str::uuid().'.json';
        File::put($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        @chmod($reportPath, 0600);
        $this->line('Private report: '.$reportPath);
        if (isset($report['manifest_hash'])) {
            $this->line('Manifest: '.$report['manifest_hash']);
            $this->line('Target: '.$report['target_hash']);
            $this->line('Inventory: '.$report['inventory_hash']);
        }

        return isset($report['conflict_count']) && $report['conflict_count'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
