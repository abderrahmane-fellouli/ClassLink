#!/usr/bin/env bash
set -Eeuo pipefail
: "${PGDATABASE:?Set the EMPTY target database}"
: "${PGHOST:?Set libpq PGHOST}"
: "${PGUSER:?Set libpq PGUSER}"
[[ "${RESTORE_CONFIRM:-}" == "$PGDATABASE" ]] || { echo 'RESTORE_CONFIRM must match target PGDATABASE.' >&2; exit 1; }
file="${1:?Pass dump file}"
[[ -f "$file" && -f "$file.sha256" ]] || { echo 'Dump and checksum required.' >&2; exit 1; }
export PGSSLMODE="${PGSSLMODE:-require}"
export PGCONNECT_TIMEOUT=15
sha256sum --check "$file.sha256"
tables=$(psql -X -At -v ON_ERROR_STOP=1 -c "select count(*) from information_schema.tables where table_schema='public'")
[[ "$tables" == 0 ]] || { echo 'Refusing restore into a non-empty database.' >&2; exit 1; }
pg_restore --dbname="$PGDATABASE" --no-owner --no-acl --exit-on-error --single-transaction "$file"
psql -X -v ON_ERROR_STOP=1 -c 'select count(*) as applied_migrations from migrations;'
echo 'Restore completed. Validate records and private objects before switching traffic.'
