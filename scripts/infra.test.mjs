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
test('nginx runtime paths are explicit and privately owned by the non-root user', () => {
  const docker = read('Dockerfile')
  const nginx = read('scripts/nginx.conf')
  const runtime = read('scripts/runtime.sh')
  assert.match(docker, /USER www-data/)
  assert.match(docker, /USER www-data\s+#.*\s+RUN nginx -e \/dev\/stderr -t -c \/app\/infra\/nginx\.conf/)
  assert.match(docker, /chown -R www-data:www-data \/tmp\/nginx/)
  assert.match(docker, /chmod 750 \/tmp\/nginx/)
  assert.doesNotMatch(docker, /chmod\s+(?:-\S+\s+)*777|USER root/)
  assert.doesNotMatch(nginx, /^\s*user\s+/m)
  assert.match(nginx, /pid \/tmp\/nginx\/nginx\.pid;/)
  for (const path of ['client_body', 'proxy', 'fastcgi', 'uwsgi', 'scgi']) {
    assert.ok(docker.includes(`/tmp/nginx/${path}`), `${path} directory created at build time`)
    assert.ok(nginx.includes(`${path}_temp_path /tmp/nginx/${path};`), `${path} avoids package defaults`)
  }
  assert.match(nginx, /error_log \/dev\/stderr/)
  assert.match(nginx, /access_log \/dev\/stdout/)
  assert.match(runtime, /nginx -e \/dev\/stderr -t -c \/tmp\/nginx\/nginx\.conf/)
  assert.match(runtime, /nginx -e \/dev\/stderr -c \/tmp\/nginx\/nginx\.conf -g 'daemon off;'/)
  assert.match(runtime, /port="\$\{PORT:-8000\}"/)
  assert.match(runtime, /10#\$port < 1024 \|\| 10#\$port > 65535/)
  assert.match(runtime, /listen \$\{port\};/)
  assert.match(docker, /http:\/\/127\.0\.0\.1:\$\{PORT:-8000\}\/up/)
})
test('runtime accepts Render ports and rejects privileged or invalid port values', () => {
  const runtime = read('scripts/runtime.sh')
  const start = runtime.indexOf('        port="${PORT:-8000}"')
  const end = runtime.indexOf('        # Source config', start)
  assert.ok(start >= 0 && end > start)
  const setup = runtime.slice(start, end)
  const bash = process.platform === 'win32' ? `${process.env.ProgramFiles}/Git/bin/bash.exe` : 'bash'
  const check = port => spawnSync(bash, ['-c', `${setup}\nprintf '%s' "$port"`], {
    env: { ...process.env, PORT: port }, encoding: 'utf8',
  })
  for (const [input, expected] of [['', '8000'], ['8000', '8000'], ['10000', '10000'], ['1024', '1024'], ['65535', '65535'], ['08000', '8000']]) {
    const result = check(input)
    assert.equal(result.status, 0, result.error?.message ?? result.stderr)
    assert.equal(result.stdout, expected)
  }
  for (const port of ['0', '80', '1023', '65536', '999999', '-1', 'abc', '8000;exit 0', '8000\n']) {
    const result = check(port)
    assert.equal(result.status, 1, `${port}: ${result.error?.message ?? result.stderr}`)
    assert.match(result.stderr, /PORT must be an unprivileged TCP port/)
  }
})
test('restore refuses nonempty databases and requires explicit confirmation', () => {
  const script = read('scripts/restore.sh')
  assert.match(script, /RESTORE_CONFIRM/)
  assert.match(script, /Refusing restore into a non-empty database/)
  assert.match(script, /--single-transaction/)
  assert.doesNotMatch(script, /--clean|drop database/i)
})
