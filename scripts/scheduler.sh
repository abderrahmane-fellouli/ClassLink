#!/usr/bin/env bash
set -Eeuo pipefail
cd /app
while true; do
    # Align to wall-clock minutes, not 60 seconds after a long-running digest.
    php infra/operations.php schedule
    sleep "$((60 - $(date +%s) % 60))"
done
