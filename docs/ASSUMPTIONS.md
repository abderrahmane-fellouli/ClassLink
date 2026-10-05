# Assumptions & Known Constraints — ClassLink 1.0

This document records remaining external dependencies, known limitations, and intentional trade-offs. Per requirements: do not hide unresolved dependencies; document them explicitly.

> Current OFPPT policy: exact `ofppt-edu.ma` domain, numeric observed student
> candidates, non-numeric pending teacher candidates requiring admin approval.
> Durable Microsoft identity uses tenant + Graph object ID, never inferred birth
> dates or email alone. See [OFPPT_MICROSOFT_AUTH.md](OFPPT_MICROSOFT_AUTH.md).
> Real school-tenant testing is pending; older automatic-teacher notes are superseded.

> Historical delivery notes below contain old test counts and runtime assumptions.
> The current local findings and verification boundaries are in
> [LOCAL_POLISH_REPORT.md](LOCAL_POLISH_REPORT.md). In particular, the PDF parser
> is now installed, OAuth has browser-bound state validation, browser checks run in
> Chromium/Firefox, and the minimum PHP version is 8.4.1. External
> credentials/integrations and the Docker image build are still pending.

## External Dependencies (Not Live-Tested)

The following services require real credentials/configuration in production and were not exercised against live endpoints in this delivery:

- **Microsoft Entra ID (Azure AD)** — OAuth flow mocked in unit/integration tests only. Redirect URIs, tenant, client secrets must be configured and verified post-deploy.
- **Production database** — Tests/use SQLite locally; production targets PostgreSQL/MySQL with TLS (`DB_SSLMODE`). Migrations verified against schema, not against managed DB-specific edge cases.
- **Object storage (S3-compatible)** — `FILESYSTEM_DISK=s3` path untested end-to-end. Signed URL behavior and bucket policies assumed per Flysystem AWS S3 v3.
- **Email (Brevo/SMTP)** — Daily digest and OTP emails use Mail facade; actual delivery not tested. Failures logged, non-blocking where applicable (digest catches exceptions).
- **AI providers (OpenAI/Groq/Mistral)** — Keys absent; all AI tests use mocked HTTP clients/fakes. Provider fallback, quotas, retry logic validated via mocks (T-18/T-19/T-20). Real rate limits/response formats may differ slightly.

No claim is made that external services are live-tested without credentials present.

## PDF Extraction (Resolved)

- `smalot/pdfparser` **2.12.5** is installed and locked in `backend/composer.lock`, so
  PDF-backed AI generation works locally. `App\Services\SmalotPdfTextExtractor`
  implements `PdfTextExtractor` and still raises explicit exceptions — `pdf_not_found`,
  `pdf_corrupted`, `pdf_unreadable` — instead of silently returning empty text.
- Real text/page extraction is asserted by `backend/tests/Unit/PdfExtractionTest.php`.
- The manual quiz-creation fallback remains the documented behaviour when extraction
  fails or the AI provider is down (T-19).
- Remaining external limitation: the *model* response still requires live provider
  credentials, so only the parsing half of the pipeline is proven locally.

## PHP Version & Deprecations

- Minimum supported PHP is **8.4.1**, matching `Dockerfile`, `.github/workflows/ci.yml`
  (`php-version: '8.4'`) and the README badge. Local development runs PHP **8.5.1**
  (Windows). The floor is 8.4 rather than 8.3 because the locked Symfony 8.1
  dependencies require it; `composer check-platform-reqs --no-dev` is part of the
  verification record.
- The full local suite is **500 tests / 1770 assertions / 0 failures** on SQLite.
  CI additionally runs the same suite against PostgreSQL.
- On PHP 8.5 two upstream deprecation notices can appear in the summary line:
  - `PDO::MYSQL_ATTR_SSL_CA` deprecated since PHP 8.5 — originates from
    `vendor/laravel/framework/config/database.php` (framework code). App config uses
    `constant()` to prefer `Pdo\Mysql::ATTR_SSL_CA` when available, which avoids the
    notice while staying 8.4-compatible.
  - `ReflectionMethod::setAccessible()` deprecated since PHP 8.5 — originates from
    `vendor/nunomaduro/collision` (dev dependency, test runner only).
- Neither notice affects the 8.4 runtime used by Docker and CI. Vendor files are not
  modified and no unsafe polyfills are forced.

## Dependency Security Advisories (`composer audit`)

`composer audit` reports **no advisories**. `laravel/framework` is pinned to `^12.69.3`, which includes all fixes for vulnerabilities that affected 11.57. Exposure assessment for this codebase:

| Advisory | Severity | Affects framework? | Exposure in ClassLink |
| --- | --- | --- | --- |
| [CVE-2026-48019](https://github.com/laravel/framework/security/advisories/GHSA-5vg9-5847-vvmq) — CRLF injection in the default `email` rule | high | yes (`>=11.0.0,<12.0.0`) | **Mitigated in code.** All user-supplied email inputs validate with `email:rfc` (egulias validator, rejects CR/LF) rather than the default `email` rule — see `OtpRequest`, `OtpVerifyRequest`, `DevAuthController`. CSV import (`MembershipService::importAccepted`) uses a parameterised lookup on existing accounts only and never builds a header. |
| [PKSA-3r5d-mb8f-1qw9](https://github.com/advisories/GHSA-5vg9-5847-vvmq) — same CRLF issue, second entry | high | yes | Same mitigation. Duplicate report of the same class. |
| [PKSA-m5cs-t1y6-qpcs](https://github.com/advisories/GHSA-crmm-hgp2-wgrp) — temporary signed URL path confusion | medium | yes (`<12.61.1`) | **Not applicable.** The app issues no signed URLs. `MaterialStorageService::supportsSignedUrls()` refers to S3 presigned object URLs, a different feature. |
| [CVE-2026-102279](https://github.com/advisories/GHSA-jh5r-qr3c-85q8) — XSS on the debug error page | low | no (`>=12.69.0` / `>=13.0.0`) | **Not applicable**, and `APP_DEBUG=false` is enforced in `render.yaml`. |

No follow-up required for v1.0: Laravel 12 is already in use.

## Framework/Compatibility Notes

- Laravel 12.69.3, Sanctum 4.3, Socialite 5.31 + socialiteproviders/microsoft-azure 5.2. 
- Frontend: React 19, Vite 8, Vitest 5, Tailwind CSS 4. ESM throughout (`import.meta.url`, `fileURLToPath`). 
- CORS: Restricted to `FRONTEND_URL` origins (comma-split). `config/cors.php` reads from `classlink.allowed_origins`. `supports_credentials=false` (stateless Bearer tokens). Optional `CORS_ALLOWED_ORIGIN_PATTERNS` for preview subdomains.
- OAuth redirects: Use fragment `#token=...` to frontend routes `/auth/microsoft/callback`, `/denied`, `/pending`. These paths must remain synchronized with `frontend/src/router.tsx`.
- Session: Sanctum personal access tokens (8h). `sessionStorage` only on frontend; never in URL. Logout revokes current token (T-23).

## Data & Demo

- Seeders: `DatabaseSeeder` no-ops in `production` environment (warns). Demo data uses OFPPT-like test emails (`@ofppt-edu.ma`) with fictional names (no real PII). 
- Migrations: include all tables (classes, memberships, materials, quizzes, attempts, flashcards, assignments, submissions, AI jobs/providers, audit logs, OTP). 
- Audit logs: immutable by design (append-only). No emails stored.

## Security/Privacy Constraints (Preserved)

- No passwords, password reset, birth dates, or extra session-management UI beyond revocation. 
- Student emails never exposed in member lists/partner candidates/unauthorized exports (T-22). 
- Private files: authenticated downloads only; no public links. 
- AI jobs: asynchronous, draft/unreviewed by default, require teacher review before publication. 
- Role detection RG-01/RG-02 enforced server-side only; never accepted from client. 
- `DEV_AUTH_ENABLED` strictly disabled in production.

## Testing Scope

Measured totals for the current local pass (PHP 8.5.1, SQLite, Node 22):

| Suite | Result |
| --- | --- |
| Backend PHPUnit | 500 tests / 1770 assertions / 0 failures |
| Frontend Vitest | 87 tests / 7 files / 0 failures |
| Scripts `node --test` | 8 passed |
| Playwright (Chromium + Firefox) | 30 passed, 4 skipped, 0 failed |

- Every T-xx criterion that can be asserted headlessly is mapped to at least one named
  test; the full mapping is in `docs/JIRA-BOARD.md`.
- The 4 skipped Playwright cases are the opt-in real-local-API suites
  (`responsive.spec.mjs` route sweep and `local-settings.spec.mjs`), which require
  `CLASSLINK_LOCAL_API` pointing at a running, seeded Laravel instance.
- Browser coverage is real Chromium and Firefox at 320–1920px, driven by mocked API
  fixtures plus the opt-in local-API suites. Physical-device/Safari visual approval
  and final client design sign-off remain manual steps.
- The `infrastructure` CI job is the verification point for anything Docker: the
  production image build, `docker compose config`, health/readiness probes, the queue
  probe, `schedule:run`, and the backup/restore round trip.
- The backend matrix also runs against PostgreSQL and `shellcheck scripts/*.sh`;
  only the SQLite leg was executed on this machine.

## Deployment Assumptions

- Render (backend): PHP 8.4 runtime expected; build runs `composer install --no-dev`,
  caches config/routes/views, migrates with `--force`.
- Vercel (frontend): static build `dist/`, SPA rewrites to `/index.html`. `VITE_API_URL` points to backend `/api` prefix.
- Scheduled jobs: GitHub Actions call internal endpoints with `X-Digest-Token` (or external scheduler). `DIGEST_TOKEN` must be non-empty in production to close internal routes (middleware returns 404 if unset).

---

*These assumptions are complete and accurate for ClassLink 1.0 delivery.*
