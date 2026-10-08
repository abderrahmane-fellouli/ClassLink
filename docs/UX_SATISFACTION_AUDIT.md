# ClassLink — product UX and satisfaction audit

## Scope and evidence

Starting point: clean `main` at `2d2157a`. This pass preserves the navy/blue
ClassLink identity, warm canvas, institutional permissions and historical data.
No commits, pushes, deployments, remote Jira changes or production operations.

Inventory: **38 route definitions** in `frontend/src/router.tsx`: **35 rendered
route entries**, including the wildcard recovery screen, and **3 compatibility
redirects**. `/app` and `/app/school` deliberately share the institutional home.
Role-specific variants, tabs, dialogs, imports and detail actions are inventoried
in [FEATURE_UX_VALIDATION.md](FEATURE_UX_VALIDATION.md).

All 38 entries were reviewed in source and instantiated in browser coverage.
Seven screen modules were directly edited; shared shell/primitives/tokens affect
the wider rendered-route surface. “Reviewed” means source/API/role/state review
plus browser rendering, not that every possible combination of business data
or every interaction has a separate automated end-to-end test.

Baseline: **75 role/route visits**, **225 viewport checks**, **219 screenshots**.
Baseline had no page-level horizontal overflow or JavaScript exception, but
visual inspection exposed confusing navigation, excess nesting/padding,
unhelpful empty states, dense admin forms and poor mobile grade-table ergonomics.
Passing DOM checks alone was insufficient.

Scores below are **heuristic judgments from synthetic role walkthroughs**, not
measured satisfaction from real students/staff. No real roster, messages, grades,
credentials or personal records were used. The audit fixture explicitly seeds
six synthetic groups and ten synthetic teachers; no school limits are inferred.

## Shared design system

- Keep existing Inter/Poppins typography, blue primary/navy navigation and warm
  page background. Use quieter neutral field borders and low-elevation cards.
- One bounded 1,200px content container; institutional screens stop adding a
  second layer of outer padding. Shared headers have readable responsive scale.
- Consistent button spacing/line-height, 44px mobile controls, native select
  indicators, resizable multiline fields, visible file-input keyboard focus.
- Progressive disclosure for setup, group settings, admissions and exceptional
  module access. Avoid turning the dashboard into a decorative statistics page.
- Native fieldsets for multi-group audiences, column headings for tables,
  labelled mobile table cells, skip-to-content navigation and shared dialog close
  controls with Escape/focus trapping/restoration.
- Direct Messages and My grades navigation; four useful student bottom-nav
  destinations. Historical learning spaces remain reachable without pretending
  they are official module setup. Request badges no longer count all unread
  notifications as admission requests.
- Consistent contextual empty states, retryable errors and announced loading.
  One-page pagination is hidden instead of showing two disabled navigation buttons.

## Findings fixed

1. Official announcements rendered titles without bodies. Module cards now show
   their content and contextual edit/delete controls for authorized teachers.
2. Submission grading requested metadata from the blocked legacy class collection.
   It now reads the authorized assignment record directly.
3. Teacher links opened published quizzes in a draft-only editor. Published
   quizzes open results; draft quizzes retain the editor. Offering-aware return
   links prevent an institutional learner from returning to a blocked legacy page.
4. Study partners had no usable group selection for institution-only students.
   The UI combines official enrolled groups and historical classes. The new
   scoped read endpoint reuses existing matching and requires full enrollment;
   module-only grants do not expose classmates. Opt-in and email privacy remain.
5. Admin historical actions mixed in official groups and asked for numeric
   teacher IDs. Official groups stay in institutional setup; historical transfer
   uses approved teacher names. Inline role changes require explicit confirmation.
6. Manual school admission asked admins to discover database user IDs. It now
   uses named, searchable active-student choices. Coordinator import/decisions
   retain their existing scoped authorization.
7. Delegate-announcement permission and exceptional module grants were API-only.
   Admins can manage them in context. Grant inspection is admin-only, without
   emails. Delegate permission now round-trips as a boolean in school overview.
8. Grade entry could retain `ungraded` while a numeric score was entered. Typing
   now selects `graded`; absent/exempt/makeup clear numeric scores. Inline limits
   explain invalid entries, and invalid drafts cannot be submitted by the UI.
9. Language refresh could replace dirty grades and adopt a newer revision while
   preserving an older edit buffer. Dirty rows and the original optimistic-lock
   version now remain intact. Server conflict checks remain authoritative.
10. Grade import reasons were raw machine codes. Grade/roster previews now explain
    specific corrections in FR/EN. Publication prerequisites, saved-draft status
    and completed-roster count are explicit.
11. Roster preview looked up transfer conflicts using emails against numeric user
    IDs. Corrected the lookup and added a preview regression: required transfers
    are explained before commit; enrollment remains unchanged.
12. Notification read failures were unhandled promises; profile notifications
    did not open their targets. Both views recover errors and use an explicit
    internal destination allowlist. Teacher historical links use management routes.
13. Deadlines were non-actionable rows. Authorized deadline responses now include
    direct assignment/quiz destinations, including offering context.
14. Naive deadline input used device/UTC time inconsistently. School wall-clock
    values resolve against `Africa/Casablanca`, including Ramadan UTC suspension.
    Date-only assessment context and explicitly zoned timestamps remain unchanged.
15. OTP forms lacked Enter submission and one-time-code autocomplete. Added native
    form semantics, pending guards, visible email context and a prominent OTP
    fallback. OTP can return to the originally requested internal route.
16. Suspended/rejected users were told only that Microsoft eligibility was missing.
    Recovery now describes account unavailability without falsely diagnosing the
    cause or granting access. Profile sign-in copy does not certify Microsoft
    verification for an OTP account.
17. Quiz CSV export treated successful `void` download completion as failure.
    It now reports only the action’s actual error. Protected downloads use safe
    localized transport/server failure messages.
18. Screenshot iteration caught empty `search=` query validation failures and an
    oversized sticky mobile grade toolbar. Empty queries are omitted; the mobile
    toolbar is inline, while desktop retains useful sticky actions.
19. The installed oxfmt 0.2 formatter corrupted inline TypeScript separators and
    cast parentheses. Repairs preserved the UX changes; tooling is pinned to
    oxfmt 0.72.0 and verified against TypeScript and the full frontend suite.
20. Workspace refreshes unmounted disclosures and reset local search/file state.
    Background refresh now retains existing content and announces progress;
    failures still replace content with the appropriate error boundary.
21. Official partner replies were rejected by the legacy teaching-record boundary.
    Explicit school-scoped request/reply endpoints retain enrollment, recipient,
    opt-in and read-only checks; the legacy boundary is not relaxed.
22. Development role shortcuts are now explicitly opt-in in local Vite builds.
    Production builds still cannot display them. OTP Enter submission also checks
    the complete six-digit value and expiry before making a request.
23. Continuation recovery testing found two competing post-login redirects: the
    OTP screen preserved the requested page, but the authenticated guest guard
    sent the user to the dashboard. Both now use the same normalized internal
    return-path resolver, retaining query context and refusing escaping/external
    destinations. Actual session expiry and OTP re-login verify the result.
24. Server-error validation/context payloads could retain internal details even
    after the top-level message was sanitized. HTTP 5xx now discards those extra
    fields; a regression test covers message, field errors and context together.

### Continuation verification method

The existing runner was resumed, not replaced by another audit. Same-page
`networkidle` was not treated as proof of a committed mutation: the runner now
polls for the expected real API/database state. French/English initialization
does not overwrite a user's selected locale on navigation. The isolated server
uses its own database cache so audience previews persist across HTTP requests,
matching production behavior without contacting a production cache or DB.

Delegate verification covers ordinary student access, badge/navigation, teacher
representation, private admin requests, permitted class-only notices, unrelated
group denial, peer-grade/private-thread denial, teacher/admin capability denial,
revocation and replacement without conversation inheritance. The original
synthetic responsibility is restored between browser runs.

Recovery verification includes transport interruption; FR/EN 403, 404, 429,
500 and 503 retry states; a removed notification target; actual 401 expiry of
only the synthetic browser session; invalid OTP; valid OTP re-login to the
previous workflow; 422 field validation with retained input; and a failed 503
upload followed by a successful persisted retry. SMTP delivery is not claimed:
OTP verification uses a guarded CLI fixture and the array mailer, never a real
mailbox or Microsoft-consent bypass.

## Before/after satisfaction scorecard (1–5)

Vector order: **clarity / visual quality / efficiency / consistency / mobile /
error handling / confidence-trust / overall satisfaction**. A 5 is reserved for
a specific strong capability, not a claim of product perfection.

| Major page/workflow | Before | After | Reason and remaining limitation |
|---|---|---|---|
| Public home | 3/4/3/3/3/3/3/3 | 4/4/4/4/4/3/4/4 | Current group/module language; labelled illustrative preview; no live institutional sign-off |
| Privacy | 4/3/4/3/3/3/4/4 | 4/4/4/4/4/3/4/4 | Mobile heading scale; policy itself is institution-owned |
| Microsoft callback/recovery | 3/3/3/3/3/3/3/3 | 4/4/4/4/4/4/4/4 | Clear retry/OTP route; live tenant consent is external |
| OTP login | 3/4/3/3/4/3/4/3 | 4/4/4/4/4/4/4/4 | Native forms/autofill/pending/expiry; live mail delivery not exercised |
| Pending/denied accounts | 2/3/2/3/3/3/2/2 | 4/4/3/4/4/4/4/4 | Truthful account-state copy; approval still requires staff |
| 404/route error | 3/3/3/3/3/3/4/3 | 4/4/4/4/4/4/4/4 | Shared controls and safe recovery; no unsupported error details |
| Student institutional home | 3/3/3/2/3/3/4/3 | 4/4/4/4/4/4/4/4 | Role guidance, deadlines, updates and direct grades/messages |
| No-group student | 3/3/2/3/3/3/3/3 | 4/4/4/4/4/4/4/4 | Admission next step without invented groups |
| Delegate responsibility | 2/3/2/2/3/3/4/2 | 4/4/4/4/4/4/5/4 | Discoverable scoped responsibility; cannot inherit private history |
| Historical class list | 2/3/3/2/3/3/3/3 | 4/4/4/4/4/4/4/4 | Explicit compatibility context instead of misleading new-class CTA |
| Historical class tabs | 4/3/3/3/3/3/4/3 | 4/4/4/4/4/4/4/4 | Shared controls/feedback; historical model remains intentionally separate |
| Module space/resources | 3/3/3/2/3/3/4/3 | 4/4/4/4/4/4/4/4 | Tabs, content bodies, edit/delete and purposeful creation groups |
| Assignment publication | 3/3/3/3/3/3/4/3 | 4/4/4/4/4/4/4/4 | Only relevant lifecycle actions; draft/published/closed remain distinct |
| Student submission | 4/3/3/3/3/3/4/3 | 4/4/4/4/4/4/4/4 | Keyboard file input and closed-state guard; single submission remains the rule |
| Submission grading | 3/3/3/3/3/3/4/3 | 4/4/4/4/4/4/4/4 | Correct metadata scope, retryable errors; no institutional-average claim |
| Practice quiz attempt/resume | 4/3/4/3/3/4/4/4 | 4/4/4/4/4/4/4/4 | Wrapped controls and valid offering return path; server timer stays authoritative |
| Manual quiz editor | 3/3/3/3/3/3/4/3 | 4/4/4/4/4/4/4/4 | Typed date input/edit context; lengthy multi-question forms still need human pilot feedback |
| Quiz results/export | 4/3/3/3/3/2/4/3 | 4/4/4/4/4/4/4/4 | Successful export no longer emits a false error |
| Flashcards | 4/3/4/3/3/3/4/4 | 4/4/4/4/4/4/4/4 | Existing study/review/edit lifecycle preserved and shared controls improved |
| AI draft generation | 3/3/3/3/3/3/4/3 | 4/4/3/4/4/4/4/4 | Accessible input and manual fallback; provider performance not measured locally |
| Official grade entry | 3/3/3/2/2/3/4/3 | 4/4/5/4/4/4/5/4 | Practical desktop grid, mobile rows, inline limits and revision-safe dirty state |
| Spreadsheet preview/commit | 3/3/3/3/3/2/4/3 | 4/4/4/4/4/4/5/4 | Localized row corrections; IDs/context must remain institution-supplied |
| Grade publication/correction | 3/3/3/3/3/3/4/3 | 4/4/4/4/4/4/5/4 | Clear prerequisites and previous published result retained during correction |
| My grades/private export | 4/3/3/3/3/3/4/3 | 4/4/4/4/4/4/5/4 | Readable private cards, correction status and direct navigation |
| Messages/inbox | 3/3/3/2/3/3/4/3 | 4/4/4/4/4/4/5/4 | Recipient context, privacy, resolved status; no attachments by design |
| Multi-group announcements | 3/3/3/3/3/3/4/3 | 4/4/4/4/4/4/5/4 | Native fieldset and audience preview/confirmation; broadcast is distinct from private question |
| Private support reports | 3/3/3/3/3/3/4/3 | 4/4/4/4/4/4/5/4 | Contextual empty state and localized status; no hidden thread access |
| Notifications/preferences | 3/3/3/3/3/2/4/3 | 4/4/4/4/4/4/5/4 | Real deep links, failure recovery, unread text and preference destination |
| Deadlines | 3/3/2/3/3/3/4/3 | 4/4/4/4/4/4/4/4 | Actionable links and consistent school time |
| Study partners | 2/3/2/2/3/3/4/2 | 4/4/4/4/4/4/5/4 | Actual official group selection; opt-in preserved, no emails |
| Practice progression | 4/3/3/3/3/3/4/3 | 4/4/4/4/4/4/4/4 | Existing history/trends retained; separate from official averages |
| Profile/language/security | 4/3/3/3/3/3/4/3 | 4/4/4/4/4/4/4/4 | Accessible shared controls and truthful identity copy |
| Admin setup | 3/3/2/2/2/3/4/3 | 4/4/4/4/4/4/4/4 | Progressive setup, named choices and explicit delegated permissions |
| Roster/import/transfer | 3/3/2/3/2/2/4/3 | 4/4/4/4/4/4/5/4 | Search, admission names, early transfer conflict and clear import errors |
| Teacher assignments/co-teaching | 3/3/3/3/3/3/4/3 | 4/4/4/4/4/4/4/4 | Searchable approved staff; explicit replacement/revocation preserved |
| Exceptional module access | 1/2/1/2/2/2/4/2 | 4/4/4/4/4/4/5/4 | Reason/expiry, named student, active-grant review/revoke; admin only |
| Admin users/pending validation | 3/3/3/3/2/3/4/3 | 4/4/4/4/4/4/5/4 | Mobile rows, debounced search and confirmation of role changes |
| Admin historical classes | 2/3/2/2/2/3/3/2 | 4/4/4/4/4/4/4/4 | No official-group legacy controls; teacher choice is named |
| Admin AI/audit | 3/3/3/3/2/3/4/3 | 4/4/3/4/4/4/4/4 | Responsive controls/table cells; operational audit vocabulary remains specialist |

## Role-by-role judgment

- **Stagiaire: 8.3/10.** Useful deadlines/modules/private results and messages,
  phone-sized controls and recovery. Historical practice still has a separate area.
- **Délégué: 8.2/10.** Student simplicity is preserved, responsibility is explicit,
  admin contact stays private and announcement permission is staff-controlled.
  No automatic handover of predecessors’ private conversations.
- **Formateur: 8.4/10.** Scoped module creation, actionable grade workflow and
  safer drafts/imports. Human feedback on a full-size real teaching workload is
  still needed; practice and official assessment remain distinct intentionally.
- **Super admin: 8.1/10.** Six-group/ten-teacher synthetic setup works without
  opening the database. The setup has useful disclosure/search, but it is a
  structured workspace rather than a fully guided onboarding wizard.

**Verdict: GOOD FOR PILOT.** No claim of perfection, certified averages, live
provider readiness or measured real-user satisfaction.

## Remaining limitations and external inputs

- Microsoft tenant consent, real SMTP delivery, private object storage, actual
  Render runtime and physical mobile/Safari behavior were not exercised here.
- Institutional averaging/rounding/absence rules and certified transcripts remain
  external decisions. The application must not invent them.
- Legacy group/module reconciliation requires human review. Historical data is
  preserved; compatibility workspaces are intentionally still reachable.
- Real staff/student pilot feedback is needed to validate the heuristic scores,
  large-roster density, long-form workflows and screen-reader behavior beyond
  the automated keyboard/semantic checks. This is not a reason to defer code defects.

## Reproduction and visual evidence

`node scripts/ux-audit.mjs before|after` owns isolated SQLite/API/Vite servers,
refuses occupied ports, uses `MAIL_MAILER=array`, and cleans its temporary DB and
token file. `node scripts/ux-report.mjs` summarizes the evidence without printing
tokens or private response bodies. `node scripts/browser-gate-local.mjs` runs
the existing browser suite against a separate fresh local demo DB.

For continuing verification use `after --routes-only` to refresh the final
route/viewport evidence and `after --journeys-only` for committed-state role and
recovery checks. This preserves the existing baseline; do not rerun `before`
against the improved UI. `node scripts/ux-contact-sheet.mjs` builds labelled
visual overview sheets from the final mobile route screenshots.

- `artifacts/ux/before/review.json`, `artifacts/ux/after/review.json`
- `artifacts/ux/journeys/review.json`: individual delegate/recovery checks;
  runtime-generated credentials are excluded from this report.
- Before/after examples: `student2-0-320.png`, `student2-3-1440.png`,
  `teacher1-16-320.png`, `teacher1-17-320.png`, `admin-0-1440.png`.
- Final representative screenshots also include every requested width and Firefox
  examples prefixed `firefox-`. Screenshots and runtime evidence stay gitignored.

## Final continuation verdict and evidence

**GOOD FOR PILOT.** The everyday roles can complete the verified local workflows
without a developer operating the database. Scores remain heuristic: stagiaire
8.3/10, délégué 8.2/10, formateur 8.4/10, super admin 8.1/10. They are not survey
results and do not certify production/provider readiness.

- **38 route entries discovered/reviewed**: 35 rendered entries and 3 redirects.
- **35 rendered entries affected** by the shared/UI changes; seven screen modules
  directly edited, with recovery semantics also changed in the router/guards.
- **300 final role/route/locale visits**, **5,360 responsive checks**, **0 final
  document overflows/render-error visits**. Widths: 320, 360, 375, 390, 430, 768,
  1024, 1280, 1440 and 1920, Chromium and Firefox.
- **316 route screenshots**, **24 delegate/recovery screenshots**, **7 contact
  sheets** covering 73 mobile route instances. Representative desktop/tablet/
  mobile images and all sheets were visually inspected; screenshots are not
  treated as proof that every control has an independent mutation test.
- **12 committed-state scenarios** across both browsers; **56 named delegate/
  recovery checks**. Revocation and replacement preserve student simplicity and
  never transfer private conversation history or peer-grade permissions.
- SQLite **594/2,479 assertions**, isolated PostgreSQL **594/2,479 assertions**;
  frontend **110/9 files**, Playwright **34/34**, infrastructure **25/25**;
  TypeScript, build, formatting, syntax, schema, audits and diff checks passed.
- Intended-content scan: **50 files**, **0 credential/cohort/phone-pattern files**,
  **0 forbidden artifact paths**; ignored credentials were not opened by the scan.

The remaining dissatisfaction is explicit: historical compatibility uses a
separate learning area; admin setup is structured rather than a complete wizard;
specialist audit/AI screens need staff context; large real workloads and actual
assistive devices need human pilot feedback. These are not excuses for unresolved
internal blocking defects or reasons to weaken authorization.

The full exact gate table and feature-specific coverage are in
`FEATURE_UX_VALIDATION.md`. Partial/failed iterations are not passing evidence.
No commit, push, deployment, Jira operation or production configuration/data
change was performed; all intentional changes remain in the current working tree.
