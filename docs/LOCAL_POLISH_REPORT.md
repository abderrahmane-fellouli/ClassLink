# ClassLink — local polish and application-readiness report

This is a local engineering verification record, not deployment certification.
No production services, credentials, DNS, commit or push were configured by this pass.
Earlier completion reports are historical snapshots; this record supersedes their
frontend-size, mobile-navigation, missing PDF parser and OAuth-state statements, and
the totals recorded in "Final results" below (they now cover the later Jira
acceptance pass as well).

## Findings and fixes

| Finding | Root cause | Correction |
|---|---|---|
| Desktop appears zoomed | Global CSS overwrote every `text-xs`, `text-sm`, 9–11px label with 16px and flattened several heading sizes. Broad element selectors also distorted controls/icons. | Restore Tailwind typography hierarchy; 14px body, 12px metadata, 14px controls, bounded 1200px content, 248px fixed-height sidebar, consistent card/control tokens. No zoom/scale layout hack. |
| Mobile language cannot be selected reliably | Absolute dropdown below sidebar footer inside a scrolling dialog; crowded account row; API-only change with swallowed failure. | Native keyboard/touch select in mobile header and sidebar; account and language actions in separate rows; immediate local FR/EN change with visible account-save failure. |
| Mobile navigation overflow | Fixed 256px menu inside padded 320px dialog; global link/flex overrides; five enlarged bottom labels. | Fluid dialog content, dedicated compact bottom-label styles, safe-area clearance, explicit close, Escape/outside close and focus return. |
| Teacher content overflow | Long AI-review badge forced `nowrap`; invitation-code actions shared one inflexible row. | Responsive badge wrapping and invitation action wrapping. |
| Student has no visible accepted classes | Class API did not populate the `membership_status` consumed by ClassroomResource and the frontend filter. | Populate the requesting student's actual status; retain the accepted-membership UI/access filters. Count accepted members only. |
| Admin users crashes | `data` contained a Laravel paginator, not the array promised by the client contract. | Return its mapped collection and separate pagination metadata. |
| Flashcard review failure disappears | Study error swallowed; teacher study navigation submitted an unnecessary student review. | Visible retryable failure; teacher navigation without student mutation. No-op edits no longer certify AI review; UI uses server-reviewed state. |
| Join setting shows false success | Local enabled state changed before API acceptance, without showing errors. | Change state after confirmed success and show errors. |
| Dashboard labels completed attempts as upcoming | History list reused an upcoming heading and fabricated a one-answer count. | Recent-results heading and actual submission date. |
| OAuth handshake missing state security | Stateless Socialite disabled state checks without replacement. | 10-minute random browser-bound HttpOnly SameSite=Lax nonce, one-time atomic cache consumption, provider failure recovery. Sanctum API requests remain bearer-only; no session-auth rewrite. |
| Callback retains token while awaiting API | Fragment scrub occurred after validation/navigation and discarded router history state. | Scrub before network calls, preserve router history state, prevent duplicate adoption, show translated recovery links. |
| OTP reports success after SMTP failure | Delivery exception swallowed while the code remained valid. | Return localized 503, invalidate undelivered code, omit exception secrets; serialize code verification with row lock. |
| Email localization/security gaps | French OTP and digest subject; digest used raw notification types and logged SMTP exception messages. | FR/EN email copy, translated digest types/subject, app link, safe exception classification. Map legacy MAIL_ENCRYPTION to Laravel 12/Symfony STARTTLS requirements. |
| PDF-backed AI unavailable locally | Required parser missing; historical docs referenced incorrect package name. | Install/lock `smalot/pdfparser` 2.12.5 and test actual text/page extraction. AI provider calls still require external verification. |
| Runtime render failure exposes stack | Default React Router developer error UI. | Translated retry/home recovery screen without stack output. |

## Design direction

Read `design/` as a visual reference only. The Figma prototype has older
Fraunces/Outfit tokens, whereas the official specification/completion documents
establish Navy/Ocean/Sky/Ice/Cream and Poppins/Inter/JetBrains Mono. Preserve the
approved ClassLink identity; restore the prototype's density and layout hierarchy
without replacing the brand or rewriting screens. Filled SVG paths are rendered
as designed instead of being globally converted into inaccurate outlines.

All public, student, teacher and admin screens receive the shared primitives.
Specific fixes touch public navigation/auth, authenticated shell, dashboard,
flashcards, teacher class settings and profile preferences. Existing forms,
tables with contained scrolling, data boundaries, dialogs and destructive-action
confirmations remain in place. This is not a claim of pixel-perfect Figma matching.

## Feature status and evidence boundaries

| Feature | Classification | Evidence |
|---|---|---|
| Local demo login and role routing | VERIFIED WORKING | Real Laravel/SQLite login, profile and browser routes for student, teacher, admin. |
| FR/EN, mobile menu, bounded desktop layout | VERIFIED WORKING | Interaction tests and Chromium/Firefox viewport checks; screenshots of actual local dashboards. |
| Classes, joins, membership, announcements | VERIFIED WORKING (local application) | Backend feature tests and frontend interaction tests; actual class pages/tabs with local data. |
| Private local materials/submissions | VERIFIED WORKING (local application) | Upload/content-validation/authorization/download feature tests, multipart UI tests; live class/assignment screens. |
| Quiz creation/edit/publish/save/resume/score/expiry/results | VERIFIED WORKING (local application) | Backend grading/expiry/review/resume suites and frontend workflows; actual local editor, attempt entry and result routes. |
| Flashcards | VERIFIED WORKING (local application) | Backend CRUD/policy/review tests, UI study/edit tests, live deck routes. |
| Assignments/grading/deadlines/progression | VERIFIED WORKING (local application) | Backend and frontend suites and live pages. |
| Partners/privacy/notifications/admin | VERIFIED WORKING (local application) | Authorization/privacy/preferences tests and local browser routes including actual admin users. |
| Microsoft Entra login | IMPLEMENTED BUT EXTERNAL VERIFICATION REQUIRED | Real authorization-URL generation; mocked Graph profile/token exchange; state rejection/replay/provider-error tests. No live school tenant login. |
| Brevo SMTP delivery | IMPLEMENTED BUT EXTERNAL VERIFICATION REQUIRED | Transport/application config, localized Mail tests, delivery-failure behavior. No real SMTP transmission/inbox/domain verification. |
| AI providers | IMPLEMENTED BUT EXTERNAL VERIFICATION REQUIRED | Real PDF parsing plus queued-job/fallback/quota tests with fake provider HTTP. No live model requests. |
| Production PostgreSQL/S3/runtime | IMPLEMENTED BUT EXTERNAL VERIFICATION REQUIRED | Existing configuration; deliberately not provisioned or deployed in this pass. |
| Physical-device/Safari visual approval | PARTIAL | Desktop Chromium/Firefox with emulated viewports is covered; physical-device and final client design acceptance remain pending. |

Backend feature tests validate operations against an isolated database. Browser
responsive fixtures are explicitly mocked and are not external-integration proof.
The opt-in local API browser tests use real Laravel and seeded local data, browse
all role routes plus available class/quiz/deck/assignment pages/tabs, check page/API
errors and overflow at 360/1440px, and revoke their test tokens. Those route checks
are not a claim that every mutation was clicked end-to-end in a real browser.

## Microsoft configuration needed later (do not configure now)

- Register a **Web** application in the institution's actual Entra tenant.
- `AZURE_CLIENT_ID`: application/client ID.
- `AZURE_CLIENT_SECRET`: secret **value**, not the secret ID.
- `AZURE_TENANT_ID`: directory/tenant ID approved by school IT; use the school
  tenant rather than personal Microsoft accounts or a guessed tenant ID.
- `AZURE_REDIRECT_URI`: exact backend URL ending `/api/auth/microsoft/callback`.
  Local example: `http://127.0.0.1:8000/api/auth/microsoft/callback`; use a matching
  local `FRONTEND_URL` for frontend return. Production will require HTTPS later.
- The installed Azure provider requests Microsoft Graph **delegated `User.Read`**
  and maps `userPrincipalName` to email. It does not currently request an OIDC
  ID token; do not document `openid profile email` as the actual request scopes.
- School IT must approve app registration/access, tenant policy and consent as
  required. Account/domain and locked-role detection remain server-side.
- Logout revokes the current ClassLink token. It does not sign the user out of
  every Microsoft application or the Microsoft browser SSO session.
- OAuth nonce cookie is handshake-only; CORS credentials remain disabled for
  ordinary API calls. Missing credentials return a recoverable frontend error.

## Brevo configuration needed later (do not configure now)

Use a verified ClassLink/domain sender, never Gmail as the production sender.
The actual inbox recipient may be chosen separately, subject to ClassLink's
allowed school-email policy for OTP.

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=smtp-relay.brevo.com
MAIL_PORT=587
MAIL_ENCRYPTION=tls
MAIL_USERNAME=<YOUR_VALUE>
MAIL_PASSWORD=<YOUR_VALUE>
MAIL_FROM_ADDRESS=noreply@<YOUR_DOMAIN>
MAIL_FROM_NAME=ClassLink
```

`MAIL_PASSWORD` is a Brevo SMTP key, not an API key or account password. Verify
the sender/domain and Brevo-provided DNS records later. Actual mailbox arrival,
bounces, limits and TLS handshake are pending. OTP is sent synchronously so
delivery failure is observable; database worker is needed for asynchronous AI.
Daily digest execution requires a running scheduler. Local log mail is not proof
of email delivery; use demo roles to explore without external credentials.

## Local testing

From repository root on Windows, after README dependency/env/database setup:

```powershell
.\scripts\start-local.ps1 -BackgroundJobs
```

This opens persistent terminals for the API, Vite and optional queue/scheduler;
it does not seed, reset, deploy or overwrite environment files. Open
`http://127.0.0.1:5173/login` and choose a demo role. Keep those windows open.
If demo data is absent, `php artisan db:seed` is a deliberate **local-only** step
from `backend/`; never seed fictional users into production.

## Checks

Commands used during this pass (run from indicated directory):

- Root: `git status --short`, `git log --oneline -3`, `git diff --check`.
- Backend: `composer update smalot/pdfparser --with-dependencies --no-interaction`
  (download/lock succeeded; initial autoload timed out); completed with
  `composer dump-autoload --no-interaction`.
- Backend: `composer validate --strict --no-check-all`, `composer audit --no-interaction`,
  `php vendor/phpunit/phpunit/phpunit --no-progress`, `php artisan migrate:status`.
- Backend: focused `--filter 'MicrosoftRedirectTest|OtpLoginTest'` and Pint on
  modified PHP files only.
- Frontend: `npm test`, `npm run typecheck`, `npm run build`, `npm audit`.
- Scripts: `npm test`, `npm run browser`; local browser variants set
  `$env:CLASSLINK_LOCAL_API='http://127.0.0.1:8000'` before invoking Playwright.
- Browser widths: 320, 360, 375, 390, 430, 768, 1024, 1280, 1440, 1920.

Final measured totals are recorded below. No lint script is
defined in frontend/package.json. No production migration or destructive reset
was performed. Existing local migrations are all applied. Dependencies changed
only for the PDF parser; frontend lockfiles did not change.

### Final results

Re-measured after the Jira acceptance pass on Windows (PHP 8.5.1, Node 22):

| Check | Actual result |
|---|---|
| `php vendor/phpunit/phpunit/phpunit --no-progress` | 500 tests, 1770 assertions, zero failures/errors |
| Focused filter `JiraAcceptanceFixTest\|AiReviewGateTest\|AiTest` | 52 tests, 153 assertions, zero failures |
| `composer validate --strict --no-check-all` | `./composer.json is valid` |
| `composer audit --locked --no-interaction` | No security vulnerability advisories found |
| `composer install --no-dev --no-scripts --no-autoloader --prefer-dist` | 91 packages installed into a throwaway vendor dir; `composer check-platform-reqs --no-dev` reports every requirement satisfied |
| `composer update --lock --no-install` | `Nothing to modify in lock file` |
| PHP syntax lint over `backend/app, backend/config, backend/database, backend/routes, backend/tests` | No syntax errors |
| `npm test` (frontend) | 87 passed, 7 files |
| `npm run typecheck` | Passed |
| `npm run build` | Passed; 45 modules; main JS 255.98kB / 58.08kB gzip, CSS 36.08kB / 7.63kB gzip |
| `npm audit --audit-level=high` (frontend) | 0 vulnerabilities |
| `npm run browser` (Chromium + Firefox) | 30 passed, 4 skipped (the 4 opt-in real-local-API cases need `CLASSLINK_LOCAL_API`), 0 failed — stable across 3 consecutive runs |
| `npm test` (scripts, `node --test infra.test.mjs`) | 8 passed |
| `npm audit --audit-level=high` (scripts) | 0 vulnerabilities |
| `node scripts/validate-schema.mjs` | `render.yaml` and `frontend/vercel.json` official schema OK |
| `git diff --check` | Passed; Git emits repository LF/CRLF normalization warnings only |
| `git diff --cached --name-only` | Empty; nothing staged |
| `git ls-files -- '*.env' '*.pem' '*.key' '*.p12'` | Empty; no tracked private env/key files in these patterns |
| `docker compose up --build` (production image build) | **NOT RUN — BLOCKED**: this Windows host has no Docker engine and no WSL distribution. The image build is only exercised by the CI `infrastructure` job, which is the intended external verification point. |
| `php artisan migrate:status` | **NOT RUN**: the local `.env` points at a remote Aiven PostgreSQL instance whose credentials are not present on this machine, so the connection is refused. No migration was applied, rolled back or reset. |

No claim of live external OAuth/SMTP/S3/managed DB/AI verification. No benchmark
or physical-device claim. No production container was built, run or deployed from
this machine. Current browser screenshots are ignored local artifacts under
`artifacts/browser/`, not committed production assets.

## Jira acceptance pass

Fixes landed in this pass, each with a regression that fails without it:

| Story gap | Correction | Regression |
|---|---|---|
| Invitation code could not be copied on a real browser (copy path relied on a non-browser fallback) | `CopyButton` uses the async Clipboard API and confirms success only after the write resolves; failure stays visible and retryable | `scripts/browser/story-fixes.spec.mjs` reads back `navigator.clipboard` in Chromium at 360px |
| Student home lost the announcement feed and the full result history overflowed narrow phones | Home renders the accepted/active pinned-then-newest feed; the progression screen renders all history entries and each result link at 320px with no horizontal scroll | Same spec asserts both announcements, 8 quiz headings, 8 result links and `scrollWidth <= innerWidth` at two stages |
| Teacher progression showed nothing useful when no student had participated | The payload now carries `inactive_student_ids`/`inactive_students` so zero participation still lists members with 0% | Same spec asserts the inactive member name and the `0%` cell |
| AI generation could appear to stall between `queued`/`processing` and the terminal state | Polling advances through the intermediate state and always renders the terminal outcome (`Completed`, or the failure state with its manual-creation escape) | Two Playwright cases (`done`, `failed`) assert exactly 2 polls and the terminal UI; two Vitest cases in `frontend/tests/jira-fixes.test.tsx` cover the same lifecycle |
| Admin audit filters sent parameters the API never accepted | Category now maps to `action=` with the API's own vocabulary; the end date is sent as `to=` inclusive | Playwright case captures the real request query and asserts `action=class` plus `to=2026-10-01` |
| Logout raced the router, so back/forward could briefly restore a signed-out profile | Sign-out navigates first and defers `signOut()` in all four call sites (`AppShell`, `guards`, `PublicScreens`, `ProfileScreen`) | `scripts/browser/smoke.spec.mjs` polls the logout call and cleared `sessionStorage`, then `goBack()` and asserts the profile route is not restored |
| Browser regressions failed intermittently rather than on a real defect | The per-role responsive sweeps (10 widths × language switch × mobile menu) and the AI lifecycle cases were sharing Playwright's default 30s budget; under 6 parallel workers Firefox occasionally exceeded it | `test.slow()` on the responsive and AI lifecycle cases, an explicit 20s budget on the audit request poll, and a readiness assertion before the audit filter interaction |
| Material upload limit was ambiguous between MiB and KiB | Documentation now states the single resolved limit: 10 MiB = 10,485,760 bytes = 10,240 KiB, and `GET /api/me/announcements` scope is written down | `backend/tests/Feature/ProductionConfigTest.php`, `JiraAcceptanceFixTest.php` |
| Docs advertised a PHP floor the locked Symfony 8.1 dependencies cannot meet | `README.md` states `PHP 8.4.1+`, matching the Dockerfile and CI (`php-version: '8.4'`) | `composer check-platform-reqs --no-dev` plus the strict validate step |

### Changed files

`git status --porcelain` after this pass (includes both passes; still unstaged on
`main`, nothing staged, nothing committed or pushed):

Modified:

```text
.github/workflows/ci.yml
Dockerfile
README.md
backend/app/Http/Controllers/AdminController.php
backend/app/Http/Controllers/AuthController.php
backend/app/Http/Controllers/ClassroomController.php
backend/app/Http/Controllers/ContentController.php
backend/app/Http/Controllers/FlashcardController.php
backend/app/Http/Controllers/OtpController.php
backend/app/Http/Controllers/ProfileController.php
backend/app/Http/Controllers/QuizController.php
backend/app/Http/Resources/AnnouncementResource.php
backend/app/Models/Quiz.php
backend/app/Models/User.php
backend/app/Services/EmailDigestService.php
backend/app/Services/OtpService.php
backend/app/Services/SmalotPdfTextExtractor.php
backend/app/Support/RoleDetector.php
backend/composer.json
backend/composer.lock
backend/config/classlink.php
backend/config/mail.php
backend/database/factories/UserFactory.php
backend/database/seeders/DemoSeeder.php
backend/lang/en/api.php
backend/lang/fr/api.php
backend/routes/api.php
backend/tests/Feature/AiReviewGateTest.php
backend/tests/Feature/AiTest.php
backend/tests/Feature/ApiContractTest.php
backend/tests/Feature/MicrosoftRedirectTest.php
backend/tests/Feature/OtpLoginTest.php
backend/tests/Feature/ProductionConfigTest.php
backend/tests/Feature/QuizResumeAndFlashcardEditTest.php
backend/tests/Feature/RoleDetectionTest.php
backend/tests/Unit/DomainRulesTest.php
docs/API.md
docs/ASSUMPTIONS.md
docs/DEPLOYMENT.md
docs/INFRA_COMPLETION.md
docs/JIRA-BOARD.md
frontend/src/components/AppShell.tsx
frontend/src/components/UI.tsx
frontend/src/components/guards.tsx
frontend/src/context/AuthContext.tsx
frontend/src/i18n/en.ts
frontend/src/i18n/fr.ts
frontend/src/i18n/index.tsx
frontend/src/index.css
frontend/src/lib/endpoints.ts
frontend/src/lib/types.ts
frontend/src/router.tsx
frontend/src/screens/AdminScreens.tsx
frontend/src/screens/ProfileScreen.tsx
frontend/src/screens/PublicScreens.tsx
frontend/src/screens/StudentScreens.tsx
frontend/src/screens/TeacherClassScreens.tsx
frontend/src/screens/TeacherScreens.tsx
frontend/tests/frontend-flows.test.tsx
scripts/browser/smoke.spec.mjs
scripts/playwright.config.mjs
```

New:

```text
Jira.csv                                  (untracked Jira export, not part of the app)
backend/app/Services/MicrosoftAccountService.php
backend/app/Services/MicrosoftOrganizationResolver.php
backend/database/migrations/2026_10_04_000001_add_microsoft_identity_to_users.php
backend/database/migrations/2026_10_06_000001_add_quiz_editor_opening.php
backend/scripts/
backend/tests/Feature/JiraAcceptanceFixTest.php
backend/tests/Feature/MicrosoftOrganizationsTest.php
backend/tests/Feature/OfpptMicrosoftAccountTest.php
backend/tests/Unit/PdfExtractionTest.php
docs/LOCAL_POLISH_REPORT.md
docs/OFPPT_MICROSOFT_AUTH.md
frontend/src/components/CopyButton.tsx
frontend/src/screens/StudentProgressScreen.tsx
frontend/tests/jira-fixes.test.tsx
scripts/browser/local-settings.spec.mjs
scripts/browser/ofppt-verification.spec.mjs
scripts/browser/prepare-local.mjs
scripts/browser/responsive.spec.mjs
scripts/browser/story-fixes.spec.mjs
scripts/start-local.ps1
```

Deleted: none.

## Where verification must still happen (external)

These cannot be closed from a Windows workstation without a container runtime or
real third-party accounts, and no claim is made about them:

| Item | Why it is external | Verification point |
|---|---|---|
| Production image build (`Dockerfile`, `docker compose up --build`) | No Docker engine and no WSL distribution on this host | CI `infrastructure` job (`.github/workflows/ci.yml`), which also runs `docker compose config --quiet`, the `/up` + `/ready` probes, the queue probe, `schedule:run` and the backup/restore round trip |
| Live Microsoft Entra school-tenant login | Needs a real tenant and school IT approval | Manual QA with the values listed above |
| Real Brevo SMTP transmission, bounce, TLS | Needs a verified sender/domain and DNS | Manual QA |
| Live AI provider requests | Needs provider keys; only faked provider HTTP is tested here | Manual QA / staging |
| Managed PostgreSQL and S3 storage | Deliberately not provisioned | Staging/deploy pipeline (`.github/workflows/deploy.yml`) |
| PostgreSQL and shellcheck legs of the suites | Backend suite is verified locally on SQLite only; CI runs a `sqlite` + `pgsql` matrix and `shellcheck scripts/*.sh` | CI `backend` and `infrastructure` jobs |
