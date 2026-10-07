# ClassLink institutional model — audit and durable implementation handoff

## Evidence and authority

Repository `abderrahmane-fellouli/ClassLink`, branch `main`, confirmed locally.
The combined master prompt supersedes the teacher-owned class model in earlier
specifications and Jira handoff documents. Six groups and ten teachers are
initial operating numbers, never limits or seed data. DEVOWFS204 / 23 students
is user-provided context only; no roster has been supplied or imported.

Locked/installed stack: Laravel 12.69.3, React 19.3.0, TypeScript 5.9.3,
Vite 8.3.1, Tailwind 4.3.3; production image PHP 8.4 (project floor 8.4.1).
Current domains are `https://classlink.space` and `https://api.classlink.space`.
No live provider configuration or credentials have been independently verified.
User reports OTP 202/200 in production and OFPPT tenant admin-consent blocking
Microsoft before callback. Neither implies complete production readiness.

Uncommitted storage-ceiling and runtime-supervision work predates this task and
is preserved. Sessions migration is already committed. No external actions are
authorized: no commit/push/deploy/Jira updates, production environment changes,
real-user messaging, production seeds, or destructive database operations.

## Compact gap table

| Requirement | Current evidence | Missing / broken | Proposed change | Verification |
|---|---|---|---|---|
| Shared official groups | `Classroom.teacher_id`; `ClassroomPolicy`; teacher POST `/classes` | One owner per copy, owner-controlled roster | Reuse classrooms; explicit official flag/year; module offerings and assignments | Two-teacher shared-roster API pilot and cross-module ID tests |
| Enrollment | `memberships` unique group/student; `MembershipService` | No one-primary-group/year invariant, teacher approves | Institutional enrollment history and admin/coordinator decisions | Duplicate/transfer/archive/suspension tests |
| Delegates | No entity/routes/screens | No scoped representation or two-person invariant | Time-bounded membership responsibility and serialized appointment | Third-delegate rejection, revocation and privacy tests |
| Official grades | Submission marks and quiz scores only | No assessment lifecycle, templates, import/correction versions | Separate official assessments and versioned grades | Stable-ID import, conflicts, publication privacy and correction tests |
| Communication | Announcements, partner requests, notifications | No private student/staff or representation threads | Explicit scoped participants, unread state, lifecycle checks | Nonparticipant and replacement/revocation tests |
| Identity | OTP hashed/expiry/attempt tests; Microsoft state/link service | Entra external admin consent; pending onboarding unclear | Preserve identity flow; clarify consent and onboarding | OTP/identity regressions, no live email sends |
| Resources / practice | Controllers, policies, React class screens | Ownership checks and class-only scope | Preserve legacy records; enforce offering scope on institutional content | Legacy suite plus module-scoped content tests |
| Runtime | `/ready`, `tini`, FPM/nginx/worker/scheduler; local diagnostics patch | Historical exited process unknown; early FPM race | Preserve readiness/process diagnostics; do not blame 502 for exit | Shell regression suite, actual Docker/Render remains external |
| Migration | Custom school schema, sessions migration | Ambiguous legacy teacher-copy mappings | Additive schema; dry-run mapping report; no name-based merging | Empty and populated synthetic upgrade, PostgreSQL gate |
| Daily UI | Existing responsive FR/EN screens | Owner-based teacher menus; no setup/grades/messages | Role-specific institutional workspace using existing design | Build, translations, keyboard/mobile/browser pilot |

## Structural decisions

1. Reuse `classrooms` for official groups; existing records remain explicitly
   legacy until reviewed. Never infer a mapping from similar names.
2. Academic year → official group → module offering; teacher authorization is
   an explicit active assignment. Legacy ownership does not grant access to an
   official offering. Coordinating and roster-management rights are separate.
3. Enrollment history stays in its original group/year. Transfers end one
   active period and open another; grades never move with the student.
4. Delegate is not a global user role. Maximum two active delegates, serialized
   on the group; responsibilities end on departure/expiry.
5. Institutional grades are separate from practice scores. Published revisions
   remain visible while corrections are drafted. No institutional average is
   certified. Averages are disabled until institutional inclusion/rounding rules
   are approved. The proposed numeric rule is score/max × 20 before weighting;
   absent/exempt/ungraded/makeup never silently become zero.
6. Private threads have explicit participants. Replacements are not implicitly
   added to old conversations. Removed/suspended actors lose live access.
7. Database timestamps remain consistent; school-facing dates use
   `Africa/Casablanca`. No production timezone variable is changed.

## Permission matrix (new institutional workflows)

| Action | Stagiaire | Formateur | Délégué | Super admin |
|---|---|---|---|---|
| Group / published module read | Accepted enrollment | Active assignment | Same as student | Institutional setup |
| Official roster decisions/import/transfer | No | Explicit coordinator permission only | No | Yes, audited |
| Teaching content / assessment draft | No | Assigned offering only | No | No implicit grading privilege |
| Own published grades / feedback | Own only | Assigned assessment roster | Own only | No implicit grade access |
| Grade correction/publication | No | Assigned offering, revision + reason | No | Requires separate justified policy |
| Delegate appointment/revocation | No | Recommendation only | No self-grant | Yes, audited |
| Private thread | Explicit eligible participant | Explicit eligible participant | Explicit participant, not predecessor's inbox | Explicit organizational participant only |
| Class representation | Contact active delegates | Relevant assigned staff | Active scoped responsibility | Organizational requests addressed to admin |

## Migration and operator constraints

Back up PostgreSQL using the existing private backup runbook before an approved
deployment. Apply new migrations before enabling new screens. Do not use
`migrate:fresh`, reset, or seed in production. Old content/attempts/submissions/
grades/memberships remain on their original IDs. Rollback after new institutional
data exists requires a backup/recovery plan; dropping new tables loses new data.

Ambiguous legacy mapping requires human review: source classroom ID, target
official group, target offering, membership collisions and publication history.
The dry-run report must never mutate data or disclose sensitive emails. Real
rosters require authorized supplied stable identities. No production demo setup.

## Implementation / verification status

Institutional core implemented locally. Main files:

- `backend/routes/school.php`: authenticated institutional APIs.
- `SchoolAccess`, `SchoolSetupService`, `RosterImportService`: group/module
  assignments, coordinator authorization, enrollment/transfer and delegate rules.
- `OfficialGradeService`, `GradeSpreadsheet`, `OfficialGradeController`:
  versioned drafts, CSV/XLSX preview/commit, publication and corrections.
- `SchoolCommunicationService`, `SchoolNoticeService` and their controller:
  participant-scoped conversations, audience preview and private support reports.
- `DeliverSchoolNotifications`: durable, retry-safe in-app notification outbox.
- `InstitutionalLegacyBoundary`, `ScopedToOffering`, `SchoolTeachingController`:
  existing teaching records reused behind current offering authorization.
- `frontend/src/screens/SchoolScreens.tsx`, `lib/school.ts`, `i18n/school.ts`:
  FR/EN workspace, module spaces, grading, personal results and messages.
- Uncommitted institutional migrations finalized on the actual host date:
  `2026_10_07_000010` through `000018`, after the already-deployed sessions
  migration. The previous future-dated names were never committed/deployed.

## Safe transition from old records

`classlink:school-mapping-report` is read-only. Optional `--map=reviewed.json`
accepts explicit `legacy_classroom_id` / `offering_id` pairs, reports enrollment
collisions and invalid/duplicate source mappings, and never applies a merge.
Do not infer mappings from names. A reviewed content-provenance mapping and real
roster reconciliation are required before retiring legacy workspaces.

Legacy records remain accessible through their existing compatibility workflows;
they are not silently converted, archived or merged. New class creation is now
admin-only and creates official groups. Legacy class-list endpoints exclude
official groups. Bound resource endpoints recover and verify offering scope;
they never trust old ownership for an official record. This transitional
compatibility is deliberate, not a claim that existing teacher copies have
already been reconciled institutionally.

## Four user journeys and setup guide

### Super admin

1. An authorized operator provisions the first school-email admin using the
   existing CLI `classlink:make-super-admin`; there is no public self-promotion
   endpoint or production password. Provisioning is audited by user ID.
2. Open **Vie scolaire**. Create the academic year, real official group(s), and
   module definitions. No six/ten/23 limits or fabricated school records exist.
3. Validate real teacher identities in **Administration → Utilisateurs**.
   Assign each teacher explicitly to the correct offering. Co-teaching uses
   multiple assignments; replacement revokes the old assignment and grants the
   new one. A teacher request records intent only and cannot grant access.
4. Import an authorized CSV roster with `student_identifier;email;display_name`.
   Preview conflicts/new accounts, then confirm. Existing identities are matched
   by stable identifier/email; conflicts reject the commit. No email is sent.
5. Appoint a coordinator and explicitly decide whether roster management is
   granted. Ordinary module assignment does not grant roster management.
6. Approve admissions, appoint at most two delegates with an expiry, review
   assignments/roster and activate invitations. A single ready group can pilot.
7. Transfers preserve old enrollment periods and grades. Archive/year rollover
   creates a new year/group/offering context; it never copies or moves grades.

Advanced admin APIs cover module-only access grants (reason/expiry), invitation
regeneration/toggling, group metadata changes, and year archival. These are not
all surfaced as dedicated polished wizard steps yet; review them before broad
operator rollout. Delegate notices are disabled by default and require the
admin's explicit `delegate_notices_enabled` group setting.

### Formateur

Open the assigned module, manage resources/assignments and existing practice
tools. Official assignments begin as drafts; publishing enables submissions;
closure blocks new deposits but preserves feedback/history. Default is one
submission, late deposits flagged by the server, no automatic resubmission or
retroactive lateness rewrite when a deadline is extended.

Create an official assessment with date, positive maximum/weight, and eligible
roster snapshot. Enter grades/status/individual feedback, then **Enregistrer le
brouillon**, or download CSV/XLSX, fill the template, preview errors/overwrite
effects and confirm the draft import. Omitted rows stay unchanged. CSV supports
semicolon/comma/tab and UTF-8/Windows-1252 (grade CSV also supports BOM UTF-16).
Grade decimals accept comma/dot and at most two fractional digits. XLSX macros,
formulas, external XML entities and oversized archives are rejected/never evaluated.

An ungraded candidate prevents publication; absent/exempt/pending makeup are
explicit statuses with no numeric score. Publish with a summary. A correction
requires a reason and creates a new draft; the prior published revision remains
visible until republication. Conflicts require reload, not blind overwrite.
Roster changes require deliberate reconciliation; historical rows are retained.
Adding new candidates to a frozen assessment is an explicit API operation and
needs further UI refinement; the current UI can confirm retention of the old roster.

### Stagiaire

Use Microsoft when tenant consent allows it, or the school-email OTP fallback.
An account without a group sees admission next steps rather than fabricated
classes. Request admission with an active code; it remains pending until an
authorized decision. Once approved, use the group's published module spaces.
Read only your published results/feedback in **Mes notes**, privately export
your own results, and open a linked clarification with currently assigned staff.
Practice scores remain separate from institutional results.

After transfer/removal, live group/module content is revoked; personal published
official results remain on their original context. Grade-linked private
clarification retains its personal-history eligibility while the original
offering's relevant staff is assigned and the group permits new activity.

### Délégué

This is an expiring group responsibility, not a platform role. Use only current
membership's representation/contact/organizational threads. Do not relay original
private messages automatically; summarize the issue. You cannot read classmates'
grades/submissions, appoint delegates, grant admission or assign teachers. A
replacement never inherits the previous delegate's private threads. Persistent
representation threads retain explicit participants; create a new thread for a
replacement rather than silently transferring private history.

## Privacy and operational defaults

In-app notifications contain IDs/deep links and generic messages, not grades,
feedback or conversation bodies. Private support reports expose only the
reporter's summary and response to the reporter and named admin; they never
grant hidden access to the thread. No message attachments are supported yet.
Administrative grading override and unrestricted moderation browsing are not
implemented. Staff-only grade notes are not supported or mapped from imports.
PNG/JPEG resources are checked by actual MIME/image dimensions (maximum 4096
per dimension); SVG/active content is rejected and private downloads remain
authorized. Previously issued S3 URLs expire according to existing short TTL;
revocation blocks new authorization but cannot retract already-read content.

School displays use `Africa/Casablanca`; raw SQL UTC timestamps are normalized
before browser formatting. No production timezone variable was changed. OAuth
query strings are excluded from nginx/FPM access logs. Unexpected institutional
errors log class/route metadata, not query bindings, grades or private text.
Temporary `/me` outages preserve the token and offer retry without granting
initial protected access; actual 401/403 still removes local authentication.

## Verification evidence (current local work)

- SQLite full backend: **573 passed, 2,256 assertions**.
- Isolated native PostgreSQL **16.14** full backend: **573 passed, 2,256 assertions**.
  Prepared in the approved temp directory, loopback only, synthetic test database,
  no Aiven connection or production environment change. Populated legacy upgrade
  preservation passed on both engines.
- Frontend: **91 tests passed / 8 files**, TypeScript and Vite production build pass.
- Infrastructure shell tests: **17 passed**; Bash syntax and scoped formatting
  checks pass. Actual Alpine Docker build/FPM runtime still requires CI/Render.
- Existing Chromium/Firefox browser gate: **30 passed, four skipped**. The four
  optional legacy-demo API checks require `CLASSLINK_LOCAL_API`, which was not
  configured. The separate institutional pilot below uses its own real local API.
- `npm --prefix scripts run school-pilot`: actual local PHP API + Vite + Chromium,
  isolated SQLite, admin/two teachers/student/delegate. Shared roster, two modules,
  resource/assignment, browser grade import/publication/correction, own results,
  nonparticipant rejection, revocations and 360px results passed. Token fixtures
  are synthetic and local-only; no real email is sent. This is not live Microsoft,
  SMTP, R2 or Docker verification.

Run backend engines sequentially locally: fake filesystem roots are shared and
parallel full runs can contaminate one another. Tests/runner output is evidence
for these commands, not a claim that every school policy/scenario is complete.

## Deployment/recovery and blockers

No changes have been committed/pushed/deployed for this broad task. No Jira write
or real-user message has occurred. Production provider settings and secrets are
unchanged. Review this working tree together with the earlier storage/runtime
changes before any separately authorized release.

For an approved release: private backup → restore/verify in staging → apply
additive migrations → finish backend rollout → deploy frontend → synthetic staging
pilot → operator-authorized real group setup. Startup already uses normal
`migrate --force`; never use fresh/reset/seed on production. Use forward fixes
after institutional data exists. Schema rollback drops new grade/message tables
and can change visibility semantics; restore a coherent backup rather than
blindly rolling back a live populated installation.

Still blocked / deliberately deferred:

1. Reviewed legacy class/module mapping and authorized real roster (including
   DEVOWFS204 context): no actual mapping/import or fabricated identities.
2. OFPPT tenant administrator consent; publisher-domain verification is not a
   replacement for tenant consent. Keep OTP available; do not weaken identity.
3. Actual Docker/Render restart, private R2, SMTP and deployment validation.
   Old unnamed critical-process logs cannot establish which child exited; the
   preserved supervisor patch names processes/statuses after an authorized release.
4. Institutional averages/rounding/absence rules, certified transcripts, admin
   grade-correction privileges and broader conversation-transfer policy. Averages
   are disabled; no invented OFPPT policy is applied.
5. Polishing all advanced lifecycle/admin actions and frozen-roster additions
   into dedicated screens, and reviewed legacy-content conversion. New official
   AI generation is not integrated yet; existing legacy AI remains reviewed-draft
   only with worker access rechecks. Manual practice and core workflows remain usable.

## Proposed Jira CL updates (not synchronized)

No issue IDs invented. Match these exact titles to existing CL items or create
them only after explicit authorization:

- Replace single-teacher class ownership with academic-year official groups.
- Add module offerings, explicit co-teaching/replacement and teacher setup requests.
- Add coordinator roster permission, stable roster imports and audited transfers.
- Add scoped delegates with two-slot database invariant and expiry/revocation.
- Add official assessment revisions, stable-ID CSV/XLSX preview and draft commit.
- Add atomic grade publication/correction and deduplicated notification outbox.
- Add participant-scoped communication, audience previews and private reports.
- Integrate FR/EN school workspace, personal results and Casablanca timestamps.
- Verify populated upgrade on PostgreSQL and synthetic browser pilot.
- Review legacy mapping, institutional grade policy and production runtime evidence.

Status: institutional core implemented and locally verified; broader rollout and
supervised **real-school** pilot remain conditional on the blockers above.
