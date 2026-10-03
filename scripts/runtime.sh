#!/usr/bin/env bash
set -Eeuo pipefail
cd /app
php artisan config:clear
php infra/operations.php preflight
case "${1:-serve}" in
    serve)
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
        nginx -c /app/infra/nginx.conf -g 'daemon off;' & pids+=("$!")
        php artisan queue:work database --sleep=2 --tries=1 --timeout=900 & pids+=("$!")
        bash infra/scheduler.sh & pids+=("$!")
        # Any lost child, including a clean worker exit, restarts the whole unit.
        wait -n "${pids[@]}" || true
        echo 'Critical runtime process exited; restarting container.' >&2
        exit 1
        ;;
    *) exec "$@" ;;
esac
