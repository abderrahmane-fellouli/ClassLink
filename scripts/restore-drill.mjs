// Disposable PostgreSQL restore drill. Never accepts an existing data directory.
import { execFileSync } from 'node:child_process'
import { mkdtempSync, mkdirSync, readdirSync, existsSync } from 'node:fs'
import { resolve, join } from 'node:path'
const bin = process.env.PG_BIN
const temp = process.env.INFRA_TEMP
if (!bin || !temp) throw new Error('Set PG_BIN and approved INFRA_TEMP directory.')
for (const name of ['initdb', 'pg_ctl', 'createdb', 'psql', 'pg_dump', 'pg_restore']) {
  if (!existsSync(join(bin, `${name}${process.platform === 'win32' ? '.exe' : ''}`))) throw new Error(`PostgreSQL client/server prerequisite missing: ${name}`)
}
const dir = mkdtempSync(join(temp, 'classlink-restore-'))
const data = join(dir, 'data')
const backups = join(dir, 'backups')
mkdirSync(backups)
const binary = name => join(bin, `${name}${process.platform === 'win32' ? '.exe' : ''}`)
const env = { ...process.env, PATH: `${bin}${process.platform === 'win32' ? ';' : ':'}${process.env.PATH}`, PGHOST: '127.0.0.1', PGPORT: '55432', PGUSER: 'infra_fixture', PGDATABASE: 'source_fixture', PGSSLMODE: 'disable', BACKUP_DIR: backups.replaceAll('\\', '/') }
const run = (name, args, extra = {}) => (execFileSync(binary(name), args, { env: { ...env, ...extra }, stdio: name === 'pg_ctl' ? 'ignore' : 'pipe', timeout: 30000 }) || '').toString()
const bash = process.env.INFRA_BASH || 'bash'
const script = (name, args = [], extra = {}) => execFileSync(bash, [resolve(`scripts/${name}`).replaceAll('\\', '/'), ...args], { env: { ...env, ...extra }, stdio: 'pipe' }).toString()
run('initdb', ['-D', data, '-U', 'infra_fixture', '-A', 'trust', '--no-locale'])
let started = false
try {
  run('pg_ctl', ['-D', data, '-l', join(dir, 'server.log'), '-o', '-h 127.0.0.1 -p 55432', '-w', 'start'])
  started = true
  run('createdb', ['source_fixture'])
  run('createdb', ['restore_fixture'])
  run('psql', ['-X', '-v', 'ON_ERROR_STOP=1', '-c', "create table migrations (id integer primary key, migration text, batch integer); insert into migrations values (1,'synthetic_restore_fixture',1); create table infra_restore_probe (id integer primary key, value text); insert into infra_restore_probe values (1,'synthetic-only');"])
  console.log(script('backup.sh'))
  const file = join(backups, readdirSync(backups).find(name => name.endsWith('.dump'))).replaceAll('\\', '/')
  console.log(script('restore.sh', [file], { PGDATABASE: 'restore_fixture', RESTORE_CONFIRM: 'restore_fixture' }))
  const value = run('psql', ['-X', '-At', '-c', 'select value from infra_restore_probe'], { PGDATABASE: 'restore_fixture' }).trim()
  if (value !== 'synthetic-only') throw new Error('Restored fixture mismatch.')
  let refused = false
  try { script('restore.sh', [file], { PGDATABASE: 'restore_fixture', RESTORE_CONFIRM: 'restore_fixture' }) } catch { refused = true }
  if (!refused) throw new Error('Nonempty target was not refused.')
  console.log('PASS: backup, checksum, transactional restore, record comparison, nonempty-target refusal.')
} finally {
  if (started) run('pg_ctl', ['-D', data, '-m', 'fast', '-w', 'stop'])
}
