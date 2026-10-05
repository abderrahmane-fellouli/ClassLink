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
        # Source config stays root-owned; only the rendered copy is writable.
        (umask 027; sed "s/listen 8000;/listen ${port};/" infra/nginx.conf > /tmp/nginx/nginx.conf)
        # -e also redirects startup errors before nginx reads its config file.
        nginx -e /dev/stderr -t -c /tmp/nginx/nginx.conf
        # A single replica owns migrations and scheduling. Never seed production.
        php artisan migrate --force
        php artisan config:cache
        php artisan route:cache
        php artisan view:cache
        pids=()
        cleanup() { kill -TERM "${pids[@]}" 2>/dev/null || true; wait || true; }
        trap cleanup EXIT
        trap 'exit 0' TERM INT
        php-fpm -F & pids+=("$!")
        nginx -e /dev/stderr -c /tmp/nginx/nginx.conf -g 'daemon off;' & pids+=("$!")
        php artisan queue:work database --sleep=2 --tries=1 --timeout=900 & pids+=("$!")
        bash infra/scheduler.sh & pids+=("$!")
        # Any lost child, including a clean worker exit, restarts the whole unit.
        wait -n "${pids[@]}" || true
        echo 'Critical runtime process exited; restarting container.' >&2
        exit 1
        ;;
    *) exec "$@" ;;
esac
