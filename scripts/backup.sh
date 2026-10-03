#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
: "${PGHOST:?Set libpq PGHOST}"
: "${PGDATABASE:?Set libpq PGDATABASE}"
: "${PGUSER:?Set libpq PGUSER}"
: "${BACKUP_DIR:?Set an existing private backup directory}"
[[ -d "$BACKUP_DIR" ]] || { echo 'Backup directory must already exist.' >&2; exit 1; }
export PGSSLMODE="${PGSSLMODE:-require}"
export PGCONNECT_TIMEOUT=15
file="$BACKUP_DIR/classlink-$(date -u +%Y%m%dT%H%M%SZ)-$$.dump"
trap 'rm -f "$file.partial"' EXIT
pg_dump --format=custom --no-owner --no-acl --file="$file.partial"
pg_restore --list "$file.partial" >/dev/null
mv "$file.partial" "$file"
sha256sum "$file" > "$file.sha256"
echo "Backup created: $file"
# Off-site copy must succeed before local retention is applied by the operator.
