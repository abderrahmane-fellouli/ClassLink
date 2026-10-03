# Deployment Guide — ClassLink 1.0

This guide covers production deployment of the Laravel 12 backend (Render) and React/Vite frontend (Vercel), with environment configuration, CORS, storage, scheduled tasks, and verification steps. All secrets must be managed through platform secret stores (never committed).

> **2026-10-03 infrastructure revision:** the production runbook in section 9
> supersedes the original runtime, queue, scheduler, database, deployment and
> acceptance-baseline descriptions below. Earlier notes are retained to preserve
> the existing remediation record, not as current operating instructions.

## Architecture Overview

- Backend (API): Laravel 12, Sanctum bearer tokens, stateless API (`Authorization: Bearer ...`), CORS restricted to `FRONTEND_URL`. 
- Frontend (SPA): React 19 + Vite 8, served as static build; API calls use `VITE_API_URL` (or `/api` proxied in dev). Token stored in `sessionStorage` only.
- Auth: Microsoft Entra ID (OAuth 2.0) via `laravel/socialite` + `socialiteproviders/microsoft-azure`. OTP fallback. Dev login disabled in production.
- Files: private disk (`local` or S3-compatible). No public file serving; downloads are streamed by the authenticated route `GET /api/materials/{id}/download` after a policy check (RG-12). No temporary/signed URL is ever returned to the client.
- Scheduled tasks: GitHub Actions workflows call protected internal endpoints (`/api/internal/daily-digest`, `/api/internal/prune`, `/api/internal/ai-quota-reset`) with `X-Digest-Token`. `classlink:daily-digest`, `classlink:prune`, daily quota reset run via these endpoints/commands.

## 1. Backend (Render)

### Render Service

The delivered configuration uses the **Docker** runtime, so the root `Dockerfile` owns the build, the caches and the start command. `render.yaml` is the source of truth.

- Type: Web Service, `runtime: docker`, `dockerfilePath: ./Dockerfile`, `dockerContext: .`
- Build command: **none**. Render ignores `buildCommand` for Docker services. The image already runs `composer install --no-dev`, `dump-autoload --classmap-authoritative`, `package:discover` and `storage:link` at build time.
- Start command: **none**. The image `CMD` runs `config:cache && route:cache && view:cache && exec php -S 0.0.0.0:8000 -t public public/index.php`.
- Pre-deploy command: `php artisan migrate --force`. This is the correct hook for Docker services — migrations run **before** traffic is shifted to the new image, and all migrations are additive.
- `APP_KEY` is **not** generated during the build. `generateValue: true` in `render.yaml` makes Render create the secret; `key:generate` is deliberately excluded from the `Dockerfile` so no key ever lands in an image layer.

### Environment Variables (Render)
Set the following (required where marked):

| Variable | Required | Example | Notes |
|---|---|---|---|
| `APP_ENV` | yes | `production` | Must be `production` to disable `DEV_AUTH_ENABLED`. |
| `APP_DEBUG` | yes | `false` | Never `true` in production. |
| `APP_KEY` | yes | (generated) | `php artisan key:generate --show`. |
| `APP_URL` | yes | `https://api.classlink.ma` | Base URL of backend. |
| `FRONTEND_URL` | yes | `https://app.classlink.ma` | Comma-separated allowed origins if multiple (staging). Used for CORS + redirects. |
| `APP_LOCALE`/`APP_FALLBACK_LOCALE` | yes | `fr` | Supported locales FR/EN only. |
| `DB_CONNECTION` | yes | `pgsql`/`mysql`/`sqlite` | Production: managed Postgres/MySQL (Neon/Aiven/Supabase). |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | per DB | — | `render.yaml` links them to the declared database with `fromDatabase`, so nothing is written in the file. For an external database, replace those five with `sync: false` secrets. |
| `DB_SSLMODE` | as needed | `require` | For cloud Postgres requiring TLS. |
| `SESSION_DRIVER` | yes | `file`/`redis`/`database` | Stateless API; `file` fine for single instance. |
| `SESSION_LIFETIME` | yes | `480` | Minutes (8h). |
| `CACHE_STORE` | yes | `database`/`redis` | Prefer persistent cache for quotas. |
| `FILESYSTEM_DISK` | yes | `local` or `s3` | Private storage. If `s3`, set AWS_* below. |
| `QUEUE_CONNECTION` | yes | `sync` | Queues optional in 1.0; `sync` is deterministic. |
| `MAIL_MAILER` | yes | `log`/`smtp` | Laravel 12 ships `smtp`; **Brevo is used through it**, not via a custom mailer. `log` for a smoke test. |
| `MAIL_FROM_ADDRESS` | yes | `no-reply@classlink.ma` | Sender. |
| `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION` | if SMTP | — | Brevo: see §3. |
| `AZURE_CLIENT_ID`, `AZURE_CLIENT_SECRET`, `AZURE_TENANT_ID` | if MS Entra | — | `AZURE_REDIRECT_URI` must point to `https://api.classlink.ma/api/auth/microsoft/callback`. |
| `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET`, `AWS_ENDPOINT`, `AWS_USE_PATH_STYLE_ENDPOINT` | if S3 | — | S3-compatible (MinIO/R2) supported via `league/flysystem-aws-s3-v3`. Private bucket only. |
| `AI_PROVIDER_1_KEY`, `AI_PROVIDER_2_KEY`, `AI_PROVIDER_3_KEY` | optional | — | Stored in env only (never DB). Providers configured in `ai_providers` table (admin). |
| `AI_PROVIDER_*_BASE_URL`, `AI_PROVIDER_*_MODEL` | optional | — | Override endpoints/models. |
| `DEV_AUTH_ENABLED` | no | `false` | Must remain `false` in production (enforced: `enabled` only when `APP_ENV!==production`). |
| `DIGEST_TOKEN` | yes | (random 64+ chars) | Shared secret for GitHub Actions internal endpoints (`X-Digest-Token`). |
| `CORS_ALLOWED_ORIGIN_PATTERNS` | no | `^https://.*\.vercel\.app$` | Optional regex patterns for preview deployments. |

Notes:
- CORS origins are derived from `FRONTEND_URL` (comma-split). Configure `config/cors.php` is authoritative (`paths: ['api/*']`, `supports_credentials: false`). 
- OAuth redirect uses fragment `#token=...` to frontend routes `/auth/microsoft/callback`, `/denied`, `/pending` (must match `frontend/src/router.tsx`).
- Session: stateless API with Sanctum; no cookies required for SPA calls (Authorization header only).

## 2. Frontend (Vercel)

### Build
- Framework: Vite
- Build command: `npm run build` (runs `tsc -b && vite build`)
- Output directory: `dist/`
- Install command: `npm ci`

### Environment Variables (Vercel)
| Variable | Required | Example | Notes |
|---|---|---|---|
| `VITE_API_URL` | production | `https://api.classlink.ma/api` | Base API URL. In dev leave empty and use Vite proxy. Never include trailing slash inconsistently. |
| `VITE_API_PROXY` | dev only | `http://127.0.0.1:8000` | Used by Vite dev server proxy `/api`. |

SPA rewrites: `vercel.json` (see §4) must rewrite all non-file paths to `/index.html`.

## 3. Third-Party Services

### Microsoft Entra ID (Azure AD)
- App registration in tenant `ofppt-edu.ma` (per spec §17.3).
- Redirect URI: `https://api.classlink.ma/api/auth/microsoft/callback` (must match `AZURE_REDIRECT_URI`).
- Grant type: Authorization Code. No client credentials flow for user login.
- Scopes: `openid profile email` (default via Socialite). Email is used for role detection (domain + local part). 
- Secrets: `AZURE_CLIENT_ID`, `AZURE_CLIENT_SECRET`, `AZURE_TENANT_ID` in backend env.

### Brevo (Email) — Digest & OTP (recommended)

Brevo is reached through Laravel's standard `smtp` transport — there is no custom `brevo` mailer in this project:
- `MAIL_MAILER=smtp`
- `MAIL_HOST=smtp-relay.brevo.com`
- `MAIL_PORT=587` (TLS) or `465` (SSL)
- `MAIL_USERNAME=<brevo-login>` (often API key username or SMTP login)
- `MAIL_PASSWORD=<brevo-smtp-key>` (SMTP key, not account password)
- `MAIL_ENCRYPTION=tls`
- `MAIL_FROM_ADDRESS=no-reply@classlink.ma`, `MAIL_FROM_NAME=ClassLink`

Daily digest (F-NOT-02): triggered by GitHub Actions calling `POST /api/internal/daily-digest` with header `X-Digest-Token: <DIGEST_TOKEN>`. Command `php artisan classlink:daily-digest` also available.

### AI Providers (optional)
Three slots configured (`services.ai.openai`, `groq`, `mistral`) with keys in env only. Admin UI (`/app/admin/ai`) manages priority/enabled/daily limits; secrets never exposed. Generation is abstracted; PDF extraction requires `smalot/pdf-parser` (see Assumptions). On provider failure/cooldown/invalid JSON, system degrades to manual creation (T-18/T-19).

### S3-compatible Storage (optional)
Private bucket, never public. Downloads go through the authenticated route `GET /api/materials/{id}/download`, which streams the object from the private disk after the policy check — no presigned URL is issued to the browser. Set the `AWS_*` vars; `AWS_USE_PATH_STYLE_ENDPOINT` for MinIO/R2 as needed. `FILESYSTEM_DISK=s3`.

## 4. Manifests (provided in repo)

- Root `Dockerfile` — Laravel app image for Render/Fly/CI
- `docker-compose.yml` — local dev stack (app + optional db)
- `render.yaml` — Render service blueprint (optional)
- `frontend/vercel.json` — SPA rewrites + headers. Set the Vercel **root directory** to `frontend`, or the build will not find it.
- `.github/workflows/ci.yml` — CI (backend tests, syntax, Pint advisory, frontend types/tests/build, Docker build + health check)
- `.github/workflows/daily-digest.yml` — scheduled digest (calls internal endpoint)
- `.github/workflows/prune.yml` — scheduled prune (calls internal endpoint)

## 5. Pre-Deployment Verification

Backend:
```bash
cd backend
php artisan migrate:status
php artisan db:seed --class=DatabaseSeeder  # no-op in production; safe
php artisan classlink:prune --help
php artisan classlink:daily-digest --help
php artisan classlink:make-super-admin --help
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan test --no-coverage
```

Frontend:
```bash
cd frontend
npm ci
npx tsc -b --noEmit
npm test -- --run
npm run build
```

Security checks (recommended):
- `APP_DEBUG=false`, `APP_ENV=production`, `DEV_AUTH_ENABLED` false
- `DIGEST_TOKEN` non-empty, strong
- CORS: `FRONTEND_URL` whitelisted only
- No `.env` committed; secrets in platform
- Private storage, no public URLs for materials/submissions

## 6. Deployment Steps (Exact)

1. **Configure secrets** in Render and Vercel (env vars above). Generate `APP_KEY` and `DIGEST_TOKEN` (e.g. `openssl rand -base64 48`).
2. **Deploy backend to Render**: push to repo; Render runs build commands (migrate --force). Verify `/up` health endpoint returns 200.
3. **Promote super admin** (first login): `php artisan classlink:make-super-admin admin.classlink@ofppt-edu.ma --name="Admin ClassLink"`. Login via Microsoft (or OTP if configured). Role locked.
4. **Deploy frontend to Vercel**: set `VITE_API_URL=https://api.classlink.ma/api`. Apply `vercel.json` rewrites. Build passes.
5. **Smoke tests**: OAuth redirect lands on `/auth/microsoft/callback#token=...`; denied/pending routes correct; CORS preflight from frontend origin succeeds; authenticated download works (no token in URL); logout revokes token (T-23).
6. **Scheduled jobs**: configure GitHub Actions secrets (`CLASSLINK_API_URL`, `DIGEST_TOKEN`) and enable workflows (daily-digest 07:00, prune 03:00, ai-quota-reset 00:05). `CLASSLINK_API_URL` is the API base URL **without** the `/api` prefix, e.g. `https://classlink-api.onrender.com` — each workflow appends the full path (`/api/internal/...`) itself. Storing the `/api` suffix in the secret would produce `/api/api/internal/...` and every scheduled job would fail with 404. Alternatively call internal endpoints from an external scheduler with the same `X-Digest-Token` header.

## 7. Rollback & Troubleshooting

- CORS failures: confirm `FRONTEND_URL` includes exact origin (scheme+host+port), no trailing slash mismatch; check `config/cors.php` uses `classlink.allowed_origins`.
- OAuth 500 `Socialite Facade not found`: ensure `laravel/socialite` installed and imports use `Laravel\Socialite\Facades\Socialite` (Laravel 12). Azure provider registered via `SocialiteWasCalled` event.
- Fragment token lost: callback must redirect to `/auth/microsoft/callback#token=...` (not query string). Frontend reads hash.
- Pending vs denied: unknown format (@ofppt-edu.ma but not matching patterns) → pending screen; external domain → denied screen (§17.6).
- Deprecated notices: PHP 8.5 local deprecations in framework/collision (documented in `docs/ASSUMPTIONS.md`); production targets PHP 8.3 — safe to ignore in local test output.
- PDF extraction: if `smalot/pdf-parser` absent, AI PDF jobs return 422/503 with `pdf_not_configured` (manual fallback). Install package when PDF AI required: `composer require smalot/pdf-parser` (note: availability varies by environment).
- `composer audit` is clean. `laravel/framework` is pinned to `^12.69.3`, the first 12.x line free of every advisory that affected 11.57; the CI `audit` job fails the build on any new advisory and `block-insecure` is left enabled so Composer itself refuses an insecure resolution.
- Image fails to build with `composer: not found`: the Composer binary must be copied from the `vendor` stage into the PHP stage before `composer dump-autoload` (see `Dockerfile`).
- `/up` returns 500 in a container: with `DB_CONNECTION=sqlite`, the file named by `DB_DATABASE` must already exist (`touch /tmp/db.sqlite`).

## 8. Acceptance Baseline

- Backend: **450 tests / 1469 assertions / 0 failures**, every T-xx criterion mapped to at least one named `test_tNN_…` in `backend/tests/Feature/`.
- Frontend: **73 Vitest tests** plus a clean `tsc -b --noEmit` and `npm run build`. No E2E framework in 1.0 scope.
- T-24 (browser back after logout) and T-27 (no overflow on mobile) are automated at the unit/integration level only. A visual pass on a real 360 px viewport and a real back-button click should be done once after the first deployment.
- Docker build and health check are executed by the `docker` job in CI; they were not run locally (Docker absent on the delivery machine).

---

*This document reflects the delivered implementation. No external services are assumed live-tested without credentials/config present.*

## 9. Production Runbook (Current)

### Runtime and Budget

The image now runs nginx on port 8000, PHP-FPM, a real database queue worker and a
wall-clock aligned scheduler under `tini` and `scripts/runtime.sh`. It runs as
`www-data`, uses no public storage symlink and fails the container if any child
exits. The worker timeout is 900s; `DB_QUEUE_RETRY_AFTER=960` prevents another
worker reserving the same job while it is still executing. Jobs get one attempt;
investigate failures before retrying because AI generation may incur provider cost.
Render permits only a 300s shutdown grace, so an unusually long in-flight job may
be killed on rollout. Inspect AI job state and failed/reserved queue rows after
deployment; do not assume exactly-once provider requests or blindly replay jobs.

`render.yaml` targets one **paid, always-on 1c-2g** web instance and an externally
managed PostgreSQL database. This is explicitly **not a 0 MAD deployment**. Free
web service sleep cannot reliably execute minute-level expiry, midnight reset or
background work. A free demonstration is possible but is not reliable production.
Do not use an expiring free Render database for real student records. Provider
selection, price, region, retention, TLS and school authorization require human
approval. The specification's free budget and continuous background execution are
in tension; obtain approval for the paid baseline or a genuinely always-on host.

Migrations run before traffic via Render's `preDeployCommand` and idempotently at
container startup for Compose/other hosts. They never run at image build time.
No production seeding occurs. Back up first and review every migration for
compatibility with both old and new application versions; do not assume every
future migration is additive. Keep API replication at one until distributed
maintenance and migrations have been reviewed. A database lock and minute marker
prevent duplicate scheduler runs during rolling deployment overlap.

### Required Configuration

Use `backend/.env.production.example` as the complete backend reference, but
enter real values into platform secret stores. Examples intentionally have empty
keys and `.invalid` URLs and cannot start production unchanged.

- Generate a valid 32-byte Laravel key with `php artisan key:generate --show`.
  Keep it stable across releases and retain previous keys during rotation.
- `APP_URL` is the HTTPS API origin without `/api`. `FRONTEND_URL` is an explicit
  comma-separated HTTPS origin allowlist; its first entry is the OAuth redirect
  target. Missing, HTTP, localhost, wildcard and credential URLs fail closed.
- PostgreSQL: set `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`
  and `DB_SSLMODE=require` (prefer `verify-full` with provider CA configuration).
  Use a direct endpoint for migrations and backups, not a transaction pooler.
- Private S3: `FILESYSTEM_DISK=s3`, `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`,
  `AWS_DEFAULT_REGION`, `AWS_BUCKET`, optional HTTPS `AWS_ENDPOINT`, and
  `AWS_USE_PATH_STYLE_ENDPOINT` according to the provider. Deny public access;
  grant the API only required object operations. Use a separate backup bucket/key.
- Brevo SMTP: verify sender/domain and SPF/DKIM, set `MAIL_MAILER=smtp`, host
  `smtp-relay.brevo.com`, port 587, TLS, SMTP login/key and sender. SMTP keys are
  not API keys. The production preflight rejects log mail to avoid a fake OTP
  success. Check provider email limits and delivery/bounce events before launch.
- Entra: obtain school IT consent, register the tenant-specific app, set all
  `AZURE_*` values and the exact HTTPS callback ending
  `/api/auth/microsoft/callback`. Validate tenant, scopes, consent and actual login.
  SMTP OTP remains the fallback if Microsoft approval is not available.
- AI is optional: leave all keys empty and providers disabled until approved.
  Each of the three slots has a key, base URL and model. Enable/order providers
  in admin only after PDF extraction, privacy, model availability and quotas are
  verified. Missing PDF support must remain explicit manual fallback.
- `QUEUE_CONNECTION=database`, `CACHE_STORE=database`, secure database sessions,
  `APP_DEBUG=false`, `DEV_AUTH_ENABLED=false`, `LOG_CHANNEL=stderr`.
  Trust `*` proxies only behind Render's controlled ingress; restrict CIDRs on
  hosts that accept direct traffic. Do not enable broad Vercel preview regexes.

Vercel's root directory must be `frontend`, with access to files outside that
directory enabled so the shared build guard in `scripts/` is available. Set public
`VITE_API_URL=https://your-api-domain/api` before building. No secret belongs in
any `VITE_*` variable. `vercel.json` now refuses a missing/unsafe production API
URL before compilation, preserves SPA deep links and security headers.

### Deployment Gates

CI does clean Composer/npm installs, SQLite and PostgreSQL tests/migrations,
production frontend compilation, Chromium/Firefox smoke tests, infrastructure
tests, official manifest schemas, shell syntax/lint, Docker startup/readiness,
real queue consumption and a PostgreSQL backup/restore safety drill. Dependency
audits are blocking, not advisory. `laravel/framework` is pinned to `^12.69.3`
and `composer audit` reports no advisories, so a green release is currently
possible; the CI `audit` job keeps it that way.

Render's native auto-deploy waits for checks (`autoDeployTrigger: checksPass`).
For an explicit combined API/frontend hook rollout, set Render auto-deploy to
off and disable Vercel's automatic production Git rollout, then configure the
following GitHub `production` environment. Do not enable both rollout modes.

- Secrets: `RENDER_DEPLOY_HOOK`, `VERCEL_DEPLOY_HOOK`, each bound to `main`.
- Variables: `PRODUCTION_DEPLOY_ENABLED=true`, `CLASSLINK_API_URL` (no `/api`),
  `CLASSLINK_FRONTEND_URL`. Configure environment approval and protected `main`.
- `.github/workflows/deploy.yml` runs only after a successful push CI on `main`
  and rejects stale tested commits. Its manual dispatch does not bypass CI.
  Hooks acknowledge deployment requests; the follow-up availability check does
  **not** prove that the new release is live. Inspect each platform's deployment
  status and commit SHA before declaring rollout complete.

No platform integration, hook, account, live service or real login has been
configured by this implementation. Bootstrap admin with
`php artisan classlink:make-super-admin <approved-school-email>`, then verify the
actual login. Never seed fictitious accounts into the real production database.

### Scheduler and Recovery

The scheduler invokes an actual `php artisan schedule:run --no-interaction`
subprocess every wall-clock minute, with a shared database lock. It executes the
tasks registered by the backend owner in `backend/routes/console.php`, including
attempt finalization every minute, prune at 03:00 UTC, AI reset at 00:05 UTC and
digest at 07:00 UTC. Verify the current list with `php artisan schedule:list`.
This infrastructure owns execution, not application pruning/finalization logic.

The old digest/prune/reset workflows are now manual recovery actions only. They
have no cron or push trigger, avoiding duplicate emails and quota resets. Supply
`CLASSLINK_API_URL` and `DIGEST_TOKEN` secrets if these recovery endpoints are
needed. Do not retry a digest blindly after a transport timeout. Scheduled times
missed while offline are not automatically replayed; inspect logs and run the
specific maintenance command once after recovery.

Run `php infra/operations.php health` in the container. `/up` is Laravel liveness;
`/ready` additionally checks PostgreSQL, scheduler heartbeat (900s), jobs older
than 1800s and any `failed_jobs`. `/ready` emits only generic status, never
credentials or job payloads. Failed jobs keep readiness red until investigated.
An unhealthy Docker healthcheck alone does not restart Docker; child loss exits
the container and `restart: unless-stopped` restarts it, while Render observes
`/ready`. Alerting and operator response remain necessary for a wedged process.

### Monitoring

Enable `PRODUCTION_MONITORING_ENABLED=true` and set the API/frontend URL variables.
GitHub monitoring runs every 15 minutes; cron delivery can be delayed and is not
an SLA. Configure a dedicated uptime service with a 1-minute `/ready` check and
named email/on-call recipient for serious use. Enable GitHub Actions failure
notifications and test one controlled outage in staging.

- Optional `DATABASE_EXPIRES_AT`, `STORAGE_EXPIRES_AT`,
  `ENTRA_SECRET_EXPIRES_AT` ISO dates trigger failure within seven days.
- Optional `BREVO_MONITOR_API_KEY` (API key, not SMTP key) checks aggregated
  hard-bounce, blocked and complaint counters. Review quota/delivery dashboards
  and set provider-native notifications; this API integration is not live-tested.
- Run `php infra/operations.php capacity` via a private operator session/host cron
  to flag enabled AI providers at 90% daily quota. Configure provider-native quota
  alerts as well. No permanent admin bearer token or new unauthenticated metrics
  endpoint was added just for monitoring.
- Configure database capacity/expiry, object-storage usage/billing, Brevo daily
  email limit and Entra secret-expiry alerts in the chosen provider dashboards.
  Thresholds, recipients and retention are human configuration, not completed
  account setup. AI quota exhaustion must not disable manual quiz authoring.

Before a demo, call `node scripts/monitor.mjs` with configured URLs, wait for
readiness, open the frontend and exercise a synthetic login and private download.
On a sleeping free demonstration service allow several minutes to wake, and keep
screenshots/video as fallback. Do not advertise uptime or latency from this check.

### Backup and Restore

On an authorized host install PostgreSQL clients matching the server major version
or newer, Bash, `sha256sum`, and AWS CLI for off-site copying. Use libpq environment
variables or a private `PGPASSFILE`; never put passwords in process arguments.
The daily GitHub backup at 02:30 UTC requires `PRODUCTION_BACKUP_ENABLED=true`,
`BACKUP_PGHOST`, `BACKUP_PGPORT`, `BACKUP_PGDATABASE`, `BACKUP_PGUSER`,
`BACKUP_PGPASSWORD` secrets, `BACKUP_S3_ACCESS_KEY`/`BACKUP_S3_SECRET_KEY`, and
`BACKUP_S3_REGION`, `BACKUP_S3_URL`, optional `BACKUP_S3_ENDPOINT` variables.
Confirm hosted runner connectivity or use a private/self-hosted runner. The
workflow uploads encrypted dumps to a separate private bucket, never GitHub
artifacts, and cleans runner copies. Confirm the bucket supports requested SSE.

```bash
# Source: direct database endpoint, read-only dump permissions, TLS.
export PGHOST=your-direct-db-host PGPORT=5432 PGUSER=backup_operator
export PGDATABASE=classlink PGSSLMODE=require
export BACKUP_DIR=/private/existing/backups
bash scripts/backup.sh
# Target: NEW empty database; stop traffic and workers before any promotion.
export PGHOST=your-restore-host PGDATABASE=classlink_restore
export RESTORE_CONFIRM=classlink_restore
bash scripts/restore.sh /private/existing/backups/classlink-TIMESTAMP.dump
```

The backup is a custom-format consistent snapshot, verified with `pg_restore
--list` and SHA-256. Restore requires the checksum and matching target confirmation,
refuses nonempty public schemas, and uses a single transaction with exit-on-error.
It never drops or cleans an existing database. Only restore trusted dumps.

Retain at least 7 daily, 4 weekly and 3 monthly successful backups through a
provider lifecycle policy; confirm budget and legal retention first. Verify the
off-site copy before deleting local copies. Daily backups imply up to 24h data
loss (RPO); RTO is **unmeasured** until an operator times a full recovery. Store
APP_KEY history separately in a secret manager. Database dumps do not back up
S3 objects: enable object versioning/retention and test restoring a private object
with its matching database reference. Preserve audit/results retention and CNDP
requirements; do not delete user data solely to satisfy storage quotas.

After restore compare migration counts, user/class/quiz/attempt counts and sample
relationships, verify private object downloads and authentication, then switch
secrets/traffic to the restored database, restart processes and re-enable alerts.
Revoke restored access tokens before reopening if restoring after a security
incident. Roll back application releases to a compatible prior image, not blindly
with `migrate:rollback`; destructive database restoration needs explicit approval.
Record evidence in `docs/INFRA_COMPLETION.md` or a dated operations record.

### Local Verification and Remaining Acceptance

```bash
# backend/.env has a generated APP_KEY; Compose owns PostgreSQL locally.
docker compose --env-file backend/.env up --build --wait
docker compose --env-file backend/.env exec app php artisan db:seed
# Shared infra checks and two actual browsers, without frontend app modifications.
npm --prefix scripts ci
npm --prefix scripts test
node scripts/validate-schema.mjs
npm --prefix frontend run build
npm --prefix scripts exec -- playwright install chromium firefox
npm --prefix scripts run browser
```

`scripts/sqlite-drill.mjs` runs migrations, a persisted maintenance job in an
independent worker, scheduler subprocess and readiness against an isolated temp
database (`INFRA_TEMP` required). `scripts/restore-drill.mjs` runs a synthetic
PostgreSQL backup/restore when a full `PG_BIN` toolset is available; CI additionally
tests the actual application migration schema on PostgreSQL 16.

Browser smoke uses real Chromium/Firefox history and 360px public deep links with
a mocked authenticated API. It is not a real Entra/OTP/private-download journey
or a full mobile/student acceptance pass. Safari/Edge and real device testing
remain human acceptance. `scripts/load.js` is an authorized staging-only k6 harness
for 200 distinct synthetic tokens, with p95 <3s and <1% failures. Set
`LOAD_TEST_APPROVED=true`, `CLASSLINK_API_URL` and `CLASSLINK_LOAD_TOKENS` securely,
then run `k6 run scripts/load.js`. Never run against real school accounts or an
unapproved live service. API timing is not full-page timing; measure browser page
loads on a representative connection separately. No 200-user performance claim
is valid until those runs are recorded.
