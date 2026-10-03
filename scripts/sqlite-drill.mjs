import { execFileSync } from 'node:child_process'
import { mkdtempSync, writeFileSync } from 'node:fs'
import { join, resolve } from 'node:path'
const temp = process.env.INFRA_TEMP
if (!temp) throw new Error('Set INFRA_TEMP to an existing approved temporary directory.')
const dir = mkdtempSync(join(temp, 'classlink-infra-'))
const db = join(dir, 'drill.sqlite')
writeFileSync(db, '')
const env = { ...process.env, APP_ENV: 'local', APP_DEBUG: 'false', APP_KEY: '0123456789abcdef0123456789abcdef', DB_CONNECTION: 'sqlite', DB_DATABASE: db, CACHE_STORE: 'database', QUEUE_CONNECTION: 'database', MAIL_MAILER: 'array', FRONTEND_URL: 'http://localhost:5173' }
const run = args => execFileSync('php', args, { cwd: resolve('.'), env, stdio: 'inherit', timeout: 60000 })
run(['scripts/sqlite-drill.php', 'setup'])
run(['backend/artisan', 'queue:work', 'database', '--once', '--tries=1', '--timeout=900'])
run(['scripts/sqlite-drill.php', 'verify'])
run(['scripts/operations.php', 'schedule'])
run(['scripts/operations.php', 'health'])
console.log('PASS: migrations, persisted queue, independent worker, expired OTP pruning, schedule:run subprocess, readiness.')
