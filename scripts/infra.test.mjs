import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync, mkdtempSync, rmSync, statSync } from 'node:fs'
import { join } from 'node:path'
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
  assert.match(read('scripts/runtime.sh'), /queue:work database.*--timeout=900 --max-time=3600/)
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
  assert.match(docker, /chown -R www-data:www-data \/app\/storage\/nginx/)
  assert.match(docker, /chmod 750 \/app\/storage\/nginx/)
  assert.doesNotMatch(docker, /chmod\s+(?:-\S+\s+)*777|USER root/)
  assert.doesNotMatch(nginx, /^\s*user\s+/m)
  assert.match(nginx, /pid \/app\/storage\/nginx\/nginx\.pid;/)
  for (const path of ['client_body', 'proxy', 'fastcgi', 'uwsgi', 'scgi']) {
    assert.ok(docker.includes(`/app/storage/nginx/${path}`), `${path} directory created at build time`)
    assert.ok(nginx.includes(`${path}_temp_path /app/storage/nginx/${path};`), `${path} avoids package defaults`)
  }
  assert.match(nginx, /error_log \/dev\/stderr/)
  assert.match(nginx, /access_log \/dev\/stdout/)
  assert.match(runtime, /nginx -e \/dev\/stderr -t -c "\$nginx_dir\/nginx\.conf"/)
  assert.match(runtime, /nginx -e \/dev\/stderr -c "\$nginx_dir\/nginx\.conf" -g 'daemon off;'/)
  assert.doesNotMatch(nginx + runtime + docker, /\/tmp\/nginx/)
  assert.match(runtime, /port="\$\{PORT:-8000\}"/)
  assert.match(runtime, /10#\$port < 1024 \|\| 10#\$port > 65535/)
  assert.match(runtime, /listen \$\{port\};/)
  assert.match(docker, /http:\/\/127\.0\.0\.1:\$\{PORT:-8000\}\/up/)
})
test('runtime accepts Render ports and rejects privileged or invalid port values', () => {
  const runtime = read('scripts/runtime.sh')
  const start = runtime.indexOf('        port="${PORT:-8000}"')
  const end = runtime.indexOf('        # Prepare nginx', start)
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
test('startup recreates missing nginx directories and renders config on fresh and repeated starts', () => {
  const runtime = read('scripts/runtime.sh')
  const start = runtime.indexOf('        nginx_dir=/app/storage/nginx')
  const end = runtime.indexOf('        # -e also', start)
  assert.ok(start >= 0 && end > start)
  // Execute production initialization with only the filesystem locations redirected.
  const setup = runtime.slice(start, end)
    .replace('nginx_dir=/app/storage/nginx', 'nginx_dir="$TEST_NGINX_DIR"')
    .replace('infra/nginx.conf', '"$TEST_NGINX_TEMPLATE"')
  const bash = process.platform === 'win32' ? `${process.env.ProgramFiles}/Git/bin/bash.exe` : 'bash'
  const workspace = mkdtempSync(join(root, '.nginx-runtime-test-'))
  const runtimeDir = join(workspace, 'nginx')
  const run = port => {
    const result = spawnSync(bash, ['-c', `set -Eeuo pipefail\nport=${port}\n${setup}`], {
      env: { ...process.env, TEST_NGINX_DIR: runtimeDir.replaceAll('\\', '/'), TEST_NGINX_TEMPLATE: join(root, 'scripts/nginx.conf').replaceAll('\\', '/') },
      encoding: 'utf8',
    })
    assert.equal(result.status, 0, result.error?.message ?? result.stderr)
    for (const path of ['', 'client_body', 'proxy', 'fastcgi', 'uwsgi', 'scgi']) {
      const stat = statSync(join(runtimeDir, path))
      assert.ok(stat.isDirectory())
      if (process.platform !== 'win32') assert.equal(stat.mode & 0o777, 0o750)
    }
    assert.ok(readFileSync(join(runtimeDir, 'nginx.conf'), 'utf8').includes(`listen ${port};`))
    if (process.platform !== 'win32') assert.equal(statSync(join(runtimeDir, 'nginx.conf')).mode & 0o777, 0o640)
  }
  try {
    run(10000) // No nginx directory exists before this first start.
    run(8000) // Repeated start remains safe and updates the generated config.
    rmSync(runtimeDir, { recursive: true })
    run(10000) // Recreate everything after runtime state is lost.
  } finally {
    rmSync(workspace, { recursive: true, force: true })
  }
})
test('restore refuses nonempty databases and requires explicit confirmation', () => {
  const script = read('scripts/restore.sh')
  assert.match(script, /RESTORE_CONFIRM/)
  assert.match(script, /Refusing restore into a non-empty database/)
  assert.match(script, /--single-transaction/)
  assert.doesNotMatch(script, /--clean|drop database/i)
})

const supervision = () => {
  const runtime = read('scripts/runtime.sh')
  const start = runtime.indexOf('        # Supervision logs')
  const end = runtime.indexOf('        # Do not accept web traffic', start)
  assert.ok(start >= 0 && end > start)
  return runtime.slice(start, end)
}
const runSupervisor = body => {
  const bash = process.platform === 'win32' ? `${process.env.ProgramFiles}/Git/bin/bash.exe` : 'bash'
  return spawnSync(bash, ['-c', `set -Eeuo pipefail\n${supervision()}\n${body}`], { encoding: 'utf8', timeout: 10000 })
}

test('supervision identifies each critical process and preserves its exit code', () => {
  for (const [name, code] of [['php-fpm', 78], ['nginx', 7], ['scheduler', 9], ['nginx', 0]]) {
    const result = runSupervisor(`start_process survivor bash -c 'sleep 5'\nstart_process ${name} bash -c 'sleep 0.1; exit ${code}'\nsupervise`)
    assert.equal(result.status, 1, result.error?.message ?? result.stderr)
    assert.match(result.stderr, new RegExp(`Critical runtime process exited: process=${name} pid=\\d+ exit_code=${code};`))
  }
})
test('worker exits recover with backoff, one replacement and unchanged web/scheduler PIDs', () => {
  for (const [code, alreadyExited] of [[0, false], [0, true], [12, false], [1, false]]) {
    const result = runSupervisor(`
      start_process php-fpm bash -c 'sleep 8'
      start_process nginx bash -c 'sleep 8'
      start_process scheduler bash -c 'sleep 8'
      web_pids=("\${pids[@]}")
      start_process queue-worker bash -c 'sleep 0.1; exit ${code}'
      old_worker="\${pids[3]}"
      recovery_started=$SECONDS
      spawn_worker() {
        ! kill -0 "$old_worker" 2>/dev/null || exit 21
        [[ "\${#pids[@]}" == 3 ]] || exit 22
        [[ "\${#process_names[@]}" == 3 ]] || exit 23
        for p in "\${web_pids[@]}"; do kill -0 "$p" || exit 24; done
        (( SECONDS - recovery_started >= 2 )) || exit 25
        start_process queue-worker bash -c 'sleep 5'
        [[ "\${#pids[@]}" == 4 ]] || exit 26
        echo 'one replacement; web and scheduler alive; backoff observed'
        kill -TERM $$
      }
      ${alreadyExited ? 'sleep 0.3' : ':'}
      supervise
    `)
    assert.equal(result.status, 0, result.error?.message ?? result.stderr)
    assert.match(result.stdout, /one replacement; web and scheduler alive; backoff observed/)
    assert.match(result.stderr, new RegExp(`exit_code=${code}; respawning worker`))
    assert.doesNotMatch(result.stderr, /restarting container|supervisor wait failed/)
  }
})
test('three abnormal worker exits within 60 seconds escalate after bounded recovery', () => {
  const result = runSupervisor(`
    start_process nginx bash -c 'sleep 8'
    worker_command=(bash -c 'exit 9')
    spawn_worker
    supervise
  `)
  assert.equal(result.status, 1, result.error?.message ?? result.stderr)
  assert.match(result.stderr, /exit_code=9; respawning worker \(1\/3\)/)
  assert.match(result.stderr, /exit_code=9; respawning worker \(2\/3\)/)
  assert.match(result.stderr, /worker crash-loop detected:.*exit_code=9 restarts=3; restarting container/)
  assert.equal((result.stderr.match(/process started: process=queue-worker/g) ?? []).length, 3)
})
test('worker recovery does not discard a concurrently exited critical child', () => {
  const result = runSupervisor(`
    start_process queue-worker bash -c 'exit 0'
    start_process nginx bash -c 'exit 7'
    worker_command=(bash -c 'sleep 5')
    sleep 0.3
    supervise
  `)
  assert.equal(result.status, 1, result.error?.message ?? result.stderr)
  assert.match(result.stderr, /process=nginx pid=\d+ exit_code=7; restarting container/)
})
test('supervision captures a child that already exited before wait begins', () => {
  const result = runSupervisor("start_process survivor bash -c 'sleep 5'\nstart_process scheduler bash -c 'exit 9'\nsleep 0.2\nsupervise")
  assert.equal(result.status, 1, result.error?.message ?? result.stderr)
  assert.match(result.stderr, /process=scheduler pid=\d+ exit_code=9;/)
})
test('supervision distinguishes external shutdown from an unexpected process exit', () => {
  const result = runSupervisor("start_process survivor bash -c 'sleep 5'\nkill -TERM $$")
  assert.equal(result.status, 0, result.error?.message ?? result.stderr)
  assert.match(result.stderr, /Runtime shutdown requested: signal=TERM/)
  assert.doesNotMatch(result.stderr, /Critical runtime process exited/)
})
test('container init signals only the supervisor, which owns child shutdown ordering', () => {
  assert.match(read('Dockerfile'), /ENTRYPOINT \["\/sbin\/tini", "--", "bash", "\/app\/infra\/runtime\.sh"\]/)
  assert.doesNotMatch(read('Dockerfile'), /ENTRYPOINT.*"-g"/)
})
test('platform TERM while supervising drains children, reaps them and exits successfully', () => {
  const result = runSupervisor(`
    # Shell stand-ins need job control to receive QUIT (real nginx/FPM install
    # their own signal handlers even when launched as background processes).
    set -m
    start_process nginx bash -c 'trap "echo nginx-drained; exit 0" QUIT; while :; do sleep 0.1; done'
    start_process php-fpm bash -c 'trap "echo fpm-drained; exit 0" QUIT; while :; do sleep 0.1; done'
    start_process queue-worker bash -c 'trap "echo worker-stopped; exit 0" TERM; while :; do sleep 0.1; done'
    start_process scheduler bash -c 'trap "echo scheduler-stopped; exit 0" TERM; while :; do sleep 0.1; done'
    check_cleanup() {
      cleanup
      for pid in "\${pids[@]}"; do ! kill -0 "$pid" 2>/dev/null || exit 31; done
      echo all-children-reaped
    }
    trap check_cleanup EXIT
    ( sleep 0.5; kill -TERM $$ ) &
    supervise
  `)
  assert.equal(result.status, 0, result.error?.message ?? result.stderr)
  for (const marker of ['nginx-drained', 'fpm-drained', 'worker-stopped', 'scheduler-stopped', 'all-children-reaped']) {
    assert.ok(result.stdout.includes(marker), `${marker}: ${result.stdout}\n${result.stderr}`)
  }
  assert.ok(result.stdout.indexOf('nginx-drained') < result.stdout.indexOf('fpm-drained'))
  assert.match(result.stderr, /Runtime shutdown requested: signal=TERM/)
  assert.doesNotMatch(result.stderr, /Critical runtime process exited|restarting container|supervisor wait failed/)
})
test('TERM during worker backoff suppresses replacement and intentional shutdown is not a failure', () => {
  const result = runSupervisor(`
    start_process nginx bash -c 'sleep 5'
    start_process queue-worker bash -c 'exit 0'
    spawn_worker() { echo unexpected-replacement; exit 32; }
    ( sleep 0.5; kill -TERM $$ ) &
    supervise
  `)
  assert.equal(result.status, 0, result.error?.message ?? result.stderr)
  assert.match(result.stderr, /Runtime shutdown requested: signal=TERM/)
  assert.doesNotMatch(result.stdout, /unexpected-replacement/)
  assert.doesNotMatch(result.stderr, /Critical runtime process exited|restarting container/)
})
test('a child-only nginx TERM is still unexpected even when nginx exits successfully', () => {
  const result = runSupervisor(`
    start_process nginx bash -c 'trap "exit 0" TERM; while :; do sleep 0.1; done'
    nginx_pid="\${pids[0]}"
    ( sleep 0.4; kill -TERM "$nginx_pid" ) &
    supervise
  `)
  assert.equal(result.status, 1, result.error?.message ?? result.stderr)
  assert.match(result.stderr, /Critical runtime process exited: process=nginx pid=\d+ exit_code=0;/)
  assert.doesNotMatch(result.stderr, /Runtime shutdown requested/)
})
test('repeated platform TERM does not interrupt graceful web draining', () => {
  const result = runSupervisor(`
    set -m
    start_process nginx bash -c 'trap "sleep 0.5; echo drain-complete; exit 0" QUIT; while :; do sleep 0.1; done'
    ( sleep 0.4; kill -TERM $$; sleep 0.2; kill -TERM $$ ) &
    supervise
  `)
  assert.equal(result.status, 0, result.error?.message ?? result.stderr)
  assert.match(result.stdout, /drain-complete/)
  assert.equal((result.stderr.match(/Runtime shutdown requested/g) ?? []).length, 1)
  assert.doesNotMatch(result.stderr, /Critical runtime process exited|restarting container/)
})
test('FPM readiness retries before nginx starts and is bounded on timeout', () => {
  const ready = runSupervisor(`
    start_process php-fpm bash -c 'sleep 5'
    calls=0
    php() { calls=$((calls+1)); ((calls >= 3)); }
    sleep() { :; }
    wait_for_fpm "\${pids[0]}"
    echo "probe_count=$calls"
    start_process nginx bash -c 'exit 7'
    supervise
  `)
  assert.equal(ready.status, 1, ready.error?.message ?? ready.stderr)
  assert.match(ready.stdout, /probe_count=3/)
  assert.ok(ready.stderr.indexOf('Runtime upstream ready:') < ready.stderr.indexOf('process=nginx'))

  const timeout = runSupervisor(`
    start_process php-fpm bash -c 'sleep 5'
    php() { return 1; }
    sleep() { :; }
    wait_for_fpm "\${pids[0]}"
    echo 'must not start nginx'
  `)
  assert.equal(timeout.status, 1, timeout.error?.message ?? timeout.stderr)
  assert.match(timeout.stderr, /process=php-fpm readiness_attempts=30/)
  assert.doesNotMatch(timeout.stdout, /must not start nginx/)
})
test('FPM startup exit is reported with its status before nginx starts', () => {
  const result = runSupervisor(`
    start_process php-fpm bash -c 'exit 23'
    php() { return 1; }
    wait_for_fpm "\${pids[0]}"
    echo 'must not start nginx'
  `)
  assert.equal(result.status, 1, result.error?.message ?? result.stderr)
  assert.match(result.stderr, /process=php-fpm pid=\d+ exit_code=23;/)
  assert.doesNotMatch(result.stdout, /must not start nginx/)
})
test('scheduler reports the failing tick status without logging command arguments', () => {
  const script = read('scripts/scheduler.sh').replace('cd /app', ':')
  const bash = process.platform === 'win32' ? `${process.env.ProgramFiles}/Git/bin/bash.exe` : 'bash'
  const result = spawnSync(bash, ['-c', `php() { return 17; }\n${script}`], { encoding: 'utf8', timeout: 10000 })
  assert.equal(result.status, 17, result.error?.message ?? result.stderr)
  assert.match(result.stderr, /Scheduler tick failed: exit_code=17/)
})
