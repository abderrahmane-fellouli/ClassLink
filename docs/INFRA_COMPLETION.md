# Infrastructure Completion Evidence

Date: 2026-10-03. Scope: infrastructure/deployment/reliability/CI readiness only.
This is a new evidence record, not a rewrite of the existing audit/progress.

## Inputs and Ownership

Read the complete official 45-page
`cahier_des_charges/ClassLink_Cahier_des_Charges_Professionnel.pdf`, especially
NF-02/NF-06/NF-07/NF-13/NF-14 and sections 17, 20, 21 and 45's external gates.
Read `docs/IMPLEMENTATION_AUDIT.md` Phase 4 (4.1-4.5), the original Dockerfile,
Compose/Render/Vercel manifests, workflows, environment examples, configuration,
README and deployment documentation. Inspected dirty status before editing.

Edits are confined to the assigned root manifests, `.github/`, `scripts/`, backend
configuration/environment examples, frontend environment/Vercel configuration,
deployment/README/contribution documentation and this requested new record.
Backend application/routes/tests/migrations and frontend application/tests were
not edited by this infrastructure task. Their concurrent changes were preserved;
test totals below reflect the shared worktree at execution time. No commits,
account deployment or real secrets were created.

## Delivered

| Phase / Requirement | Implementation | Evidence / Status |
|---|---|---|
| 4.1 deploy-on-merge / NF-11 | CI-gated Render native setting; guarded successful-main-CI hook workflow for Render/Vercel; stale SHA rejection; production environment | Files implemented; actual platform linkage/hooks/approvals remain external |
| Queue / F-IA-07 / NF-07 | PHP-FPM/nginx instead of PHP development server; independent database worker; timeout 900s, reservation 960s; after-commit queue; non-root supervised runtime | Real persisted SQLite maintenance job consumed by separate worker locally; production Docker/Postgres pending CI |
| Scheduler / 4.4 / RG-12 | Minute-aligned scheduler starts an actual artisan `schedule:run` process; shared lock/minute marker; heartbeat and critical-child failure shutdown | Actual subprocess executed local attempt-finalization command; backend owner supplies prune/finalize logic |
| 4.2 / NF-13 backup | Custom-format `pg_dump`, catalog validation, SHA-256, restricted files, TLS defaults, separate encrypted off-site backup workflow | Scripts and CI round-trip/safety test implemented; successful PostgreSQL restore NOT yet evidenced |
| 4.3 / NF-07 monitoring | `/ready` checks DB/scheduler/backlog/failed jobs; external GET monitor; expiry dates; optional Brevo aggregate bounce reporting; private CLI AI quota check | Local readiness succeeded; cloud monitors/provider APIs/alert recipients not live verified |
| Production configuration / NF-02 | Explicit HTTPS URLs; fail-closed frontend build URL; one canonical OAuth target plus CORS allowlist; secure cookies; S3/SMTP preflight; Entra/optional AI examples | Infrastructure regression tests and official schema checks passed |
| Security / CI | Blocking Composer/npm dependency audits; clean installs; SQLite/Postgres tests/migrations; browser/build; shell lint; container/queue/restore job | npm clean; Laravel dependency audit blocks release; hosted CI has NOT been run |
| 4.5 / NF-14 / T-24 | Real Chromium and Firefox browser history/logout smoke with a mocked API | 4 Playwright tests passed across two browsers |
| 4.5 / T-27 | 360px public login/privacy/denied deep links and horizontal-overflow checks | Passed in Chromium and Firefox; not a full authenticated student/device journey |
| 4.5 / NF-06 | Authorized k6 harness ramps to 200 distinct synthetic user tokens; p95 <3000ms and <1% failure thresholds | Harness implemented; no load run or 200-user performance claim |

## Actual Local Results

Windows host: PHP 8.5.1, Node 22.20.0, Composer, Git Bash. Target image/CI uses
PHP 8.4 (`Dockerfile` `php:8.4-fpm-alpine`, CI `php-version: '8.4'`; the 8.4.1 floor
comes from the locked Symfony 8.1 packages). Docker, native PostgreSQL clients,
`pdo_pgsql`, `pcntl`, ShellCheck and k6 were not available on the delivery host.

> The table below is a snapshot from an earlier pass. The current measured totals are
> in [`LOCAL_POLISH_REPORT.md`](LOCAL_POLISH_REPORT.md) (500 backend tests /
> 1770 assertions, 87 frontend tests, 30 Playwright tests) together with the list of
> checks that only CI can perform.

| Command / Check | Actual result |
|---|---|
| `npm ci --ignore-scripts` in `scripts` | Clean install succeeded; 0 npm advisories |
| `npm --prefix scripts test` | 8 tests passed: YAML parsing, production URL rejection, canonical OAuth/CORS, local defaults, Vercel URL guard, runtime wiring, manual recovery triggers, restore guard contracts |
| `node scripts/validate-schema.mjs` | Render and Vercel manifests passed official fetched schemas; Vercel's mixed draft-04/newer exclusive bounds were normalized equivalently for validation |
| Git Bash `bash -n` for each `scripts/*.sh` | Passed |
| PHP lint on owned configuration and infrastructure PHP scripts | Passed |
| `composer validate --strict --no-check-all` | Passed; `--no-check-all` excludes the existing exact-version style warning, not lock or security checks |
| `php -d error_reporting=22527 artisan test --compact` | 433 tests, 1403 assertions, no failures; all marked deprecated by PHP 8.5 dependency warnings |
| `php artisan schedule:list` | Digest 07:00 UTC; prune 03:00 UTC; finalization every minute; AI reset 00:05 UTC registered |
| `INFRA_TEMP=<approved temp> node scripts/sqlite-drill.mjs` | Passed: fresh real migration schema, job persisted, independent `queue:work database --once` consumed `PruneExpiredOtpCodes`, expired OTP removed, no failed job, schedule subprocess executed finalization, readiness OK |
| `npm test -- --run` in `frontend` | 44 tests passed at that execution; later agent tests may increase the total |
| `npm run build` in `frontend` | TypeScript and Vite production build passed |
| Playwright install Chromium/Firefox | Browser binaries installed successfully |
| `npm --prefix scripts run browser` | 4 passed: two scenarios per Chromium/Firefox; real browser history after logout and 360px public deep links |
| `npm audit --audit-level=high` in `frontend` | 0 vulnerabilities |
| `composer audit --locked --no-interaction` | FAILED: four advisories affecting pinned `laravel/framework` 11.57, including high-severity CRLF/email validation |
| `git diff --check` | Passed; Git emits existing Windows line-ending conversion warnings |

An initial full backend run observed one AI test failure while the backend agent
was changing its dispatch/fallback contract. The subsequent 433-test run passed.
An initial browser run failed on an incorrect harness assumption that the denied
page must contain the brand; the harness was corrected to assert its heading,
and both complete browser runs then passed. An initial queue fixture omitted
required `otp_codes.email`; the fixture and fail-fast handling were corrected,
and the successful run explicitly observed the worker's RUNNING/DONE output.
These are harness/concurrent-state observations, not hidden passing evidence.

## Restore Feasibility

Attempted a local disposable PostgreSQL drill using an npm-distributed PostgreSQL
18 server in the pre-approved temporary directory. Initialization/start worked,
but the package includes only server binaries, not `createdb`, `psql`, `pg_dump`
or `pg_restore`. The drill could not reach backup/restore. The temporary server
was stopped, and the reusable drill now checks the entire toolset before starting.
No real database was accessed. This attempt does **not** satisfy NF-13's "one
tested restore" criterion. CI contains the full PostgreSQL 16 application-schema
backup/restore and nonempty-target refusal drill, but must execute successfully
before that criterion can be closed. RTO remains unmeasured.

## Release Blockers

1. Backend dependency owner must remediate Laravel security advisories. No audit
   ignores or advisory waivers were added. Current strict security CI must stay red.
2. Run Linux hosted CI to validate the image build, nginx/FPM startup, PostgreSQL
   migrations/tests, shell lint and successful backup/restore. These were not
   locally verified and are not claimed complete.
3. Human approval is needed for an always-on host/budget: Render `1c-2g` is paid.
   A sleeping free web service cannot guarantee scheduler/worker execution.
4. Provision managed PostgreSQL, a private object bucket, a separate encrypted
   backup destination, lifecycle retention and provider-native quota/expiry alerts.
5. Configure actual HTTPS domains, platform Git integrations or deploy hooks,
   protected-main/production approvals, SMTP verified sender, Entra school consent,
   secrets and alert recipients. Verify real OAuth/OTP and private download/logout.
6. Enable and test uptime/Brevo/expiry monitoring only after external configuration.
   AI quota CLI checks exist; recurring host/provider alert setup is still human
   work. GitHub cron is best-effort, not an uptime SLA.
7. Run authorized 200-user staging load and browser full-page latency measurement
   on a representative network. Execute full synthetic student/teacher/admin
   acceptance and Safari/Edge/real-device checks. Mock browser smoke is narrower.
8. Test private object restoration alongside database restoration. Database dumps
   alone cannot protect uploaded resources/submissions. Decide legal retention
   and CNDP handling before using real student data.

For commands, environment variables, rollback/recovery, retention and external
configuration see `docs/DEPLOYMENT.md` section 9 (current authoritative runbook).
The repository is materially more deployment-ready, but **not certified as a
live production deployment or a fully accepted Phase 4 release**.
