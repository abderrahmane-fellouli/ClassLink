param([switch]$BackgroundJobs)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$backend = Join-Path $root 'backend'
$frontend = Join-Path $root 'frontend'
foreach ($command in @('php', 'npm.cmd')) {
    if (-not (Get-Command $command -ErrorAction SilentlyContinue)) {
        throw "$command is required. Install the README prerequisites first."
    }
}
if (-not (Test-Path -LiteralPath (Join-Path $backend '.env'))) {
    throw 'Configure backend/.env and follow README local setup before starting.'
}
if (-not (Test-Path -LiteralPath (Join-Path $backend 'vendor/autoload.php'))) {
    throw 'Run composer install in backend first.'
}
if (-not (Test-Path -LiteralPath (Join-Path $frontend 'node_modules'))) {
    throw 'Run npm ci in frontend first.'
}

# Persistent operator-owned windows: closing a short-lived tool session should
# not silently stop Laravel while Vite keeps showing a working login page.
function Start-LocalTerminal($directory, $command) {
    Start-Process -FilePath 'powershell.exe' -WorkingDirectory $directory `
        -ArgumentList @('-NoExit', '-Command', $command)
}
if (-not (Get-NetTCPConnection -LocalPort 8000 -State Listen -ErrorAction SilentlyContinue)) {
    Start-LocalTerminal $backend 'php artisan serve --host=127.0.0.1 --port=8000 --no-reload'
}
if (-not (Get-NetTCPConnection -LocalPort 5173 -State Listen -ErrorAction SilentlyContinue)) {
    Start-LocalTerminal $frontend 'npm.cmd run dev -- --host 127.0.0.1 --port 5173'
}
if ($BackgroundJobs) {
    Start-LocalTerminal $backend 'php artisan queue:work database --tries=1 --timeout=900'
    Start-LocalTerminal $backend 'php artisan schedule:work'
}
Write-Host 'Open http://127.0.0.1:5173/login and use a demo role for local testing.'
Write-Host 'Keep the server windows open. Ctrl+C in each window stops its process.'
