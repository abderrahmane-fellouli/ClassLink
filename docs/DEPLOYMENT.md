# Deployment Guide — ClassLink 1.0

This guide covers production deployment of the Laravel 11 backend (Render) and React/Vite frontend (Vercel), with environment configuration, CORS, storage, scheduled tasks, and verification steps. All secrets must be managed through platform secret stores (never committed).

## Architecture Overview

- Backend (API): Laravel 11, Sanctum bearer tokens, stateless API (`Authorization: Bearer ...`), CORS restricted to `FRONTEND_URL`. 
- Frontend (SPA): React 19 + Vite 8, served as static build; API calls use `VITE_API_URL` (or `/api` proxied in dev). Token stored in `sessionStorage` only.
- Auth: Microsoft Entra ID (OAuth 2.0) via `laravel/socialite` + `socialiteproviders/microsoft-azure`. OTP fallback. Dev login disabled in production.
- Files: private disk (`local` or S3-compatible). No public file serving; downloads are streamed by the authenticated route `GET /api/materials/{id}/download` after a policy check (RG-12). No temporary/signed URL is ever returned to the client.
- Scheduled tasks: GitHub Actions workflows call protected internal endpoints (`/internal/daily-digest`, `/internal/prune`) with `X-Digest-Token`. `classlink:daily-digest`, `classlink:prune`, daily quota reset run via these endpoints/commands.

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
| `MAIL_MAILER` | yes | `log`/`smtp` | Laravel 11 ships `smtp`; **Brevo is used through it**, not via a custom mailer. `log` for a smoke test. |
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

Daily digest (F-NOT-02): triggered by GitHub Actions calling `POST /internal/daily-digest` with header `X-Digest-Token: <DIGEST_TOKEN>`. Command `php artisan classlink:daily-digest` also available.

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
6. **Scheduled jobs**: configure GitHub Actions secrets (`CLASSLINK_API_URL`, `DIGEST_TOKEN`) and enable workflows (daily-digest 07:00, prune 03:00). `CLASSLINK_API_URL` is the API base URL **including** the `/api` prefix, e.g. `https://classlink-api.onrender.com/api`. Alternatively call internal endpoints from an external scheduler with the same `X-Digest-Token` header.

## 7. Rollback & Troubleshooting

- CORS failures: confirm `FRONTEND_URL` includes exact origin (scheme+host+port), no trailing slash mismatch; check `config/cors.php` uses `classlink.allowed_origins`.
- OAuth 500 `Socialite Facade not found`: ensure `laravel/socialite` installed and imports use `Laravel\Socialite\Facades\Socialite` (Laravel 11). Azure provider registered via `SocialiteWasCalled` event.
- Fragment token lost: callback must redirect to `/auth/microsoft/callback#token=...` (not query string). Frontend reads hash.
- Pending vs denied: unknown format (@ofppt-edu.ma but not matching patterns) → pending screen; external domain → denied screen (§17.6).
- Deprecated notices: PHP 8.5 local deprecations in framework/collision (documented in `docs/ASSUMPTIONS.md`); production targets PHP 8.3 — safe to ignore in local test output.
- PDF extraction: if `smalot/pdf-parser` absent, AI PDF jobs return 422/503 with `pdf_not_configured` (manual fallback). Install package when PDF AI required: `composer require smalot/pdf-parser` (note: availability varies by environment).
- `composer audit` reports 4 advisories on `laravel/framework` 11.57, none fixed on the 11.x branch. Exposure and mitigations are detailed in `docs/ASSUMPTIONS.md`; no code path uses the affected feature unmitigated. Plan a Laravel 12 upgrade post-1.0.
- Image fails to build with `composer: not found`: the Composer binary must be copied from the `vendor` stage into the PHP stage before `composer dump-autoload` (see `Dockerfile`).
- `/up` returns 500 in a container: with `DB_CONNECTION=sqlite`, the file named by `DB_DATABASE` must already exist (`touch /tmp/db.sqlite`).

## 8. Acceptance Baseline

- Backend: **246 tests / 641 assertions / 0 failures**, every T-xx criterion mapped to at least one named `test_tNN_…` in `backend/tests/Feature/`.
- Frontend: **31 Vitest tests** plus a clean `tsc -b --noEmit` and `npm run build`. No E2E framework in 1.0 scope.
- T-24 (browser back after logout) and T-27 (no overflow on mobile) are automated at the unit/integration level only. A visual pass on a real 360 px viewport and a real back-button click should be done once after the first deployment.
- Docker build and health check are executed by the `docker` job in CI; they were not run locally (Docker absent on the delivery machine).

---

*This document reflects the delivered implementation. No external services are assumed live-tested without credentials/config present.*