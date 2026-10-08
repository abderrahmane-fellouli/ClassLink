# ClassLink — complete feature/route UX validation

## Reading this matrix

Inventory source: `frontend/src/router.tsx`, all screen modules, shared UI,
`frontend/src/lib/endpoints.ts`, `backend/routes/api.php` and `routes/school.php`.
**38 route definitions = 35 rendered entries + 3 compatibility redirects.**
No additional public privilege routes or roles were invented. Delegate remains
an expiring responsibility of a student, not a platform-administrator role.

Verification codes: **R** source/API/authorization review; **B** real synthetic
browser route/state review in Chromium and Firefox; **J** browser mutation with
API/database-result verification; **U** frontend interaction/contract test;
**A** backend feature/authorization test. “Shared” error/empty handling means
the shared boundary is verified, not a separate fault injection on every row.
“4/5” is heuristic satisfaction, not actual survey data. “Conditional” means the
feature needs a legitimate role/context or an external provider, not fake access.

## Every route and role variant

| Feature | Role | Route | Functional | Visually polished | Mobile | FR | EN | Empty state | Error state | Tested | User satisfaction | Remaining issue |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| Public overview | Visitor | `/` | Yes | Yes | 10 widths | Yes | Yes | N/A | N/A | R/B | 4/5 | Preview is explicitly illustrative |
| OTP/Microsoft entry | Visitor | `/login` | OTP yes; Microsoft conditional | Yes | 10 widths | Yes | Yes | Form guidance | Invalid/expired/network | R/B/U/A | 4/5 | Live tenant consent/SMTP external |
| OAuth callback | Visitor | `/auth/microsoft/callback` | Yes | Shared | 10 widths | Yes | Yes | Missing fragment recovery | Safe provider recovery | R/B/U/A | 4/5 | Live Microsoft not called |
| Privacy | Visitor | `/privacy` | Yes | Yes | 10 widths | Yes | Yes | N/A | N/A | R/B | 4/5 | Institutional policy sign-off |
| Pending approval | Pending | `/pending` | Yes | Shared | 10 widths | Yes | Yes | Truthful next step | Invalid receipt fallback | R/B/U/A | 4/5 | Staff approval required |
| Unavailable account | Denied/suspended | `/denied` | Yes | Shared | 10 widths | Yes | Yes | Next step | No false verification claim | R/B/U/A | 4/5 | Cause must be resolved by staff |
| Role institutional home | Student/teacher/admin | `/app` | Yes | Yes | 10 widths | Yes | Yes | Role-specific | Retry boundary | R/B/J/U/A | 4/5 | Shared with `/app/school` intentionally |
| Dashboard alias | Authenticated | `/app/dashboard` | Redirect | N/A | Verified | Yes | Yes | Destination | Protected destination | R/B | 4/5 | Compatibility redirect |
| Official workspace | All active roles/delegate | `/app/school` | Yes | Yes | 10 widths | Yes | Yes | Admission/assignment next step | Retry boundary | R/B/J/U/A | 4/5 | Structured setup, not a wizard |
| Official module | Enrolled student/assigned teacher | `/app/school/offerings/:offeringId` | Yes | Yes | 10 widths/all tabs | Yes | Yes | Per-content type | Scoped denial/retry | R/B/J/U/A | 4/5 | Large real workload needs pilot feedback |
| Official grade editor | Assigned teacher | `/app/school/assessments/:id` | Yes | Yes | Mobile rows/desktop grid | Yes | Yes | Roster guidance | Inline bounds/conflicts/import | R/B/J/U/A | 4/5 | No certified institutional average |
| Personal official results | Student | `/app/school/grades` | Yes | Yes | 10 widths | Yes | Yes | Publication/filter explanation | Private retry | R/B/J/U/A | 4/5 | Published results only |
| Conversation inbox/compose | All active roles/delegate | `/app/school/messages` | Yes | Yes | 10 widths | Yes | Yes | Recipient next step | Contacts/actions retry | R/B/J/U/A | 4/5 | No attachments |
| Conversation detail | Explicit eligible participant | `/app/school/messages/:id` | Yes | Yes | 10 widths | Yes | Yes | Scoped recovery | 404/revocation/retry | R/B/J/U/A | 4/5 | Replacement never inherits history |
| Profile/preferences/security | All active roles | `/app/profile` | Yes | Yes | 10 widths/all tabs | Yes | Yes | Notifications explanation | Save/read/revoke failures | R/B/U/A | 4/5 | 18 preferences retained |
| Historical learner class list | Student | `/app/classes` | Yes | Shared | 10 widths | Yes | Yes | Explicit historical context | Shared retry | R/B/U/A | 4/5 | Official modules use School workspace |
| Historical teacher class list | Teacher | `/app/classes` | Yes | Shared | 10 widths | Yes | Yes | Current workspace CTA | Shared retry | R/B/U/A | 4/5 | No misleading teacher self-creation |
| Historical class detail | Accepted historical student | `/app/classes/:id` | Yes | Shared | 10 widths/all tabs | Yes | Yes | Per-content type | Authorization/retry | R/B/U/A | 4/5 | Kept for historical data |
| Quiz attempt/result | Authorized student | `/app/classes/:classroomId/quizzes/:id` | Yes | Shared | 10 widths | Yes | Yes | Start/resume | Timer/submit/network | R/B/J/U/A | 4/5 | Practice is distinct from official grades |
| Assignment submission/feedback | Authorized student | `/app/assignments/:assignmentId` | Yes | Shared | 10 widths | Yes | Yes | Instructions/submission state | Upload/closed/private download | R/B/J/U/A | 4/5 | Single submission remains intentional |
| Flashcard study/manage | Authorized student/teacher/admin | `/app/flashcard-decks/:deckId` | Yes | Shared | 10 widths | Yes | Yes | Empty deck/add state | Review/edit/download errors | R/B/J/U/A | 4/5 | AI publication requires genuine review |
| Deadlines | Student | `/app/deadlines` | Yes | Shared | 10 widths | Yes | Yes | No upcoming work | Shared retry | R/B/U/A | 4/5 | Assessment dates also shown in school home |
| Opt-in study partners | Student | `/app/partners` | Yes | Shared | 10 widths/all tabs | Yes | Yes | No candidate/group | Read/write retry | R/B/U/A | 4/5 | Consent and accepted enrollment required |
| Admission request | Student | `/app/join` | Yes | Shared | 10 widths | Yes | Yes | Request guidance | Code/cooldown/history retry | R/B/U/A | 4/5 | Code does not approve enrollment |
| Historical membership requests | Teacher | `/app/requests` | Yes | Shared | 10 widths | Yes | Yes | No pending requests | Shared retry | R/B/U/A | 4/5 | Official roster decisions are in workspace |
| Practice history | Student | `/app/progression` | Yes | Shared | 10 widths | Yes | Yes | No practice results | Shared retry | R/B/U/A | 4/5 | No institutional-average claim |
| Historical class progression | Teacher | `/app/progression` | Yes | Shared | 10 widths | Yes | Yes | No class/data | Shared retry | R/B/U/A | 4/5 | Specialist teaching metrics retained |
| Old class-create route | Teacher | `/app/classes/new` | Redirect | N/A | Verified | Yes | Yes | Destination | Protected destination | R/B | 4/5 | Admin-only official creation preserved |
| Historical class management | Owner teacher | `/app/classes/:id/manage` | Yes | Shared | 10 widths/all tabs | Yes | Yes | Per-tab states | Scope/action retry | R/B/U/A | 4/5 | Legacy compatibility, no official ownership grant |
| Manual practice creation | Teacher | `/app/classes/:id/manage/quizzes/new` | Yes | Shared | 10 widths | Yes | Yes | Guided settings/questions | Field validation/retry | R/B/U/A | 4/5 | New institutional quizzes created from module |
| Practice editor | Assigned teacher/historical owner | `/app/classes/:id/manage/quizzes/:quizId/edit` | Yes | Shared | 10 widths | Yes | Yes | Draft settings | Published/permission denial | R/B/U/A | 4/5 | Published quiz uses results instead |
| Practice results/CSV | Assigned teacher/historical owner | `/app/classes/:id/manage/quizzes/:quizId/results` | Yes | Shared | 10 widths | Yes | Yes | No attempts | Actual export failure only | R/B/U/A | 4/5 | Practice analytics only |
| Submission grading | Assigned teacher/historical owner | `/app/classes/:id/manage/assignments/:assignmentId/grade` | Yes | Shared | 10 widths | Yes | Yes | No submissions/select next | Record scope/retry | R/B/U/A | 4/5 | Correct record metadata path |
| Old teacher detail alias | Teacher | `/app/teacher/classes/:id` | Redirect | N/A | Verified | Yes | Yes | Destination | Protected destination | R/B | 4/5 | Historical redirect preserved |
| Operational overview | Admin | `/app/admin` | Yes | Shared | 10 widths | Yes | Yes | Counts/next actions | Stats failure | R/B/A | 4/5 | School setup lives in official workspace |
| Account approvals/management | Admin | `/app/admin/users` | Yes | Yes | Labelled mobile rows | Yes | Yes | No matches/pending | Read/action retry | R/B/U/A | 4/5 | Human identity approval still required |
| Historical class ownership | Admin | `/app/admin/classes` | Yes | Yes | Labelled mobile rows | Yes | Yes | No historical records | Read/transfer/archive retry | R/B/U/A | 4/5 | Official groups excluded intentionally |
| AI provider controls | Admin | `/app/admin/ai` | Yes, local configuration | Shared | 10 widths | Yes | Yes | No providers | Action/retry | R/B/U/A | 4/5 | Live provider capacity not measured |
| Audit filters/history | Admin | `/app/admin/audit` | Yes | Shared | Labelled mobile rows | Yes | Yes | No matching events | Shared retry | R/B/U/A | 4/5 | Audit vocabulary intentionally technical |
| Unknown URL recovery | Any | `*` | Yes | Shared | 10 widths | Yes | Yes | Return path | Route-level recovery | R/B/U | 4/5 | No blank route |

## Nested workflows, dialogs and APIs

| Feature | Role | Route | Functional | Visually polished | Mobile | FR | EN | Empty state | Error state | Tested | User satisfaction | Remaining issue |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| Year create/edit/archive | Admin | School workspace → setup | Yes | Disclosure | Verified | Yes | Yes | Setup guide | Native fields/API | R/B/A | 4/5 | Closed-year dates remain frozen |
| Group create/edit/archive/restore | Admin | School workspace → group | Yes | Disclosure | Verified | Yes | Yes | Setup guide | Native fields/API | R/B/J/A | 4/5 | Historical data never moved |
| Module catalogue/offering status | Admin | School workspace → setup/group | Yes | Disclosure | Verified | Yes | Yes | Catalogue guide | Native fields/API | R/B/J/A | 4/5 | Definition/offering archive are distinct |
| Named teacher assignment/co-teaching | Admin | School workspace → offering | Yes | Search/select | Verified | Yes | Yes | Approved staff only | Request/action boundary | R/B/J/A | 4/5 | Replacement is revoke + explicit assignment |
| Coordinator roster grant | Admin | School workspace → group | Yes | Explicit options | Verified | Yes | Yes | Named staff selector | API | R/B/A | 4/5 | Assignment alone grants no roster management |
| Named admission/search/remove | Admin/coordinator | School workspace → roster | Yes | Disclosure | Verified | Yes | Yes | No students/search matches | Scoped fields/API | R/B/J/A | 4/5 | Coordinator uses existing approved decision/import scope |
| Roster preview/commit/corrections | Admin/coordinator | School workspace → admissions | Yes | Localized row reasons | Verified | Yes | Yes | CSV instructions | Early conflict/transfer error | R/B/J/A | 4/5 | Institution must supply stable identities |
| Transfer | Admin | School workspace → roster | Yes | Named selectors | Verified | Yes | Yes | Same-year targets | Scoped API | R/B/A | 4/5 | Not a silent grade migration |
| Delegate appointment/revocation | Admin | School workspace → roster | Yes | Named selectors | Verified | Yes | Yes | No delegate | API/current membership | R/B/J/A | 4/5 | Maximum two active scoped delegates |
| Delegate notice permission | Admin | School workspace → group settings | Yes | Toggle/help | Verified | Yes | Yes | Default disabled | Boolean round-trip | R/B/J/A | 4/5 | No self-granted permission |
| Exceptional module access | Admin | School workspace → offering disclosure | Yes | Named reason/expiry/grants | Verified | Yes | Yes | No active grant | Scoped admin-only API | R/B/A | 4/5 | Does not expose full roster |
| Assignment/setup requests | Teacher/admin | School workspace → requests | Yes | Context guide | Verified | Yes | Yes | No requests | Action boundary | R/B/A | 4/5 | Teacher request grants no access |
| Resource link/file create/edit/delete | Assigned teacher | Module → resources | Yes | Grouped actions | Verified | Yes | Yes | No resource | Fields/private download | R/B/J/U/A | 4/5 | No public file shortcut |
| Module announcement body/edit/delete | Assigned teacher | Module → announcements | Yes | Readable body | Verified | Yes | Yes | No announcement | Action boundary | R/B/A | 4/5 | Separate from private threads |
| Assignment draft/edit/publish/close | Assigned teacher | Module → assignments | Yes | Relevant lifecycle controls | Verified | Yes | Yes | No assignment | Native fields/API | R/B/J/U/A | 4/5 | Closure preserves existing submissions |
| Practice quiz/flashcard manual create | Assigned teacher | Module → practice/flashcards | Yes | Shared fields | Verified | Yes | Yes | No practice content | Native fields/API | R/B/J/U/A | 4/5 | Full quiz editing uses existing editor |
| AI upload/poll/review/fallback | Owner teacher | Historical management → practice | Local/stubbed | Shared | Verified | Yes | Yes | Upload guidance | Quota/failure/manual fallback | R/B/U/A | 4/5 | Real model quality/availability external |
| Grade direct entry/save | Assigned teacher | Official assessment | Yes | Desktop grid/mobile cards | Verified | Yes | Yes | Roster context | Inline bounds/revision safety | R/B/J/U/A | 4/5 | Human full-roster pilot feedback needed |
| CSV/XLSX download/import/preview | Assigned teacher | Official assessment | Yes | Explicit draft semantics | Verified | Yes | Yes | File instructions | Localized row/context errors | R/B/U/A + school pilot | 4/5 | Template identifiers must stay intact |
| Grade publication/correction/reconciliation | Assigned teacher | Official assessment | Yes | Prerequisites/history | Verified | Yes | Yes | Candidate guidance | Dirty/conflict/roster checks | R/B/J/U/A | 4/5 | No certified averages/transcripts |
| Own results export/clarification | Student | My grades → private conversation | Yes | Readable private cards | Verified | Yes | Yes | Publication explanation | Private authorization | R/B/J/U/A | 4/5 | Historical eligibility is scoped |
| Private/delegate/organizational conversations | Explicit participants | Messages → composer/thread | Yes | Recipient/privacy/status | Verified | Yes | Yes | Contact guidance | Scope/revocation/network | R/B/J/U/A | 4/5 | No attachment support |
| Multi-group audiences/preview/publish | Teacher/admin/authorized delegate | Messages → announcement | Yes | Native grouped checks | Verified | Yes | Yes | Audience selector | Preview/commit rules | R/B/U/A | 4/5 | Publication is deliberately confirmed |
| Private support reports/respond/resolve | Reporter/named admin | Thread → report; Messages → support | Yes | Safe summary/status | Verified | Yes | Yes | No support requests | Named recipient/API | R/B/A | 4/5 | No implicit admin thread browsing |
| Notification bell/read/all-read/deep link | Active roles | Shell dialog | Yes | Shared dialog/unread text | Verified | Yes | Yes | No notifications | Action/network recovery | R/B/U/A | 4/5 | Target can be removed/revoked |
| Eighteen preferences/digest | Active roles | Profile → notifications | Yes | Labelled toggles | Verified | Yes | Yes | Notifications guidance | Read/save recovery | R/B/U/A | 4/5 | Real mail scheduling not invoked |
| Language/account display/security/logout | Active roles | Shell/profile dialogs | Yes | Shared controls | Verified | Yes | Yes | Account identity | Save/session recovery | R/B/U/A | 4/5 | Locale save outage is explained |
| Authentication expiry/re-login/retry | Active/expired account | Protected routes → login | Yes | Safe recovery | Verified | Yes | Yes | Sign-in next step | 401/network distinction | R/B/J/U/A | 4/5 | Live provider outage timing external |
| Dialogs/confirmation/Escape/focus | All permitted actors | Shared overlays | Yes | Consistent close/cancel | Verified | Yes | Yes | N/A | Disabled pending | R/B/U | 4/5 | Screen-reader pilot still recommended |

## Continuation: delegate and recovery details

| Feature | Role | Route | Functional | Visually polished | Mobile | FR | EN | Empty state | Error state | Tested | Satisfaction | Remaining issue |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| Scoped delegate badge/navigation | Active delegate | School workspace → representation | Yes | Shared badge/guidance | 320px evidence | Yes | Yes | Ordinary student when revoked | Current responsibility only | R/B/J/A | 4/5 | Not a global admin role |
| Delegate teacher/admin communication | Delegate | Messages → representation/organization | Yes | Explicit recipients | Verified | Yes | Yes | Eligible contacts | Unrelated participants denied | R/B/J/A | 4/5 | No inherited private conversation history |
| Delegate class notice | Authorized delegate | Messages → audience announcement | Yes | Preview/confirmation | Verified | Yes | Yes | Disabled without permission | Other groups denied | R/B/J/A | 4/5 | Admin permission required |
| Revocation/replacement | Admin/delegate | School workspace/delegate APIs | Yes | Badge tracks current status | Verified | Yes | Yes | Returns to student experience | Privileged access removed | R/B/J/A | 4/5 | Predecessor's threads remain private |
| Network/API fault retry | Student | School workspace | Yes | Localized safe boundary | 320px screenshots | Yes | Yes | Existing content/recovery | 403/404/429/500/503 + transport | R/B/J/U/A | 4/5 | Backend data checks are authoritative |
| Expired session/re-login | Student | Messages → login → original messages query | Yes | Session explanation/native OTP | 320px evidence | Yes | Yes | Sign-in recovery | Actual 401 + invalid OTP | R/B/J/U/A | 4/5 | Real SMTP/tenant consent remains external |
| Removed notification target | Student | Notification → missing thread → inbox | Yes | Safe error/return link | Verified | Yes | Yes | Missing target explained | Actual 404 | R/B/J/U/A | 4/5 | Removed content is not made public |
| Failed form/upload retry | Assigned teacher | Module resources | Yes | Specific fields/safe server error | Verified | Yes | Yes | Creation guidance | 422/503, retained input | R/B/J/U/A | 4/5 | Private storage authorization preserved |

## Safe test environments and limits

Two exported compatibility components, `StudentDashboard` and
`CreateClassScreen`, are deliberately not mounted by the current router.
They are retained for historical source/test coverage; the actual home is the
institutional workspace and teacher self-creation redirects there. This is not
an undiscovered navigation route or permission workaround. Operator-only backup,
storage reconciliation and initial-admin CLI commands are not end-user pages.

- Local UX audit: disposable synthetic SQLite, loopback ports 18010/18011,
  array mailer, no Microsoft/SMTP/object-store calls. Occupied ports are refused.
- Browser gate: fresh separate local demo SQLite on 8000; preview on 4173;
  array mailer and blank AI keys. Existing fixtures are declared synthetic.
- Backend engines run sequentially; PostgreSQL is an isolated loopback test
  cluster, never the Aiven endpoint from ignored `backend/.env`.
- The application’s real API validates mutations and resulting state. Unit/API
  tests cover additional failure, import, authorization and lifecycle branches.
- No claim that every button has a dedicated browser mutation test. The matrix
  distinguishes browser journeys from source/unit/API coverage instead of
  representing screenshots as proof of every interaction.

## Final gate evidence

All results below are from the final current local working tree, not the earlier
partial/failed runner iterations. Backend engines ran sequentially on isolated
databases. No production data/configuration or remote Jira was touched.

| Gate | Exact final result |
|---|---|
| Full backend SQLite | **594 passed, 2,479 assertions** |
| Full isolated PostgreSQL | **594 passed, 2,479 assertions**, `SUITE_EXIT=0` |
| Full frontend | **110 passed, 9 test files** |
| TypeScript | Passed (`tsc -b --noEmit`) |
| Production build | Passed (Vite 8.3.1) |
| Existing Playwright Chromium/Firefox | **34 passed, 0 failed, 0 skipped** |
| Synthetic committed-state journeys | **12 passed**: six scenarios in each browser |
| Delegate/recovery detail checks | **56 passed** within those scenarios: 20 delegate, 36 recovery |
| Final role/route/locale visual visits | **300 completed** |
| Base viewport checks | **3,000 passed** at 10 widths |
| Additional tab-state viewport checks | **2,360 passed**: 236 tab states × 10 widths |
| Combined responsive checks | **5,360 passed**, no document overflow |
| Browser render errors in final route evidence | **0** |
| Final route screenshots | **316** |
| Delegate/recovery screenshots | **24** |
| Visual contact sheets | **7**, representing 73 mobile route instances |
| Preserved baseline | 75 visits, 225 checks, 219 screenshots |
| Infrastructure/runtime | **25 passed**; Render/Vercel schema validation passed |
| Formatting | Oxfmt check passed on touched frontend/QA files; Pint check passed |
| Syntax/whitespace | JS/PHP/Bash syntax checks and `git diff --check` passed |
| Dependency audits | Composer: no advisories; frontend npm: 0 vulnerabilities |
| Intended-content sanity | **50 files** reviewed; 0 forbidden artifacts, 0 credential-pattern files, 0 cohort/phone-pattern files |

The final visual pass retained completed coverage and reverified **45**
interrupted/error or copy-affected visits on the frozen final UI. Recheck history
is retained separately; old errors were not silently ignored. The after report
contains the final verified record for each browser/role/locale/route key.

The final admission copy names the authorized group manager (not an assumed
teacher), covering official and historical workflows. The 404 and authentication
callback recovery screens have proper page headings. User-created synthetic
English content inside French UI is intentionally not translated; UI strings
and recovery states were checked in both languages.

### Evidence paths

- `artifacts/ux/before/review.json` — preserved baseline.
- `artifacts/ux/after/review.json` — 300 final route visits, tabs and viewport results.
- `artifacts/ux/after/recheck-history.json` — previous verification records.
- `artifacts/ux/journeys/review.json` — committed-state scenarios and named checks.
- `artifacts/ux/after/contact-sheet-1.png` through `contact-sheet-7.png`.
- `artifacts/ux/after/student2-0-320.png`, `student2-3-1440.png`,
  `teacher1-17-320.png`, `firefox-teacher1-17-768.png`, `admin-0-1440.png`,
  `empty-3-320.png`, `public-5-320.png`.
- `artifacts/ux/journeys/chromium-delegate-badge-320.png`,
  `chromium-expired-session-login-320.png`, `firefox-recovery-fr-503-320.png`.

Screenshots, traces, logs and local databases are gitignored or outside the
working tree. Only intentional source, configuration examples, tooling/tests and
documentation remain uncommitted. No files are staged; HEAD stays `2d2157a`.

### Honest boundaries

This is **GOOD FOR PILOT**, not perfection or measured real-user satisfaction.
The matrix distinguishes source/API/unit coverage from actual browser mutations.
Physical Safari/mobile-device and screen-reader pilot feedback, live SMTP/object
storage/Microsoft consent, certified average rules and human legacy reconciliation
remain external inputs. No internally identified blocking workflow defect remains
in the tested scope; the separate historical area and specialist audit/AI controls
are still less seamless than the everyday school workflows.
