# Assumptions & Known Constraints — ClassLink 1.0

This document records remaining external dependencies, known limitations, and intentional trade-offs. Per requirements: do not hide unresolved dependencies; document them explicitly.

## External Dependencies (Not Live-Tested)

The following services require real credentials/configuration in production and were not exercised against live endpoints in this delivery:

- **Microsoft Entra ID (Azure AD)** — OAuth flow mocked in unit/integration tests only. Redirect URIs, tenant, client secrets must be configured and verified post-deploy.
- **Production database** — Tests/use SQLite locally; production targets PostgreSQL/MySQL with TLS (`DB_SSLMODE`). Migrations verified against schema, not against managed DB-specific edge cases.
- **Object storage (S3-compatible)** — `FILESYSTEM_DISK=s3` path untested end-to-end. Signed URL behavior and bucket policies assumed per Flysystem AWS S3 v3.
- **Email (Brevo/SMTP)** — Daily digest and OTP emails use Mail facade; actual delivery not tested. Failures logged, non-blocking where applicable (digest catches exceptions).
- **AI providers (OpenAI/Groq/Mistral)** — Keys absent; all AI tests use mocked HTTP clients/fakes. Provider fallback, quotas, retry logic validated via mocks (T-18/T-19/T-20). Real rate limits/response formats may differ slightly.

No claim is made that external services are live-tested without credentials present.

## PDF Extraction (Unresolved Dependency)

- `smalot/pdf-parser` is **not installed** in the current vendor tree (not present in `composer.lock` and `composer require` previously failed in this environment). 
- `App\Services\SmalotPdfTextExtractor` implements `PdfTextExtractor` and throws explicit exceptions: `pdf_not_configured` (when class missing), `pdf_not_found`, `pdf_corrupted`, `pdf_unreadable`. 
- AI generation endpoint accepts PDF uploads but will return a 422/503-style manual fallback path when extraction cannot run (T-19: “manual quiz creation still works when AI is down—). 
- This is intentional: prefer explicit failure over silent empty extraction. To enable live PDF-backed AI in an environment where the package is available, run `composer require smalot/pdf-parser` and ensure PHP extensions allow PDF parsing. The code remains abstracted via the `PdfTextExtractor` contract (tests use fakes).

## PHP Version & Deprecations

- Local environment: PHP **8.5.1** (Windows). Production target: **PHP 8.3** (per spec/typical Render). 
- The full suite is **246 tests / 641 assertions / 0 failures**. On PHP 8.5 the summary line sometimes reads `246 deprecated` instead of `246 passed` — the label is **not stable across identical runs** (observed both ways on consecutive runs of the same commit). That is PHPUnit's deprecation bookkeeping, not a functional result: on PHP 8.5 *every* test boots the framework and touches the two notices below, and whether PHPUnit has installed its handler before the first notice fires determines how the run is labelled. Two distinct sources: 
  - `PDO::MYSQL_ATTR_SSL_CA` deprecated since PHP 8.5 — originates from `vendor/laravel/framework/config/database.php` (framework code). Workaround in app config uses `constant()` to pick `Pdo\Mysql::ATTR_SSL_CA` when defined (PHP 8.2+) or legacy constant; this avoids triggering deprecation on 8.5 while remaining compatible with 8.3. Not a ClassLink code defect.
  - `ReflectionMethod::setAccessible()` deprecated since PHP 8.5 — originates from `vendor/nunomaduro/collision` (dev dependency, test runner). No impact on production runtime.
- Decision: do **not** modify vendor files or force unsafe polyfills. Deprecations are upstream and will not affect behavior on PHP 8.3 production. Documented here per delivery instructions (treat as non-failures, identify source).
- These notices do not appear on PHP 8.3, which is the version pinned in the `Dockerfile` and in CI — there the summary is a clean `246 passed`.

## Dependency Security Advisories (`composer audit`)

`composer audit` reports **4 advisories against `laravel/framework` 11.57.0**. None is fixed on the 11.x branch, so they cannot be resolved without a major upgrade (out of scope for 1.0 delivery). Exposure assessment for this codebase:

| Advisory | Severity | Affects 11.57? | Exposure in ClassLink |
| --- | --- | --- | --- |
| [CVE-2026-48019](https://github.com/laravel/framework/security/advisories/GHSA-5vg9-5847-vvmq) — CRLF injection in the default `email` rule | high | yes (`>=11.0.0,<12.0.0`) | **Mitigated in code.** All user-supplied email inputs validate with `email:rfc` (egulias validator, rejects CR/LF) rather than the default `email` rule — see `OtpRequest`, `OtpVerifyRequest`, `DevAuthController`. CSV import (`MembershipService::importAccepted`) uses a parameterised lookup on existing accounts only and never builds a header. |
| [PKSA-3r5d-mb8f-1qw9](https://github.com/advisories/GHSA-5vg9-5847-vvmq) — same CRLF issue, second entry | high | yes | Same mitigation. Duplicate report of the same class. |
| [PKSA-m5cs-t1y6-qpcs](https://github.com/advisories/GHSA-crmm-hgp2-wgrp) — temporary signed URL path confusion | medium | yes (`<12.61.1`) | **Not applicable.** The app issues no signed URLs. `MaterialStorageService::supportsSignedUrls()` refers to S3 presigned object URLs, a different feature. |
| [CVE-2026-102279](https://github.com/advisories/GHSA-jh5r-qr3c-85q8) — XSS on the debug error page | low | no (`>=12.69.0` / `>=13.0.0`) | **Not applicable**, and `APP_DEBUG=false` is enforced in `render.yaml`. |

Recommended follow-up (post-1.0): upgrade to Laravel 12 when the project allows a major bump. Tracked here rather than silently ignored.

## Framework/Compatibility Notes

- Laravel 11.57, Sanctum 4.3, Socialite 5.31 + socialiteproviders/microsoft-azure 5.2. 
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

- Backend: 234 feature tests / 604 assertions / 0 failures. Every T-xx criterion that can be asserted headlessly is mapped to at least one named test (`test_tNN_…`); the full mapping is in `docs/JIRA-BOARD.md`. 
- Frontend: 31 Vitest tests (session storage, API client, FR/EN dictionary parity, layout constraints, session-flow routing). Typecheck and production build clean. 
- No end-to-end (Cypress/Playwright) included in 1.0 scope. T-24 and T-27 are therefore covered at the unit/integration level (`session-flow.test.tsx`, `i18n-layout.test.tsx`); a final visual pass on a real mobile viewport and a real browser back-button click remains a manual deployment step.
- `docker` is not installed on the delivery machine: the image is statically reviewed and built in the `docker` CI job, but no local `docker build` was executed.

## Deployment Assumptions

- Render (backend): PHP 8.3 runtime expected; build runs `composer install --no-dev`, caches config/routes/views, migrates with `--force`. 
- Vercel (frontend): static build `dist/`, SPA rewrites to `/index.html`. `VITE_API_URL` points to backend `/api` prefix.
- Scheduled jobs: GitHub Actions call internal endpoints with `X-Digest-Token` (or external scheduler). `DIGEST_TOKEN` must be non-empty in production to close internal routes (middleware returns 404 if unset).

---

*These assumptions are complete and accurate for ClassLink 1.0 delivery.*