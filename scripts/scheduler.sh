#!/usr/bin/env bash
set -Eeuo pipefail
cd /app
while true; do
    # Align to wall-clock minutes, not 60 seconds after a long-running digest.
    if php infra/operations.php schedule; then
        :
    else
        status=$?
        printf 'Scheduler tick failed: exit_code=%s\n' "$status" >&2
        exit "$status"
    fi
    sleep "$((60 - $(date +%s) % 60))"
done
