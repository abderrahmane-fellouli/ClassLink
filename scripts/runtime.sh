#!/usr/bin/env bash
set -Eeuo pipefail
cd /app
php artisan config:clear
php infra/operations.php preflight
case "${1:-serve}" in
    serve)
        # Render supplies PORT; keep the image's default for local/Compose use.
        port="${PORT:-8000}"
        if [[ ! "$port" =~ ^[0-9]{1,5}$ ]] || (( 10#$port < 1024 || 10#$port > 65535 )); then
            echo 'PORT must be an unprivileged TCP port between 1024 and 65535.' >&2
            exit 1
        fi
        port=$((10#$port))
        # Prepare nginx on every start: runtime mounts may hide image directories.
        # storage is owned by www-data; do not rely on image contents in /tmp.
        nginx_dir=/app/storage/nginx
        nginx_dirs=("$nginx_dir" "$nginx_dir/client_body" "$nginx_dir/proxy" "$nginx_dir/fastcgi" "$nginx_dir/uwsgi" "$nginx_dir/scgi")
        (umask 027; mkdir -p "${nginx_dirs[@]}")
        chmod 750 "${nginx_dirs[@]}"
        # Source config stays root-owned; only the rendered copy is writable.
        (umask 027; sed "s/listen 8000;/listen ${port};/" infra/nginx.conf > "$nginx_dir/nginx.conf")
        chmod 640 "$nginx_dir/nginx.conf"
        # -e also redirects startup errors before nginx reads its config file.
        nginx -e /dev/stderr -t -c "$nginx_dir/nginx.conf"
        # A single replica owns migrations and scheduling. Never seed production.
        php artisan migrate --force
        php artisan config:cache
        php artisan route:cache
        php artisan view:cache
        # Supervision logs contain only fixed process names, PIDs and statuses.
        pids=()
        declare -A process_names=()
        start_process() {
            local name="$1" pid
            shift
            "$@" & pid=$!
            pids+=("$pid")
            process_names["$pid"]="$name"
            printf 'Runtime process started: process=%s pid=%s\n' "$name" "$pid" >&2
        }
        # Single-instance database queue worker. --max-time bounds each worker so
        # it self-recycles hourly (exit 0) instead of accumulating state forever;
        # the recycle is handled in-process (see handle_exit) and never restarts
        # the whole web container.
        worker_command=(php artisan queue:work database --sleep=2 --tries=1 --timeout=900 --max-time=3600)
        spawn_worker() {
            start_process queue-worker "${worker_command[@]}"
        }
        critical_exit() {
            printf 'Critical runtime process exited: process=%s pid=%s exit_code=%s; restarting container.\n' "${process_names[$1]}" "$1" "$2" >&2
            exit 1
        }
        # Replace the dead worker PID with exactly one fresh worker, only after the
        # previous instance has been reaped, so two workers never overlap in time.
        respawn_worker() {
            local exited_pid="$1"
            local -a live=()
            local p
            for p in "${pids[@]}"; do
                # Retain other dead children so critical exits cannot be hidden.
                if [[ "$p" != "$exited_pid" ]]; then live+=("$p"); fi
            done
            pids=("${live[@]}")
            unset 'process_names[$exited_pid]'
            # Bound repeated reconnects, including successful lost-connection exits.
            # Existing web and scheduler processes keep running during this delay.
            sleep 2
            spawn_worker
        }
        worker_crashes=0
        worker_crash_window_start=0
        # Exit policy: web-tier (php-fpm/nginx) and scheduler failures are fatal and
        # restart the container (this surfaces the failure to Render). The queue
        # worker exits 0 on graceful recycles (DB connection loss, restart/TERM
        # signal, --max-time) and is respawned in-process; repeated non-zero exits
        # (crash loop) finally escalate to a container restart so an unexpected
        # critical failure is still detected.
        handle_exit() {
            local name="$1" pid="$2" status="$3" now
            if [[ "$name" != 'queue-worker' ]]; then
                critical_exit "$pid" "$status"
                return
            fi
            if (( status == 0 )); then
                worker_crashes=0
                printf 'Runtime worker recycled: process=%s pid=%s exit_code=%s; respawning worker only.\n' "$name" "$pid" "$status" >&2
                respawn_worker "$pid"
                return
            fi
            now="$(date +%s)"
            if (( now - worker_crash_window_start > 60 )); then
                worker_crashes=0
                worker_crash_window_start="$now"
            fi
            worker_crashes=$((worker_crashes + 1))
            if (( worker_crashes >= 3 )); then
                printf 'Runtime worker crash-loop detected: process=%s pid=%s exit_code=%s restarts=%s; restarting container.\n' "$name" "$pid" "$status" "$worker_crashes" >&2
                exit 1
            fi
            printf 'Runtime worker exited: process=%s pid=%s exit_code=%s; respawning worker (%s/3).\n' "$name" "$pid" "$status" "$worker_crashes" >&2
            respawn_worker "$pid"
        }
        wait_for_fpm() {
            local pid="$1" attempt status
            for ((attempt=0; attempt<30; attempt++)); do
                if ! kill -0 "$pid" 2>/dev/null; then
                    if wait "$pid"; then status=0; else status=$?; fi
                    critical_exit "$pid" "$status"
                fi
                if php -r '$s = @fsockopen("127.0.0.1", 9000, $errno, $error, 0.2); if ($s === false) { exit(1); } fclose($s);'; then
                    echo 'Runtime upstream ready: process=php-fpm address=127.0.0.1:9000' >&2
                    return 0
                fi
                sleep 1
            done
            echo 'Runtime startup failed: process=php-fpm readiness_attempts=30' >&2
            return 1
        }
        supervise() {
            local pid exited_pid status
            while true; do
                # wait -n alone can miss children that exited before waiting.
                for pid in "${pids[@]}"; do
                    if ! kill -0 "$pid" 2>/dev/null; then
                        if wait "$pid"; then status=0; else status=$?; fi
                        handle_exit "${process_names[$pid]}" "$pid" "$status"
                    fi
                done
                exited_pid=
                if wait -n -p exited_pid "${pids[@]}"; then status=0; else status=$?; fi
                if [[ -n "${exited_pid:-}" ]]; then
                    handle_exit "${process_names[$exited_pid]}" "$exited_pid" "$status"
                    continue
                fi
                if ((status != 127)); then
                    printf 'Runtime supervisor wait failed: exit_code=%s\n' "$status" >&2
                    exit 1
                fi
                # Exit between kill -0 and wait -n: rescan and collect its status.
            done
        }
        cleanup() { kill -TERM "${pids[@]}" 2>/dev/null || true; wait || true; }
        trap cleanup EXIT
        trap 'echo "Runtime shutdown requested: signal=TERM" >&2; exit 0' TERM
        trap 'echo "Runtime shutdown requested: signal=INT" >&2; exit 0' INT
        # Do not accept web traffic until the FastCGI listener is available.
        start_process php-fpm php-fpm -F
        wait_for_fpm "${pids[0]}"
        start_process nginx nginx -e /dev/stderr -c "$nginx_dir/nginx.conf" -g 'daemon off;'
        spawn_worker
        start_process scheduler bash infra/scheduler.sh
        # Worker recycling is independent; web and scheduler exits remain critical.
        supervise
        ;;
    *) exec "$@" ;;
esac
