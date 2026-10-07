# JIRA Board — ClassLink 1.0

Historical delivery board for ClassLink 1.0, not evidence of today's production
readiness. The combined school master prompt supersedes the single-teacher class
model. See [SCHOOL_MODEL_HANDOFF.md](SCHOOL_MODEL_HANDOFF.md) for the locally
implemented institutional model, tests, blockers and exact proposed CL update
titles. Jira itself has **not** been synchronized; no new issue IDs are invented.

Legend: ✅ Done · 🚧 Partial/Could-have · ⛔ Blocked by external dependency (documented, not hidden)

## Epic 1 — Foundations & Auth

| ID | Item | Type | Priority | Status | Notes |
|---|---|---|---|---|---|
| CL-1 | Laravel project scaffold (API) | Story | Must | ✅ local | Current lock: Laravel 12.69.3. Sanctum, Socialite, private flysystem storage. |
| CL-2 | React 19 + Vite + TS frontend | Story | Must | ✅ | ESM, Tailwind 4, i18n FR/EN. |
| CL-3 | Microsoft Entra ID OAuth (F-AUTH-01) | Story | Must | ✅ local / ⛔ live | State/replay protection, tenant + Graph object identity linking, single-use pending receipt, fragment token handoff. Real school-tenant testing pending; see OFPPT_MICROSOFT_AUTH.md. |
| CL-4 | OTP fallback login (F-AUTH-02) | Story | Must | ✅ | 6-digit, 10-min TTL, hashed at rest, rate-limited. |
| CL-5 | Dev login endpoint (§25) | Task | Must | ✅ | `POST /api/auth/dev/login`; refused in production. |
| CL-6 | Token TTL 8h, revoke on logout (T-23/T-25) | Story | Must | ✅ | Sanctum; explicit 401 `session_expired`. |
| CL-7 | Role detection RG-01/RG-02 server-side | Story | Must | ✅ local | Exact OFPPT domain; numeric local part is observed student convention (no length/birth-date inference); non-numeric is pending teacher candidate. Supersedes historical automatic-teacher interpretation. |

## Epic 2 — Classes & Memberships

| ID | Item | Type | Priority | Status | Notes |
|---|---|---|---|---|---|
| CL-10 | Class CRUD + join codes | Story | Must | ✅ | Teacher ownership; 8-char codes; toggle/regenerate. |
| CL-11 | Join request workflow (F-REQ-01..03) | Story | Must | ✅ | pending/accepted/rejected; 1 pending per class; 24h cooldown (T-07/08/09). |
| CL-12 | Accept / reject / accept-all (F-REQ-05/06) | Story | Must | ✅ | Notifications sent; 409 on duplicate. |
| CL-13 | Member list privacy (T-22) | Story | Must | ✅ | No emails to students; teacher/admin see emails per policy. |
| CL-14 | Remove member → immediate access cut (T-14) | Story | Must | ✅ | Attempts preserved. |
| CL-15 | CSV member import (F-REQ-09) | Story | Could | 🚧 | Implemented endpoint; UX polish optional. |

## Epic 3 — Content & Quizzes

| ID | Item | Type | Priority | Status | Notes |
|---|---|---|---|---|---|
| CL-20 | Materials upload/download (private) | Story | Must | ✅ | MIME whitelist, .exe refused (T-21); signed/authenticated download; audited. |
| CL-21 | Announcements + notifications | Story | Must | ✅ | Pinned ordering; unread badge; mark read. |
| CL-22 | Quiz creation (manual) | Story | Must | ✅ | Single/multiple/text; correct options required. |
| CL-23 | Quiz attempts (timer, max attempts) | Story | Must | ✅ | T-15 deadline; T-16 max attempts; auto-submit on expiry. |
| CL-24 | Auto-grading (T-17) | Story | Must | ✅ | Partial score; no answers leaked pre-submit. |
| CL-25 | Results view + CSV export | Story | Must | ✅ | Teacher/owner only; no emails in export. |
| CL-26 | Flashcards (F-QUI-08) | Story | Must | ✅ | Student study mode; drafts hidden; reviewed before publish. |

## Epic 4 — Assignments & Deadlines

| ID | Item | Type | Priority | Status | Notes |
|---|---|---|---|---|---|
| CL-30 | Assignment create/manage | Story | Must | ✅ | Teacher CRUD; due dates. |
| CL-31 | Student submission (F-DEV-02) | Story | Must | ✅ | One per student; late flagged; private storage. |
| CL-32 | Grading + feedback loop | Story | Must | ✅ | Teacher grade/feedback; student sees grade + downloads own file. |
| CL-33 | Deadline calendar (F-DEV-04) | Story | Must | ✅ | `GET /api/me/deadlines` aggregates assignments + quizzes. |

## Epic 5 — AI Generation

| ID | Item | Type | Priority | Status | Notes |
|---|---|---|---|---|---|
| CL-40 | AI quiz generation (F-AI-01) | Story | Must | ✅ | Abstracted providers (openai/groq/mistral); env keys only. |
| CL-41 | Provider fallback (T-18) | Story | Must | ✅ | 429/5xx → next provider; cooldown skip. |
| CL-42 | All-fail manual fallback (T-19) | Story | Must | ✅ | Clear error + `manual_fallback: true`; job marked. |
| CL-43 | Document cache (T-20) | Story | Must | ✅ | Same PDF → cached; no quota consumed. |
| CL-44 | Daily quota + reset | Story | Must | ✅ | Per teacher; reset on `resetDailyQuotas()`. |
| CL-45 | Review gate before publish | Story | Must | ✅ | AI drafts unreviewed; cannot publish until reviewed. |
| CL-46 | PDF text extraction | Task | Must | ⛔ | `smalot/pdf-parser` not installable in this env; explicit fallback implemented. See `docs/ASSUMPTIONS.md`. |

## Epic 6 — Administration & Audit

| ID | Item | Type | Priority | Status | Notes |
|---|---|---|---|---|---|
| CL-50 | User management (promote/deactivate) | Story | Must | ✅ local | Admin-only teacher approval/rejection, approved role locked; safe candidate/email/source/status UI. |
| CL-51 | Class transfer/archive | Story | Must | ✅ | Admin override. |
| CL-52 | AI provider config UI | Story | Must | ✅ | Priority/enabled/limits; secrets never returned. |
| CL-53 | Audit log (T-28) | Story | Must | ✅ local | Microsoft verification/candidate status, role approval and activation/rejection audited without provider identifiers/tokens. |
| CL-54 | Stats dashboard | Story | Should | ✅ | Admin overview counts. |

## Epic 7 — Profile, Partners, Privacy

| ID | Item | Type | Priority | Status | Notes |
|---|---|---|---|---|---|
| CL-60 | Profile edit (locale/avatar) | Story | Must | ✅ | FR/EN only; locale persisted (T-26). |
| CL-61 | Session revocation UI | Story | Must | ✅ | Destroy all sessions. |
| CL-62 | Partner matching | Story | Should | ✅ | Candidates w/o emails; request/respond flow. |
| CL-63 | Privacy page | Story | Must | ✅ | No PII collection statement. |

## Epic 8 — Delivery & Operations

| ID | Item | Type | Priority | Status | Notes |
|---|---|---|---|---|---|
| CL-70 | Backend test suite (T-01…T-28) | Task | Must | ✅ | 246 tests, 641 assertions, 0 failures. |
| CL-71 | Frontend tests + typecheck + build | Task | Must | ✅ | 31 tests; tsc clean; Vite build clean. |
| CL-72 | README + docs (API/Deployment/Assumptions) | Task | Must | ✅ | This folder + root README. |
| CL-73 | Docker + docker-compose | Task | Must | 🚧 | Root Dockerfile + compose, statically reviewed. Docker is not installed on the delivery machine: the build and the `/up` health check are executed by the `docker` CI job, not locally. |
| CL-74 | render.yaml + vercel.json | Task | Should | 🚧 | Render blueprint (DB linked via `fromDatabase`, `preDeployCommand` migrations) + SPA rewrites. Parses as valid YAML/JSON; not deployed, so no provider-side validation. |
| CL-75 | GitHub Actions CI | Task | Must | ✅ | `.github/workflows/ci.yml` (backend tests, syntax, Pint advisory, frontend types/tests/build, Docker build + health). |
| CL-76 | Scheduled digest + prune workflows | Task | Must | ✅ | Daily-digest 07:00, prune 03:00; secrets-based. |
| CL-77 | CORS hardening | Task | Must | ✅ | `config/cors.php`; origins from `FRONTEND_URL`; no wildcard. |
| CL-78 | `.gitignore` — no secret versioned | Task | Must | ✅ | Root `.gitignore` excludes `**/.env`, `*.pem`, `*.key`; keeps `.env.example`. |
| CL-79 | API JSON contract for non-browser clients | Bug | Must | ✅ | Unauthenticated API calls without `Accept: application/json` returned **500** (« Route [login] not defined ») instead of 401. Root cause: Laravel 11 registers `redirectGuestsTo(fn () => route('login'))` by default and this API has no `login` route. Fixed via `redirectGuestsTo` returning `null` for `/api/*` plus a `ForceJsonResponse` middleware. Covered by `ApiContractTest` (12 tests). |

## T-01…T-28 → test traceability

| Critère | Test(s) automatisé(s) | Fichier |
|---|---|---|
| T-01 | `test_t01_*` | `RoleDetectionTest.php` |
| T-02 | 2 tests | `RoleDetectionTest.php` |
| T-03 | 2 tests | `RoleDetectionTest.php` |
| T-04 | `test_t04_*` | `RoleDetectionTest.php` |
| T-05 | `test_t05_*` | `OtpLoginTest.php` |
| T-06 | 2 tests | `OtpLoginTest.php` |
| T-07 | `test_t07_*` | `MembershipTest.php` |
| T-08 | 2 tests | `MembershipTest.php` |
| T-09 | `test_t09_*` | `MembershipTest.php` |
| T-10 | 3 tests | `MembershipTest.php` |
| T-11 | 3 tests | `AuthorizationTest.php` |
| T-12 | `test_t12_*` | `AuthorizationTest.php` |
| T-13 | 4 tests | `AuthorizationTest.php` |
| T-14 | 3 tests | `AuthorizationTest.php` |
| T-15 | 2 tests | `QuizTest.php` |
| T-16 | 2 tests | `QuizTest.php` |
| T-17 | 4 tests | `QuizTest.php` |
| T-18 | 2 tests | `AiTest.php` |
| T-19 | 6 tests | `AiTest.php` |
| T-20 | 3 tests | `AiTest.php` |
| T-21 | 5 tests | `FileUploadTest.php` |
| T-22 | 4 tests | `AuthorizationTest.php` |
| T-23 | 2 tests (API) + 1 (`session-flow.test.tsx`) | `SessionTest.php`, `session-flow.test.tsx` |
| T-24 | 4 tests (`session-flow.test.tsx`) | `session-flow.test.tsx` — **automatise** ; reste la recette visuelle sur navigateur reel apres deploiement |
| T-25 | `test_t25_*` | `SessionTest.php` |
| T-26 | 10 tests + 6 tests (parité FR/EN) | `AuditAndLocaleTest.php`, `i18n-layout.test.tsx` |
| T-27 | 3 tests (`i18n-layout.test.tsx`) | `i18n-layout.test.tsx` — **automatise** ; reste la verification visuelle 360 px apres deploiement |
| T-28 | 4 tests | `AuditAndLocaleTest.php` |

## Verification Log (CL-80)

Full delivery verification run locally. Every row below was executed, not inferred.

| # | Check | Command / method | Result |
|---|---|---|---|
| 1 | Migrations applied | `artisan migrate:status` | 22/22 ran, single batch |
| 2 | Migrations from scratch | `artisan migrate:fresh` on empty DB | 28 tables created |
| 3 | Migrations reversible | `artisan migrate:reset` | back to 1 table (`migrations`) |
| 4 | Migrations re-runnable | `artisan migrate` after reset | 28 tables again |
| 5 | Seeder | `artisan db:seed` | 27 users, 4 classes, 6 quizzes, 6 assignments, 3 decks |
| 6 | Seeder idempotent | `db:seed` twice, compared row counts | identical — no duplicates |
| 7 | Scheduler registered | `artisan schedule:list` | digest 07:00, prune 03:00, AI quota 00:05 |
| 8 | `classlink:prune` | 3 expired + 1 live OTP, 1 expired + 1 live token | deleted exactly 4 expired, kept both live |
| 9 | `classlink:daily-digest` | `artisan classlink:daily-digest` | 22 emails queued (`MAIL_MAILER=log`) |
| 10 | `classlink:make-super-admin` | run twice, same email | idempotent, 1 account, 2 admins total |
| 11 | Production config | `config:cache` + `route:cache` + `view:cache` + `event:cache` with `APP_ENV=production` | all cached, no non-serialisable closure, no closure route |
| 12 | Prod config enforces security | dev login under the production cache | 404 (dev route absent) |
| 13 | Caches cleared | `artisan optimize:clear` | cache back to local, demo login 200 again |
| 14 | Frontend build | `npm run build` | 6 files, 551 KB, single-line minified, content-hashed |
| 15 | Built bundle serves | `vite preview` on 4173 | SPA fallback 200, hashed JS/CSS resolve |
| 16 | Manifests | PyYAML / JSON parse | 5 YAML + 3 JSON valid; CI jobs `backend, frontend, docker` |
| 17 | Cron in CI matches code | `daily-digest.yml`, `prune.yml` | `0 7 * * *` and `0 3 * * *` |
| 18 | Docker image build | `docker build` | **not run** — no Docker daemon on this machine |
| 19 | Docker build context | `.dockerignore` replayed against the tree | 244 files / 0.7 MB, no `.env`, no `vendor`, no `node_modules`, no SQLite |
| 20 | Backend tests | `artisan test` | **246 tests, 641 assertions, 0 failures** |
| 21 | PHP syntax | `php -l` over app/config/routes/database/bootstrap | 159 files, 0 errors |
| 22 | Frontend tests | `npm test --run` | **31/31 passed** (4 files) |
| 23 | Typecheck | `tsc -b --noEmit` | exit 0, no output |
| 24 | Source encoding | UTF-8 validation over 270 files | 0 invalid, 0 mojibake, 0 stray control chars |
| 25 | Traceability | T-NN labels extracted from test names | **28/28 criteria covered, 85 named tests** |
| 26 | Live stack on LAN | `http://192.168.11.102:5173` | SPA 200, admin login 200, `/api/me` `/api/classes` `/api/admin/stats` `/api/notifications` 200 |

### Not verified here

- `docker build` / `docker compose up` and the image `HEALTHCHECK` — no Docker daemon and no WSL
  distribution on this host. The build context itself was proven secret-free (row 19); the
  `infrastructure` CI job performs the real build and the runtime probes.
- Microsoft Entra ID, managed PostgreSQL, S3, Brevo and the AI providers — all need real credentials.
- Live AI model responses — `smalot/pdfparser` 2.12.5 is installed and locked, so PDF text/page
  extraction is proven locally; only the provider call itself needs real keys.
- Physical-device/Safari visual approval for T-24 and T-27 — the logic is automated
  (`session-flow.test.tsx`, `i18n-layout.test.tsx`) and Chromium/Firefox viewport checks run in
  Playwright (`scripts/browser/story-fixes.spec.mjs`, `responsive.spec.mjs`), but final sign-off on
  a real phone remains a manual step.

## Known Issues / Follow-ups (documented, non-blocking)

- **PDF parser dependency**: resolved. `smalot/pdfparser` 2.12.5 is installed and locked, and real text/page extraction is asserted by `backend/tests/Unit/PdfExtractionTest.php`. Only the live model call remains unverified.
- **PHP 8.5 local deprecations**: upstream Laravel/collision notices, not ClassLink code. Production targets PHP 8.4 (floor 8.4.1, set by the locked Symfony 8.1 packages). Not fixed (would require vendor changes); see `docs/ASSUMPTIONS.md`.
- **Laravel security advisories**: resolved. The app runs `laravel/framework` `^12.69.3`, the first 12.x line free of every advisory that affected 11.57, and `composer audit` is clean. The CRLF issue in the old 11.x default `email` rule was additionally mitigated in code with `email:rfc`.
- **External services not live-tested**: Microsoft Entra, managed DB, S3, Brevo, AI providers — require real credentials. Documented in `docs/ASSUMPTIONS.md`.
- **Docker image build not run locally**: no Docker daemon and no WSL distribution on this Windows host, so `docker compose up --build` could not be executed here. The `infrastructure` CI job is the verification point (image build, `docker compose config`, `/up` + `/ready` probes, queue probe, `schedule:run`, backup/restore round trip).
- **CSV import UX**: endpoint functional; bulk-import UX polish is optional (Could-have).
- **E2E browser tests**: shipped. Playwright runs in Chromium and Firefox (`npm --prefix scripts run browser`), including the mocked story regressions and the opt-in real-local-API suites. Physical-device/Safari visual approval and final client design sign-off remain manual steps.

---

*Board reflects the delivered ClassLink 1.0 state. No security/privacy behavior weakened; business rules unchanged; `design/` untouched.*
