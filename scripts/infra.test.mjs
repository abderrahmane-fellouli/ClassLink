import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { spawnSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'
import { parseDocument } from 'yaml'
const root = fileURLToPath(new URL('../', import.meta.url))
const read = path => readFileSync(`${root}/${path}`, 'utf8')
const phpConfig = (env) => spawnSync('php', ['-r', "require 'backend/vendor/autoload.php'; echo json_encode(require 'backend/config/classlink.php');"], { cwd: root, env: { ...process.env, ...env }, encoding: 'utf8' })

test('all YAML manifests parse with unique keys', () => {
  for (const path of ['render.yaml', 'docker-compose.yml', '.github/workflows/ci.yml', '.github/workflows/deploy.yml', '.github/workflows/monitor.yml', '.github/workflows/backup.yml', '.github/workflows/daily-digest.yml', '.github/workflows/prune.yml', '.github/workflows/ai-quota-reset.yml']) {
    assert.deepEqual(parseDocument(read(path)).errors, [], path)
  }
})
test('production URLs reject missing, HTTP, wildcard, local and credential origins', () => {
  for (const value of ['', 'http://app.example.com', '*', 'https://localhost', 'https://user:pass@app.example.com', 'https://app.example.com/path']) {
    assert.notEqual(phpConfig({ APP_ENV: 'production', APP_URL: 'https://api.example.com', FRONTEND_URL: value }).status, 0, value)
  }
})
test('CORS allows explicit origins while OAuth has one canonical frontend', () => {
  const result = phpConfig({ APP_ENV: 'production', APP_URL: 'https://api.example.com', FRONTEND_URL: 'https://app.example.com, https://staging.example.com' })
  assert.equal(result.status, 0, result.stderr)
  const config = JSON.parse(result.stdout)
  assert.equal(config.frontend_url, 'https://app.example.com')
  assert.deepEqual(config.allowed_origins, ['https://app.example.com', 'https://staging.example.com'])
})
test('local URL defaults remain local-only', () => {
  const result = phpConfig({ APP_ENV: 'local', FRONTEND_URL: 'http://localhost:5173' })
  assert.equal(result.status, 0)
  assert.equal(JSON.parse(result.stdout).frontend_url, 'http://localhost:5173')
})
test('Vercel production build refuses unset or unsafe API URLs', () => {
  for (const value of ['', 'http://api.example.com/api', 'https://localhost/api', 'https://api.example.com', 'https://api.example.invalid/api']) {
    assert.notEqual(spawnSync(process.execPath, ['scripts/check-frontend-env.mjs'], { cwd: root, env: { ...process.env, VITE_API_URL: value } }).status, 0)
  }
  assert.equal(spawnSync(process.execPath, ['scripts/check-frontend-env.mjs'], { cwd: root, env: { ...process.env, VITE_API_URL: 'https://api.example.com/api' } }).status, 0)
})
test('runtime runs a real worker and schedule:run, without public storage links', () => {
  assert.match(read('scripts/runtime.sh'), /queue:work database.*--timeout=900/)
  assert.match(read('scripts/runtime.sh'), /wait -n/)
  assert.match(read('scripts/scheduler.sh'), /php infra\/operations.php schedule/)
  assert.match(read('scripts/operations.php'), /Process\(\[PHP_BINARY, .*'schedule:run'/)
  assert.doesNotMatch(read('Dockerfile'), /storage:link|php -S/)
  const render = parseDocument(read('render.yaml')).toJS().services[0]
  assert.equal(render.numInstances, 1)
  assert.equal(render.healthCheckPath, '/ready')
  assert.equal(render.envVars.find(v => v.key === 'QUEUE_CONNECTION').value, 'database')
})
test('maintenance endpoint workflows are manual-only to avoid duplicate scheduling', () => {
  for (const path of ['daily-digest', 'prune', 'ai-quota-reset']) {
    const workflow = parseDocument(read(`.github/workflows/${path}.yml`)).toJS()
    assert.deepEqual(Object.keys(workflow.on), ['workflow_dispatch'])
  }
})
test('restore refuses nonempty databases and requires explicit confirmation', () => {
  const script = read('scripts/restore.sh')
  assert.match(script, /RESTORE_CONFIRM/)
  assert.match(script, /Refusing restore into a non-empty database/)
  assert.match(script, /--single-transaction/)
  assert.doesNotMatch(script, /--clean|drop database/i)
})
