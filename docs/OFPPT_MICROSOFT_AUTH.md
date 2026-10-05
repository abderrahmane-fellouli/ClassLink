# OFPPT Microsoft identity verification and ClassLink authorization

## Status and policy

**IMPLEMENTED BUT EXTERNAL VERIFICATION REQUIRED.** Application tests use the real
Azure authorization URL and mocked authenticated Graph profiles. No live OFPPT
Entra login or production configuration was performed.

The currently approved domain is exactly **ofppt-edu.ma**, case-insensitive.
Lookalikes, suffixes, personal accounts, other organizations, malformed/empty
addresses and missing/invalid immutable identity information are denied safely.
Email entered by the frontend never establishes Microsoft verification.

Numeric local parts (`^[0-9]+$`) are the **currently observed** stagiaire account
convention, not a guaranteed official OFPPT rule. There is no exact-length
assumption. ClassLink never extracts, stores or displays an inferred birth date
from the identifier. The school address remains contact/account information
visible only in existing authorized contexts.

Every valid non-numeric OFPPT address is a **teacher/formateur candidate**, not an
automatically trusted teacher: `role=pending`, `role_candidate=teacher`.
The existing **admin** role is ClassLink's super-admin role. Only its authenticated
backend operation can approve teacher privileges. This requested conservative
policy supersedes the historical automatic-teacher specification interpretation.

## Architecture

1. Existing Azure Socialite driver exchanges the OAuth code and retrieves Graph
   `/v1.0/me`, retaining browser-bound, expiring, single-use OAuth state validation.
2. `MicrosoftAccountService` reads Graph `getId()` and `getEmail()` (the installed
   provider maps `userPrincipalName`), never client-supplied identity information.
3. Graph `/me` normally supplies no `tid`. With `AZURE_TENANT_ID=organizations`,
   `MicrosoftOrganizationResolver` retrieves the actual tenant GUID and verified
   domains from authenticated Graph `/organization`, using the same server-obtained
   access token. That directory must own the exact OFPPT domain. `User.Read` supports
   these organization fields; no unvalidated JWT decoding or tenant inference from
   email is used. A failed directory lookup fails closed. Fixed tenant GUID mode
   remains supported; `common` (personal-account authority) is not enabled.
4. Persist unique `(microsoft_tenant_id, microsoft_object_id)` and
   `microsoft_verified_at`, not provider tokens or raw Graph metadata. Fields are
   server-controlled and hidden from ordinary model serialization.
5. Classify authorization separately. Numeric candidates enter the student flow;
   non-numeric candidates await approval. Explicit admin-approved roles
   (`role_locked=true`) survive reauthentication.
6. Authorized users receive existing Sanctum bearer tokens. Pending users receive
   **no app token**. `/pending#verification=<receipt>` carries a random 10-minute,
   single-use receipt exchanged through `POST /api/auth/microsoft/pending-verification`.
   It returns only source/candidate/status, not provider IDs or a token. The fragment
   is cleared immediately. Forged/expired receipts show generic pending copy.

Email OTP remains the fallback, but cannot populate Microsoft verification fields
or automatically grant teacher privileges. New non-numeric OTP users also require
approval and appear as **Microsoft identity not verified**. Server middleware and
policies remain authoritative, independently of frontend state.

## Backward compatibility and collision handling

Migration: `2026_10_04_000001_add_microsoft_identity_to_users.php`.

- Nullable tenant/object/verified timestamp/candidate columns, composite unique
  identity constraint and candidate index; old users may remain unlinked.
- Matching unlinked school-email accounts link **once**, only after Microsoft
  authenticates that address. Ambiguous legacy case variants are refused.
- Thereafter the tenant/object pair is authoritative. An unoccupied UPN rename
  updates contact information on the **same user**, preserving content and approvals.
- Different objects cannot claim linked emails. Contact collisions are denied;
  there is no automatic merge/takeover. Transactions/locks/uniqueness guard duplicates.
- A legitimate new OAuth login may issue another session for the same user;
  replaying consumed OAuth state remains forbidden.
- Legacy unlocked `teacher` users become pending candidates and old tokens are
  revoked. **All users, classes, files, grades and results remain**. Approved roles
  stay intact. Operators must review legacy teachers via admin, not bulk-approve.
- Rollback removes added columns/indexes but does not restore unsafe privileges
  or revoked tokens.

Local demo teacher seed fixtures explicitly represent approved demo users, never
Microsoft proof. Production demo seeding remains disabled. Existing local fixtures
affected by migration may be approved through the local admin API/UI; that approval
is audited and does not mark them Microsoft-verified.

## Admin workflow and privacy

Existing user-list, pending-list and `PATCH /api/admin/users/{user}` routes remain.
Pending entries supply safe name, email, candidate, source, role and active status.
Tenant/object IDs, provider tokens and raw claims are not returned.

Approve with `{role:"teacher"}`: admin authorization, validation, role lock, existing
`user.role_change` audit. Reject with `{is_active:false}`: activation audit and blocked
login. UI requires confirmation and distinguishes student/candidate/approved teacher.
`auth.microsoft_verified` records candidate/status only. Self-profile updates prohibit
all verification/role fields. There is no frontend self-promotion path.

## Entra configuration required later

- The current ClassLink app is **Web / accounts in any organizational directory
  (multitenant)**. School users authenticate in their organization even when the
  application registration belongs to another organization.
- Local `AZURE_TENANT_ID=organizations`: work/school accounts only. Durable identity
  stores the actual Graph organization GUID, never the literal authority alias.
  Fixed tenant GUID mode remains available for a deliberately restricted deployment.
- `AZURE_CLIENT_ID=<YOUR_VALUE>`: application/client ID.
- `AZURE_CLIENT_SECRET=<YOUR_VALUE>`: secret **value**, server-side only.
- `AZURE_REDIRECT_URI`: exact backend callback ending `/api/auth/microsoft/callback`.
  Local example: `http://127.0.0.1:8000/api/auth/microsoft/callback`; hosted callback
  requires HTTPS. Register this exact URI as a Web redirect.
- Installed provider requests Graph delegated **`User.Read`**. School administrators
  must approve registration/access/consent under tenant policy. No unsupported
  ID-token validation claim is made.
- `FRONTEND_URL` supplies callback/pending/denied return origin. Nonce cookies are
  handshake-only; API remains stateless bearer authentication, CORS credentials off.
- Local testing must use consistent hostname spelling for the redirect and
  callback (`localhost` versus `127.0.0.1` are different cookie hosts). Use the
  loopback URI accepted by the tenant's Web registration and match it in config.
- Logout revokes ClassLink tokens, not every Microsoft SSO session.

Live tenant access, consent, UPN availability and end-to-end login remain pending.
Do not put production credentials in the repository.

## Jira traceability and checks

Updated existing **CL-3, CL-7, CL-50, CL-53**; CL-4 fallback remains supported.
No duplicate items. Old automatic-teacher expectations were replaced by stronger
candidate/no-elevation assertions; existing OAuth replay tests remain intact.

Regressions: `OfpptMicrosoftAccountTest`, `RoleDetectionTest`, `MicrosoftRedirectTest`,
frontend pending/admin interactions and `ofppt-verification.spec.mjs`. Browser UI
uses mocked proof responses and is not live Microsoft verification. Final results
are recorded after checks.

### Final verification results

| Command / check | Result |
|---|---|
| `php vendor/phpunit/phpunit/phpunit --no-progress` | 490 tests, 1706 assertions, no failures/errors |
| `composer validate --strict --no-check-all` | Valid |
| `composer audit --no-interaction` | No security advisories |
| `php artisan about --only=environment` | Confirmed local environment |
| `php artisan migrate --no-interaction` | New migration applied to local SQLite only |
| `php artisan migrate:status` | All 27 migrations applied |
| `php artisan route:list --path=auth/microsoft` | Redirect, callback and receipt routes registered |
| Frontend `npm run typecheck` | Passed |
| Frontend `npm test` | 78 passed |
| Frontend `npm run build` | Passed |
| Scripts `npm run browser` with `CLASSLINK_LOCAL_API=http://127.0.0.1:8000` | 22 passed in Chromium/Firefox; no skipped cases |
| `git diff --check` | Passed (existing LF/CRLF normalization warnings only) |

PHP formatting was applied to touched files using Pint. Existing state/replay
tests still pass. Frontend tests prove receipt-based messaging and safe candidate
details/approval controls; external provider calls remain mocked.

The optional local browser setup approves only the two known, unverified
DemoSeeder teacher fixtures through the actual authenticated local admin API.
It refuses remote hosts and never auto-approves Microsoft-verified candidates.
These fixture approvals are audited, do not set Microsoft verification metadata,
and keep existing role-route tests usable after the conservative migration.

### Files in this implementation

New:

```text
backend/app/Services/MicrosoftAccountService.php
backend/database/migrations/2026_10_04_000001_add_microsoft_identity_to_users.php
backend/tests/Feature/OfpptMicrosoftAccountTest.php
docs/OFPPT_MICROSOFT_AUTH.md
scripts/browser/ofppt-verification.spec.mjs
scripts/browser/prepare-local.mjs
```

Modified in this pass (earlier local-polish edits were preserved):

```text
README.md
backend/app/Http/Controllers/AuthController.php
backend/app/Http/Controllers/AdminController.php
backend/app/Http/Controllers/ProfileController.php
backend/app/Models/User.php
backend/app/Services/OtpService.php
backend/app/Support/RoleDetector.php
backend/config/classlink.php
backend/routes/api.php
backend/database/factories/UserFactory.php
backend/database/seeders/DemoSeeder.php
backend/tests/Feature/MicrosoftRedirectTest.php
backend/tests/Feature/RoleDetectionTest.php
backend/tests/Unit/DomainRulesTest.php
docs/API.md
docs/ASSUMPTIONS.md
docs/JIRA-BOARD.md
frontend/src/components/guards.tsx
frontend/src/screens/PublicScreens.tsx
frontend/src/screens/AdminScreens.tsx
frontend/src/lib/endpoints.ts
frontend/src/lib/types.ts
frontend/src/i18n/fr.ts
frontend/src/i18n/en.ts
frontend/tests/frontend-flows.test.tsx
scripts/playwright.config.mjs
```

No deployment, production credentials, commit, push or deletion of application
records. All repository changes remain unstaged on `main`, including the earlier
local-polish work. Real OFPPT Microsoft verification remains pending live Entra testing.
