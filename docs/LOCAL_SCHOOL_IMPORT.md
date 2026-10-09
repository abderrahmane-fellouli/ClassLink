# Local school account import

Run these commands from the repository root. The launcher explicitly selects
`backend/database/database.sqlite`, binds both servers to loopback, disables
outgoing mail and provider credentials, and enables guarded local test login.

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\start-school-local.ps1
```

In another terminal, select an imported account using its institutional email:

```powershell
node scripts/local-school-login.mjs --email=your-school-address
```

Open `artifacts/local-import/login.html` and click the local login link.
This issues a regular expiring Sanctum token; it does not certify Microsoft
identity. The administrator can use the same helper with their existing email.
Stop the launcher with Ctrl+C when finished.

## Repeat an import

Keep the supplied roster JSON, backups and result reports under the ignored
`artifacts/local-import/` directory. Never commit them. JSON contains
`group_code`, optional `group_aliases`, `academic_year`, `starts_on`, `ends_on`,
`students` (`name`, `email`), and `teachers` (`name`, `email`, `module_code`,
`module_title`). Names and email spelling are preserved; matching is
case-insensitive. Roles come from the two lists, not a role in a submitted row.

```powershell
$env:APP_ENV = 'local'
$env:APP_CONFIG_CACHE = Join-Path $env:TEMP 'opencode\unused-classlink-local-config.php'
$env:DB_URL = '(null)'
$env:MAIL_MAILER = 'array'
php backend/artisan classlink:local-import --database=backend/database/database.sqlite --file=artifacts/local-import/roster.json --backup-dir=artifacts/local-import/backups --report=artifacts/local-import/report.json
```

The command pins SQLite before querying, snapshots the database with SQLite's
`VACUUM INTO`, then imports transactionally. For an older schema add `--upgrade`
to apply additive migrations **after** the backup. Do not reset or reseed the
database. Existing administrators, matched account IDs and Microsoft identity
fields are preserved. Reruns do not duplicate accounts or active enrollments,
modules, offerings or assignments. Confirmed seed identities with uncertain
dependent records are preserved and reported. Imports send no notifications
and appoint no delegates.

To restore, stop all local database processes and replace the SQLite file with
the chosen snapshot. The first pre-upgrade snapshot also restores the original
schema. Keep backups private: subsequent snapshots contain imported personal
data. Never point these tools at a production environment or remote database.
