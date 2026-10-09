$root = Split-Path -Parent $PSScriptRoot
$database = Join-Path $root 'backend\database\database.sqlite'
if (-not (Test-Path -LiteralPath $database)) { throw 'Local SQLite database missing.' }
$env:APP_ENV = 'local'
$env:APP_DEBUG = 'false'
$env:APP_CONFIG_CACHE = Join-Path ([IO.Path]::GetTempPath()) 'opencode\unused-classlink-local-config.php'
$keyBytes = New-Object byte[] 32
$rng = [Security.Cryptography.RandomNumberGenerator]::Create()
$rng.GetBytes($keyBytes)
$rng.Dispose()
$env:APP_KEY = 'base64:' + [Convert]::ToBase64String($keyBytes)
$env:DB_CONNECTION = 'sqlite'
$env:DB_DATABASE = $database
$env:DB_URL = '(null)'
$env:APP_URL = 'http://127.0.0.1:8000'
$env:FRONTEND_URL = 'http://127.0.0.1:5173'
$env:MAIL_MAILER = 'array'
$env:MAIL_URL = '(null)'
$env:QUEUE_CONNECTION = 'sync'
$env:CACHE_STORE = 'database'
$env:DB_CACHE_CONNECTION = 'sqlite'
$env:DB_CACHE_LOCK_CONNECTION = 'sqlite'
$env:SESSION_DRIVER = 'file'
$env:FILESYSTEM_DISK = 'local'
$env:DEV_AUTH_ENABLED = 'true'
$env:VITE_DEV_AUTH_ENABLED = 'false'
$env:VITE_API_URL = 'http://127.0.0.1:8000/api'
# Windows removes empty environment variables; Laravel recognizes this explicit
# null sentinel and therefore cannot reload provider secrets from the .env file.
foreach ($key in @('AI_PROVIDER_1_KEY','AI_PROVIDER_2_KEY','AI_PROVIDER_3_KEY','AZURE_CLIENT_ID','AZURE_CLIENT_SECRET')) { [Environment]::SetEnvironmentVariable($key, '(null)', 'Process') }
$children = @()
try {
  foreach ($port in @(8000,5173)) { if (Get-NetTCPConnection -LocalPort $port -State Listen -ErrorAction SilentlyContinue) { throw "Port $port is already occupied. Refusing to reuse an unknown server." } }
  $children += Start-Process php.exe -ArgumentList @('artisan','serve','--host=127.0.0.1','--port=8000','--no-reload') -WorkingDirectory (Join-Path $root 'backend') -PassThru -NoNewWindow
  $vite = Join-Path $root 'frontend\node_modules\vite\bin\vite.js'
  $children += Start-Process node.exe -ArgumentList @('"' + $vite + '"','--host','127.0.0.1','--port','5173','--strictPort') -WorkingDirectory (Join-Path $root 'frontend') -PassThru -NoNewWindow
  'Local ClassLink: http://127.0.0.1:5173; SQLite only; outgoing mail and provider calls disabled.'
  Wait-Process -Id $children.Id
} finally {
  foreach ($child in $children) { taskkill /PID $child.Id /T /F 2>$null }
}
