# ClassLink — Workspace Migration and Ownership Design

**Phase:** A.1, proposed implementation design for review. No schema or application behavior is implemented by this document.

**Authority:** [Approved Adaptive Product Architecture](CLASSLINK_ADAPTIVE_PRODUCT_ARCHITECTURE.md), especially its approved decisions. This document resolves its six design blockers; it does not authorize the next phase.

**Notation:** `Wc` is the single explicitly seeded OFPPT compatibility workspace. Proposed entity/table names describe a concrete logical design, not migrations already written. “Current” means inspected source, not a live database inventory or fresh test result.

## 1. Decisions and invariants

1. Global accounts, global roles, Microsoft/OTP authentication and account eligibility remain global and authoritative. Workspace management never grants global admin, institutional teaching/grading, private-thread access or unrelated institutional powers.
2. Approved Teacher/Admin accounts may create workspaces. New generic resources receive explicit scoped policies; existing institutional policies are retained and evaluated with verified workspace containment.
3. All existing institutional and historical teaching data belongs to `Wc`; existing identities remain global and receive a compatibility association. No classroom/module/name-based merging. Existing IDs, authorship, dates, grade revisions and foreign-key semantics are preserved.
4. `Wc` uses the OFPPT preset, all **currently implemented** capabilities enabled, and migration-completed onboarding. Existing users never enter onboarding merely because this migration occurred.
5. The permanent owner of `Wc` is explicitly unassigned. A reviewed bootstrap manifest can designate existing approved admins as managers; it cannot infer or assign a permanent owner.
6. Active capabilities and retained history are separate action contracts. Disabling preserves data; it cannot manufacture a permission. Re-enabling restores neither revoked membership nor expired assignments/delegate appointments.
7. Attendance, flexible progress tracking, certificates and lesson scheduling remain future capabilities. Existing deadline aggregation and quiz progress do not implement those workflows.
8. Workspace creation outside `Wc` remains closed until isolation covers web requests, jobs, caches, exports and files. No old unscoped application or worker may serve data after independent workspaces open.

## 2. Resource and query ownership matrix

### 2.1 Containment convention

**Root:** persist `workspace_id` on an independently addressed domain root. **Inherited:** traverse explicit foreign keys to that root; do not add redundant workspace IDs to every row. Author/recipient/account IDs are not workspace ownership.

Where a root also references another workspace-owned record, require both to be in the same workspace. Use composite foreign-key integrity where practical. For inherited multi-parent records such as offerings, enforce parent-workspace equality with database integrity triggers on PostgreSQL/SQLite, including preventing parent reassignment, as well as transactional service validation. Never accept inconsistent parent IDs just because each exists. A missing/orphaned parent fails closed and enters a repair report; it is not assigned by name or requester preference.

Existing data belongs to `Wc` because of the approved installation-wide migration boundary, not because a user's email domain, Microsoft tenant, role or classroom name implies tenancy. The migration manifest records the population/cutoff; delta writes must be captured until cutover.

### 2.2 Domain mapping

| Domain | Current ownership / authorization | Future workspace relationship | Migration strategy | Compatibility risk |
| --- | --- | --- | --- | --- |
| Users | Global identity, one global role, account activation, unique email/Microsoft identity; school identifier on user. | Remain global. `WorkspaceMembership` provides association; `Learner` holds workspace-local school identity. | Preserve every user/role/token identity; seed unique `Wc` associations without activating pending/denied/inactive users. Bridge existing student identities separately. | Adding `users.workspace_id` would prevent multi-membership; using member status to override global restrictions would weaken auth. |
| Historical classrooms | `classrooms.teacher_id`, memberships and legacy policies; `is_official = false`. | Root `classrooms.workspace_id = Wc`; retain historical subtype/teacher owner. | Backfill all existing classrooms, including archived; do not convert them to official or generic classes. | Deleting teacher ownership or auto-mapping similar names can strand content or alter authority. |
| Official groups | Same `classrooms` table, year/coordinator/enrollment semantics; no legacy teacher owner. | Root workspace on classroom; academic year must share it. | Keep official code, coordinator, roster version, archive state and year links unchanged. | Treating a group as teacher-owned bypasses offering rules; workspace manager is not automatically coordinator. |
| Academic years | Global names; date/status/version drives group writability. | Root `academic_years.workspace_id`. | Assign `Wc`; add workspace/name uniqueness before eventually removing global-name uniqueness. | Old global lookups, timezone/date/archive logic and trigger constraints must retain behavior. |
| Modules | Global `school_modules.code`, version/status. | Root `school_modules.workspace_id`; institutional catalog, distinct from generic courses. | Assign `Wc`; scope uniqueness to workspace/code. | Duplicate codes across workspaces make unscoped code lookup unsafe. |
| Offerings | Group/module pair, active status/version; access via assignment/enrollment/grant. | Inherit workspace from classroom; module must match that workspace. | Preserve IDs and unique group/module pairs; validate both parents. | A group/module cross-workspace pair must be impossible; ownership is not the creating teacher. |
| Teaching assignments | Offering + teacher + period/active key; assigned by authorized admin. | Inherit offering workspace; teacher must have corresponding membership for active teaching. | Keep periods, active keys and assigning actor; derive associations without reopening ended assignments. | Co-teaching/replacement must remain; an owner/manager grant cannot create an offering assignment. |
| Enrollments | `school_enrollments`, student/year/group, dated periods and active-student key. | Inherit group workspace, with year equality; keep user-based institutional student FK. | Preserve periods, transfers and uniqueness; seed learner bridges by exact student ID. | Enrollment is not workspace membership; one primary group per year remains local to that year's workspace. |
| Memberships | `memberships` stores classroom admission/accepted/removed states. | Inherit classroom workspace. New `workspace_memberships` is a different relation. | Preserve status/history representation and synchronize only through existing domain services; report drift against enrollment rows. | Renaming or conflating the two relations could accept admissions or revoke historical access unintentionally. |
| Delegates | Group, enrolled student, bounded dates, active slot, revocation. | Inherit group workspace; scoped student responsibility. | Preserve limit, slots, periods and revocations. | A membership/manager flag cannot grant delegate privileges; migration cannot restart appointments. |
| Assignments | Classroom + author; optional offering scopes official teaching content. | Root `assignments.workspace_id`; existing classroom/offering retained; future generic records use a teaching context instead. | Assign `Wc`, validate classroom/offering provenance; preserve draft/published/closed state and deadlines. | An official record with missing/inconsistent offering needs reviewed repair, not a legacy fallback. |
| Submissions | Assignment + `student_id`, file, grade/feedback; own-view and assigned-teacher predicates. | Inherit assignment workspace. Existing user FK stays; new generic submissions additionally reference learner/link provenance. | Preserve IDs, unique assignment/student, file metadata, lateness and grading. | Repointing historical student IDs during linking would transfer another principal's work. |
| Quizzes / attempts / answers | Classroom/optional offering; author, publication/editor/review state; attempts belong to user. | Quiz root `workspace_id`; attempts, questions/options, answers inherit quiz workspace. | Preserve order snapshots, answers, scores, attempt states and review requirements. | Global personal-progress aggregation, active-attempt lookup and exports must be scoped; draft answer keys remain private. |
| Flashcards | Deck classroom/optional offering and publication/review; cards/reviews belong to deck/card and user. | Deck root `workspace_id`; cards/reviews inherit. | Preserve deck/card IDs and study history. | Per-user study keys/cache and direct card URLs must not escape deck containment. |
| Official assessments / grades | Offering; candidate user snapshot; revision chain, publish pointers, entries/import batches. | Inherit offering workspace through assessment. | No grade rewrite. Preserve candidates, scores, states, locks/versions, pointers and revisions; import batches inherit assessment. | Owner/admin is not grader; own published results survive appropriate enrollment departure. Never move grades during transfer. |
| Announcements | Classroom/optional offering and author; school notices also exist as threads. | Announcement root `workspace_id`; validate teaching parent. Notice threads follow communication mapping below. | Assign `Wc`, retain visibility and author. | Do not conflate a classroom announcement with a private/audience-snapshotted notice. |
| Conversations / messages | `school_threads` optional group/offering/assessment; explicit participant + continuing eligibility. All-account notices can lack classroom. | Thread root `workspace_id` is mandatory, including group-less notices. Participants/messages inherit it. | Assign all existing school threads to `Wc`; preserve participants, eligibility, read cursors and message authors. | A parentless notice still needs scope. Never rebuild participants from current workspace members or let managers browse private threads. |
| Resources / files | Material classroom/optional offering; submission/AI files have separate record owners; private storage and signed downloads. | Material root `workspace_id`; other objects inherit their owning submission/job. File path is not an ownership boundary. | Backfill metadata, retain object keys and bytes; route reads through authoritative record ownership. No bulk file move required. | Public/path-only access, object deduplication and already-issued signed URLs can outlive access decisions. |
| Notifications / outbox | User recipient, type/payload, optional outbox link; source IDs/deep links; outbox delivery scans globally. | Explicit event scope: workspace + source reference, or platform/account event. Receipt remains private to recipient. | Source-resolve known events to `Wc`, including deleted-source events of known pre-cutover workspace types; preserve receipts/dedupe keys. Unknown scope enters restricted unresolved metadata, not a guessed workspace. | Recipient's selected workspace is not event scope. Outbox batches need per-event context and authorization checks. |
| Audit | Append-only actor/action/context, sometimes global authentication/admin actions. | New immutable scope sidecar per audit event: platform or workspace, optional authoritative target reference. | Do not edit existing audit rows. Append scope associations from unambiguous targets; unknown historical events stay platform-restricted/unresolved. New events write event and scope transactionally. | Actor membership cannot infer event scope. Workspace audit must not expose global auth logs or sensitive cross-workspace context. |
| Support / reports | Report links thread, reporter and specifically assigned admin; assignment does not expose all thread history. | Inherit thread workspace; retain named reporter/assignee authorization. Separate platform account-support scope where needed later. | Preserve report content, assignment and response; no automatic reassignment to workspace owner. | Support is non-disableable infrastructure, not an excuse to bypass private-thread participation. |
| AI settings / jobs | Provider credentials/priority/quota are application-wide; jobs reference teacher/classroom; generated-content cache keyed by type/file hash. | Provider configuration and provider quota remain platform-owned. Job root `workspace_id`; draft outputs inherit context. Content cache includes workspace ID. | Preserve provider rows and global security authority; assign legacy jobs to `Wc`. Stop using old unscoped content cache before multi-workspace opening. | Workspace owner must never obtain provider secrets/reset global quotas. Identical file hashes cannot make private generated content cross workspaces. |
| Access grants / setup requests | Offering access grants; teaching/setup requests can contain requested labels without offering. | Grants inherit offering. Request root `workspace_id` covers requests without a parent. | Assign `Wc`, preserve decision/expiry and actor. | Global pending-request inbox or admin recipients must not include unrelated workspaces. |
| Roster / grade import batches | Group or assessment plus actor, expiry, roster/version snapshot, rows/errors. | Inherit authoritative group/assessment workspace; preview records bind context and configuration revision. | Preserve committed results; expire uncommitted old previews at cutover and require fresh preview. | A preview token is not authority to commit after scope, capability or actor changes. |
| Study partners | Global user profile/opt-in, requests with classroom or official group context. | Workspace-local partner profile; requests inherit referenced class/group workspace. | Seed existing profile only into `Wc`, preserving existing opt-in there; no automatic opt-in in newly joined workspaces. Preserve request context. | Global skills/availability or matching can reveal another workspace's people; generic profile preferences must not imply consent everywhere. |
| Storage usage / security infrastructure | Global capacity/reservations; sessions/tokens, OTP, cache/queue infrastructure. | Capacity/provider budgets and authentication infrastructure remain platform-scoped. Resource reservations record workspace attribution, not a grant of file access. | Preserve totals, reservations, session identities and auth semantics. Add attribution without resetting budget. | Per-workspace totals must not expose platform capacity or imply new quotas; no blanket workspace scope on auth tables. |

### 2.3 Query and side-effect coverage

The following are the mandatory query families for later implementation review; this is a source-based coverage inventory, not a claim that every SQL statement has been changed or tested.

| Family / source entry points | Required ownership predicate and behavior |
| --- | --- |
| `SchoolController::index`, `people`, contacts, assignment requests, setup-request recipients | Workspace-scoped years/modules/groups/offerings, then existing actor eligibility. People lookup intersects eligible workspace memberships; admins for notifications must be in the same workspace. No global user directory from workspace settings. |
| `SchoolAccess`, `SchoolSetupService`, `InstitutionalWriteTransaction`, `InstitutionalLegacyBoundary`, `ScopedToOffering` | Resolve resource containment and preserve offering/enrollment/grant locks, archived/year rules and existing policies. Check both endpoint families, including archived offerings and concurrent revocation. |
| `SchoolTeachingController`, content/assignment/quiz/flashcard controllers and policies | Scoped collections and direct records. Rebind parent and record consistently; author/user ID alone never chooses workspace. |
| `OfficialGradeService`, `OfficialGradeController`, roster/import services | Scope assessment graph before selecting entries, candidates, templates, import rows or recipients; retain exact version, actor, roster and publication checks. |
| `SchoolCommunicationService`, `SchoolNoticeService` | Scope thread query before participant/eligibility filters. `audience=all` means eligible members of this workspace, never all global accounts. Cache preview binds workspace, actor, audience and revision. |
| `ProgressionService`, deadline/content feeds, partner endpoints | Aggregate only through the resolved workspace and authorized source records; disabled source features do not leak into active totals. Historical aggregates are explicitly history-mode and exclude drafts/private fields. |
| `AdminController` | Existing global user-role/security/provider operations remain platform-admin-only; global statistics remain explicitly platform scope. New workspace statistics filter workspace roots and keep private teaching data out. Never silently relabel global statistics as workspace health. |
| `NotificationService`, `NotificationController`, `DeliverSchoolNotifications` | Per-recipient inbox plus verified event scope/source visibility. Global personal inbox can combine sanitized receipts across workspaces; workspace inbox/count is filtered to that workspace. No receipt grants resource access. |
| `AuditLog`, reports/support endpoints | Workspace audit joins immutable event scope and uses redacted approved fields; platform audit remains global-admin only. Report permissions remain named-assignee/reporter, separate from thread read. |
| `ProcessAiGeneration`, `AiService` | Resolve job workspace before provider call and before draft commit; content cache namespace contains workspace, target, content hash and prompt/schema version. Provider cooldown/quota can remain global; teacher limits remain at least as restrictive as today. |
| `MaterialStorageService`, submission downloads, AI inputs, signed URLs | Resolve authorized owner record before opening a file or issuing any signed URL. Object paths/filenames or storage prefixes alone are never sufficient. |
| Queue, scheduler, commands, imports/exports, cache | Explicit context for each work item; key caches by workspace and relevant principal/configuration revision. Global dispatchers may enumerate workspace work without adopting one ambient tenant for the entire process. |

Both Eloquent and raw `DB::table` queries need explicit coverage. Scoped model queries are defense in depth, not proof of isolation. Repository methods, cache keys, exports and commands require the same review as routes.

## 3. Concrete logical relationships

### 3.1 Workspace, membership and management

| Entity | Proposed keys / relationships | Integrity and lifecycle |
| --- | --- | --- |
| `Workspace` | Stable ID, immutable unique public key, identity/name, kind, timezone, status, ownership state, nullable `owner_membership_id`. | Status: provisioning, active, archived. Ownership: owned, unassigned_compatibility, recovery_required. Owner reference must be to a membership in this workspace. Only `Wc` can start unassigned. Archived state stores prior onboarding/configuration, not deleted data. |
| `WorkspaceMembership` | ID, workspace FK, user FK, status, joined/ended timestamps, version. Unique `(workspace_id, user_id)`. | Status: active, suspended, left. Global role is not copied here. Rejoining reactivates this association with append-only lifecycle events; no duplicate membership identity. Global inactive/pending/denied still prevents application use. |
| `WorkspaceManager` | Membership FK, granted/revoked times, granted_by; at most one active grant per membership. | A scoped configuration responsibility, not a replacement role enum. Owner authority derives from owner reference, not an independently mutable owner flag. Manager must be active and globally eligible Teacher/Admin. Preserve grant history. |
| `WorkspaceConfiguration` | Unique workspace FK, preset ID/version, terminology ID/version, validated terminology overrides/preferences, integer revision. | One authoritative current configuration. Bounded JSON only for structured preferences/forms; revisions and audit store changes. Preset updates never mutate a workspace snapshot implicitly. |
| `WorkspaceCapability` | Workspace FK + stable registry identifier, enabled state; unique pair. | Explicit rows for the registry snapshot provisioned for this workspace. Unknown/unavailable capability cannot activate. Changes lock configuration and increment its revision atomically. No independent settings blob duplicates this state. |
| `WorkspaceOnboarding` | Unique workspace FK, version, status, current step, validated draft answers, completed_at, completion_origin. | Status: draft/completed. Origin distinguishes user setup from migration. `Wc` is migration-completed; new creator-only/manager-authorized setup is resumable. Joining a workspace never creates onboarding. |

Foreign keys to a user indicate identity/provenance, not the user's workspace role. Keep authentication outside this containment graph. An account whose global role changes is re-evaluated at each action; workspace management cannot keep an otherwise ineligible account authorized.

### 3.2 Learners, courses, classes and contexts

| Entity | Proposed keys / relationships | Integrity and lifecycle |
| --- | --- | --- |
| `Learner` | ID, workspace FK, display name, optional workspace-local external/school identifier, active/archived state. | No required email, password, fabricated user or account. Unique nonempty external identifier within workspace. Display names are not unique. Staff-only contact fields are optional and not public identity evidence. |
| `LearnerAccountLink` | ID, workspace FK, learner FK, user FK, created/confirmed/ended timestamps, verification provenance and actor. | Append-only link periods. At most one active account per learner and one active learner per user per workspace. Referenced learner must share workspace. Account must be globally eligible Student; teacher-as-learner is deferred with per-workspace roles. |
| Generic `Course` | ID, workspace FK, optional local code, title, archive state. | Separate from institutional `school_modules`; optional in teaching contexts. No academic year required. |
| Generic `Class` | New `learning_classes` entity: ID, workspace FK, name, status; no academic-year/module requirement. | Distinct from historical/official `classrooms`. Generic learner rosters reference Learner, not compulsory User accounts. No bulk conversion of legacy classrooms. |
| `ClassLearnerMembership` | Generic class FK + learner FK, start/end/state. | Same-workspace membership periods. Does not imply login access; actor still needs an eligible account link and workspace membership. |
| `TeachingContext` | ID, workspace FK, kind, nullable generic_class_id, learner_id, offering_id, course_id. | Kinds: class, individual, institutional_offering. Exactly one of class/learner/offering is set matching kind. Course is optional for generic kinds and absent for offering contexts. Explicit FKs, not an unconstrained polymorphic target. |
| `TeachingContextTeacher` | Context FK + teacher membership FK, period/state, appointing actor. | Generic contexts only: grants explicit teaching scope to eligible global Teachers. Managers may allocate generic work but Admin alone cannot teach/grade through management. Institutional contexts continue to derive teaching from existing `teaching_assignments`, never this table. |

The schema implementation must enforce exactly-one parent and same-workspace relationships with checks/composite keys or equivalent integrity mechanisms supported on PostgreSQL and SQLite. Context parent/type is immutable after activity exists. A learner/class can have several contexts for separate courses; do not enforce global uniqueness on its parent. There is at most one adapter context per institutional offering.

Active learner-link uniqueness uses partial unique indexes for open link periods on learner and on `(workspace_id, user_id)` on both target database engines; ended periods remain immutable history. Owner-to-membership workspace equality uses a composite relationship or an equivalent database integrity trigger. Physical migration syntax is verified during the later schema rehearsal, not substituted with application validation alone.

Existing learning content remains in its present tables. When generic workflows are implemented, add a teaching-context relationship to content roots and relax classroom-required structure **only for new generic records**, with an exact parent-mode constraint:

- Existing/historical mode: classroom required; institutional offering required when official; generic context absent.
- Generic mode: generic context required; classroom/offering absent.
- The root `workspace_id` must match its authoritative mode's parent. Institutional adapter contexts do not create a second competing content owner.

This later parent-mode expansion is separate from initial workspace backfill. Generic submissions/activities target Learner and capture the actor/link ID at creation. Existing official assessments/grades remain user-based. Flexible evaluation never uses official grade revisions as a shortcut.

### 3.3 Additional design records

- `WorkspaceInvitation`: workspace, purpose (member/manager/learner-link/owner-transfer), issuer, intended verified recipient or nominated learner, hashed one-use secret, expiry, status. Invitations are not memberships until accepted and cannot assign global roles.
- Ownership-transfer requests reference current owner, target membership and workspace ownership version. Membership/management/ownership events preserve history rather than overwriting audit provenance.
- `AuditEventScope`: immutable sidecar keyed by original audit ID with platform/workspace classification and source reference. Original append-only audit content is unchanged. Workspace views redact global/private context.
- Notification/outbox scope metadata: source kind/ID, workspace or explicit platform/unresolved classification, configuration revision and delivery disposition. Existing receipt identity/deduplication stays intact.
- A migration manifest/checkpoint ledger records exact seeded workspace key, batch ranges, mappings, validation counts and unresolved records. It is not a new source of runtime authorization.

Use restrictive deletion for ownership, learner/link and history-bearing relationships. Existing cascade edges are not invoked by membership departure, disable or archive. Hard deletion of accounts/workspaces is outside this migration and must not be exposed through workspace settings.

## 4. Disabled-feature and retained-history contract

### 4.1 Authorization modes

Two explicit modes exist: **active workflow** and **history read**. History is not an alternate permission system.

- Active mode requires active workspace membership, enabled implemented capability, authorized actor/object and writable lifecycle.
- History mode requires globally eligible authenticated account, proven resource workspace and an allowlisted historical action, plus the existing object's read/participant predicate. Workspace membership alone is insufficient.
- A left member can use only explicitly retained personal history: own published grades under the existing grade-owner rule, own practice records where existing policies permit, and grade-owner conversations while their existing eligibility holds. No new class/resource entitlement arises from departure.
- Suspended members are denied workspace data, including history, until suspension is lifted; use this for workspace security revocation rather than treating voluntary departure as a security block. Global account suspension remains authoritative everywhere.
- Existing classroom submission policy requires accepted membership for the student's own read. Preserve that rule: a removed student does not gain a new submission-download entitlement through this design. Staff still need current existing teaching/ownership authority to read retained work.

### 4.2 Action rules when disabled

| Surface / operation | Exact disabled behavior |
| --- | --- |
| Active dashboard/nav, discovery, new recommendations | Hide entry/actions; exclude source records from active totals/deadlines. A separate history entry is shown only if eligible retained records exist. |
| Ordinary collection/read endpoint | Reject active-mode request; do not return its normal live collection. Explicit `mode=history` on approved read actions or a dedicated history collection returns only authorized retained projection. `mode=history` is server-validated, not a bypass switch. |
| All domain writes | Reject create/update/delete, publish/correct, grade/import, submit/replace, reply/resolve, start/resume attempts, review cards, invite/appoint through the disabled workflow. No deletion is performed. |
| Configuration/security/support writes | Still available under their own authority: enable/disable settings, invitations for membership administration, report/support handling, audit, token revocation and notification read markers. They do not mutate disabled teaching records or grant their access. |
| Direct historical record URL | Resolve/authenticate/authorize first; if history-allowlisted, return read-only projection and disabled state. Otherwise return authorized feature-disabled response. Do not redirect into a different workspace. |
| Downloads / attachments | Allow only when parent is history-readable and that file was already visible under existing authorization. Preserve original privacy and ownership. Resource downloads additionally require history-readable `resources`; a submission attachment belongs to submissions, not the resources toggle. |
| Existing attachment/link not represented by an authorized owner record | Deny. A known path, shared signed URL, filename or membership is not ownership proof. External web links retain the external provider's behavior; no promise of revoking third-party access. |
| Exports | Only existing read-only exports over history-readable data are allowed (own published grades and authorized prior quiz results). No new bulk roster/messages/grade exports are invented. Generation and download both recheck eligibility; import templates/previews/commit are active workflow and blocked. |
| Notifications already stored | Preserve receipt and read state. Return minimal authorized historical description; link goes to history projection if allowed, otherwise neutral unavailable state. Redact source-derived details when access is lost. Mark-read remains allowed for recipient. |
| New notification/outbox delivery for disabled workflow | Mark suppressed with reason/revision; retain event, do not broadcast or accumulate an automatic replay backlog. Security/support notifications continue. |
| Queued domain jobs / delayed actions | Check before start and before commit/provider/side effect. Disabled => canceled/suppressed terminal outcome, preserved provenance, no auto-resume on re-enable. New request required. |
| Cleanup and maintenance | Security expiry, operational temp cleanup and audit may continue; disabling never schedules deletion of user records or durable attachments. Existing approved retention is separate from capability behavior. |
| Re-enable | Refresh current configuration; restore ordinary authorized entry points. Do not replay old jobs/invitations, reopen closed work, or restore revoked responsibilities. |

### 4.3 Resource-level history projections

| Capability / domain | Allowed retained read | Exclusions / dependencies |
| --- | --- | --- |
| `official_grades` | Student's own published revision/feedback and existing own export; currently assigned offering teacher's retained editor/history projection, including existing drafts/revisions they are authorized to see. | No new draft save/publish/correction/reconciliation/import/template. Admin/owner never acquires grading access. Students never see drafts or other candidates. Departure does not remove existing own published-result entitlement. |
| `grade_import` only | Official-grade reads remain available if `official_grades` is enabled. Existing committed assessment revisions remain. | Block import preview/commit and import template; do not expose raw batch rows/errors as general history. |
| `assignments`, `submissions` | Existing authorized published assignment detail, own submitted file/feedback, and assigned-teacher submission view in read-only mode. Existing teacher draft visibility stays private. | Disabling assignments blocks dependent submission writes. Disabling submissions alone leaves assignment creation/content workflow if enabled, but freezes submission operations. Parent assignment must be history-readable to expose submission. |
| `quizzes`, `quiz_progress` | Own completed attempts/results; authorized teacher existing results/export. Quiz definition visible only under existing role-specific projection. Quiz-progress history derives only from history-authorized attempts. | No new/resumed attempt, answer writes or submit. In-progress answers retained but not exposed as completed results; no new student answer-key visibility. Disabling quiz_progress alone does not disable quizzes. |
| `flashcards` | Previously authorized published deck/cards and own recorded reviews. | No new review writes, publish or editing; no private draft exposure. |
| `resources` | Previously authorized material metadata and download via its class/offering/context read policy. | Revoked enrollment/grant/assignment continues to restrict access. Archive/disable does not grant perpetual access to every past member. |
| `announcements` | Existing authorized published announcements and audience-snapshotted notices. Notice body belongs to announcement history even though stored as a thread. | No publish/edit/comment. For notices, explicit participants and continuing eligibility still apply. |
| `messaging` | Existing thread/messages only for explicit participants who still satisfy the current eligibility predicate; report view is separately scoped. | No reply/new conversation/resolve. Replacement staff/delegates get no inherited access. Class-notice reads are governed by announcements; appending any message additionally requires messaging and a writable notice workflow. |
| `academic_years`, `institutional_modules`, `teaching_assignments`, `delegates`, `admissions`, `roster_import`, `transfers` | Minimum structure needed to interpret permitted records; existing authorized administrative/roster historical views remain read-only under their original predicates. | No self-service assignment/enrollment/appointment/import/transfer. Disabling a prerequisite requires explicit confirmation of all dependent active workflows; never break a valid saved configuration silently. |
| `historical_classes` | Explicit history access to legacy classes and eligible content. | Feature means legacy/historical workspace workflows, not a switch that removes all data retention. Writes require this capability and their own capability; history ignores this active toggle only for the enumerated reads above. |
| `calendar` | Explicit history view of previously authorized dated work. | Hide active deadline feed if disabled; cannot infer a future scheduling implementation. Disabled source workflows do not reappear as actionable deadlines. |
| `study_partners` | Recipient's own prior request status where current authorization permits. | No matching/discovery, profile publication, new requests or accepting requests. No exposure of other workspaces' availability. |
| `ai_tools` | Own authorized prior job outcome and generated draft through the draft's parent policy. | No generation/retry/provider call. A generated quiz/deck remains governed by its own feature; AI disable does not delete manually usable content. |
| Audit / support | Existing authorized event/report handling regardless of optional features. | A report does not reveal a thread to an assignee who lacks its separate read predicate. |
| Future capabilities | No endpoint or history claim until implemented. | Registry metadata cannot create attendance/progress/certificates/scheduling data or policy. |

An existing direct detail URL defaults to history projection when its feature is disabled **only** if the above resource action is allowlisted; active aggregate routes do not do this implicitly. Multiple relevant disabled capabilities must all pass their history rules. Source-derived notification text and download issuance use the same checks.

### 4.4 Concurrency and external side effects

Configuration changes lock the workspace/configuration revision. Domain commits take a compatible lock/check so a write is ordered either before disable (valid committed history) or after disable (rejected). Import previews capture revision and require fresh preview after change. Do not hold database locks across AI network calls: validate immediately before the external call, then recheck and reject draft creation after return if disabled. A call already sent cannot be unsent; record that outcome without publishing content.

Previously issued signed file URLs remain valid until their existing expiry. Do not claim immediate revocation. Issue no new URL except for an authorized active/history action. In-app projections use private/no-store responses where sensitive. Files already downloaded cannot be recalled. Generic new file paths should remain private and download authorization should use the owning record, with signed URLs limited by existing storage policy.

## 5. Workspace ownership lifecycle

### 5.1 Creation and first owner

An eligible active, approved global Teacher/Admin creates a workspace. One transaction creates provisioning workspace, active creator membership, owner reference, default validated configuration and onboarding draft. If creation fails, no partial usable workspace remains. The creator owns the new workspace without receiving any global or institutional permission.

For `Wc`, creation is migration-only: owner reference is null and state is `unassigned_compatibility`. Seed manager grants only for explicitly recorded existing approved active admins in the reviewed manifest. Missing eligible managers is a migration-operability failure requiring designation; do not choose another role or fabricate an account. The later explicitly designated owner must accept; no manager may claim the vacant owner field by self-service.

### 5.2 Managers and invitations

- The owner may grant/revoke manager responsibility to an active eligible Teacher/Admin member. Managers may configure identity/preset/terminology/features and allocate supported generic work within their scoped policies.
- Managers can invite ordinary members and nominate manager candidates. For an owned workspace, granting management requires owner approval; a manager cannot promote themselves or transfer ownership.
- In owner-unassigned `Wc`, adding/replacing bootstrap managers requires an explicit recorded designation by the existing platform authority; ordinary managers cannot self-elect owner. Platform intervention grants only the recorded management assignment, never access to teaching/private data.
- Invitations have explicit workspace/purpose, issuer, intended recipient, hashed random secret, 7-day expiry and one-use acceptance. Resend revokes the old invitation. Revoke/expiry does not remove an existing membership.
- Acceptance requires an authenticated eligible account with the verified intended identity and valid invitation, workspace lifecycle and issuer authority. An invitation cannot make an unapproved Teacher or a pending/denied account eligible. Learner linking uses the stricter process in section 6.
- Generic signup is not added by an invitation; unsupported email/provider eligibility is reported honestly. Do not create a generic auth bypass to make invitation UX succeed.

### 5.3 Transfer and departure

Ownership transfer is a two-party, versioned operation: current owner nominates an active eligible Teacher/Admin member; recipient accepts within 7 days. Reauthenticate through the existing login method before issuing/accepting a sensitive transfer. Acceptance locks the workspace, rechecks both accounts, atomically changes owner reference, records audit and keeps the prior owner as a manager unless they explicitly leave afterward. Transfer never moves domain records to another workspace.

Pending transfer is revocable and cannot coexist with an independent successful ownership change. No automatic transfer based on age, last login, billing, classroom authorship or email domain.

- An owner cannot voluntarily leave or remove their membership until transfer succeeds. A closing owner can archive the workspace and retain the owner membership; archive does not clear ownership or elect a successor.
- An owner may step down after transfer; departure removes configuration authority, not historical authorship or separately retained personal records.
- Manager self-departure/demotion is blocked if it would leave no eligible configuration authority (eligible owner counts). In `Wc` with no owner, at least one eligible active manager must remain.
- Global account suspension/role changes cannot be blocked to satisfy this rule. If they remove the last eligible authority, enter `recovery_required`, freeze management/new domain writes, retain allowed history, and require explicit designated recovery under existing platform authority. No automatic successor or self-appointment.
- Membership removal and generic access revocation are transactional with eligibility checks. Existing institutional enrollments/assignments require their normal authorized revocation workflows; owner membership controls cannot fabricate those operations.

### 5.4 Organization archive/shutdown

The owner, or designated `Wc` managers while ownership is unassigned, may explicitly archive after viewing affected workflows. Archive is reversible, not hard deletion: stop new teaching writes, invitations, new enrollment/assignment allocation and optional notifications/jobs; preserve configuration, members, objects/files, grades and revisions. Existing security/support and allowlisted historical reads remain.

Restore requires current eligible owner/designated management plus intact permissions and configuration validation. Do not restart suppressed jobs or extend appointments. Permanent deletion, legal retention and institution dissolution are separate future policy work; no workspace settings operation cascades through users or academic records.

## 6. Learner-account linking lifecycle

### 6.1 Account-less learner creation

An authorized generic teacher/roster manager creates a learner within their workspace/context. Collect display name and optional workspace-local identifier; contact details are optional private fields. Creation does not issue credentials, activate a global student, enroll them institutionally or grant messaging access. A duplicate name is permitted; duplicate nonempty local identifier returns a conflict for review.

For existing institutional students, seed a learner bridge from the exact existing user ID and school identifier in `Wc`, with migration provenance. Keep all current institutional FKs. Do not match on display name or transfer an account's official grades into another learner.

### 6.2 Invitation, identity verification and acceptance

1. Authorized staff selects a specific learner and intended recipient; create a single-use learner-link invitation with 7-day expiry, issuer and workspace binding. Contact details are not shown in public learner listings.
2. Recipient authenticates using existing supported Microsoft/OTP behavior. A bearer invitation is not authentication or proof of learner identity. Pending/denied/inactive/global non-Student accounts cannot link as learners during the current global-role model.
3. Confirm verified account identity matches the invitation recipient. A typed email, name match or localStorage claim is insufficient. Where the current identity provider/account record cannot demonstrate matching identity, stop automated linking and use explicit staff verification; do not weaken auth.
4. Require authorized staff confirmation of learner identity independently of mailbox control (existing roster/known-person verification). Staff can record this verification before invitation or confirm after acceptance; record method/actor/time, not sensitive evidence documents in audit payloads.
5. Transactionally lock learner/current links and account-workspace slot, enforce uniqueness and current staff authority, create active link and active workspace association, and record provenance. Existing eligible membership is reused, not promoted. Institutional enrollment is unchanged.
6. Show the recipient which records will become learner-visible. Prior offline teacher notes remain private. Generic pre-link learner-facing resources/evaluations are visible only when explicitly released under their own implemented policy; no automatic release of every learner record.

Staff-assisted linking for a learner without usable email requires authenticated recipient approval and recorded independent staff verification. If the learner cannot authenticate at all, retain account-less operation. Do not invent guardian/proxy accounts or identity verification bypasses in this migration.

### 6.3 Duplicate and conflict resolution

- One active link per learner and one active learner per user per workspace; enforce both under concurrent requests.
- If either is already linked differently, reject with a scoped conflict and expose no details about other workspaces/accounts. Repeating an already successful same-pair acceptance is idempotent.
- Revoke competing pending invites when a link completes. Two learner records for the same person require a separately reviewed consolidation operation; no automatic merge or historical FK rewrite.
- Existing account identity/email collisions retain the authentication service's rejection rules. Workspace managers cannot merge global accounts or alter global roles.
- A teacher/admin seeking learner access remains blocked by deferred per-workspace roles; they may not be silently converted to Student.

### 6.4 Unlinking and history

The linked student may request/end their generic learner association; authorized staff may unlink with reason after checking identity/context. Close the active link period, revoke outstanding invitations and linked access, record audit, and preserve Learner and all link periods. Do not delete user, enrollment, submissions, attempts, messages or grades.

All account-authored work retains original actor and link-at-creation. Existing official grades continue to belong to their original `student_id`. The former linked account retains only its existing explicitly authorized personal history, not ongoing access to the learner's new work. Relinking to a different account never transfers the old account's private submissions/conversations/results; new account sees only the current relationship and explicitly released learner-facing generic records. A wrong-identity correction is an audited recovery case requiring deliberate record-by-record policy, never a blanket relink rewrite.

If an account also has active institutional enrollment or another independent eligibility, ending a generic link does not revoke that separate authority. Institutional identity corrections require the institutional workflow and cannot be performed through generic unlink.

### 6.5 Privacy

Do not expose email, membership in other workspaces, raw linking evidence, private teacher notes or cross-account history in learner resources. Linking adds no conversation participant automatically and no retroactive class-notice audience. Workspace owners/managers can administer association metadata only within authorized scope; management alone does not expose learning records. Existing user profile and email-visibility policies remain in force and must be scoped where used for workspace discovery.

## 7. Trusted workspace context resolution

### 7.1 Canonical routes and API contract

New workspace-owned APIs use explicit routes such as `/api/workspaces/{workspace}/classes` and `/api/workspaces/{workspace}/contexts/{context}`. New browser locations use `/app/w/{workspaceKey}/...`. The public key/ID selects a candidate, not an authorization grant; opaque IDs are not security.

Resolution sequence:

1. Authenticate with existing mechanisms and enforce global active/role eligibility.
2. Resolve candidate workspace from explicit route or a documented compatibility rule. Do not use email domain, Microsoft tenant, last visited workspace or request body to infer ownership.
3. For collections/writes, load and validate active workspace membership and workspace lifecycle. For the enumerated personal-history reads, validate history eligibility instead of requiring an active membership; suspension still denies access.
4. Bind each resource through this workspace's authoritative graph, not an unrestricted `find(id)` followed by trusting a client field. Check every supplied secondary parent belongs to it.
5. Apply action-specific capability or history rule, unchanged object/role authorization and lifecycle constraints; obtain current configuration revision. Evaluate containment/authorization before exposing feature-disabled details.
6. Persist trusted context in the request/service scope and clear it on completion. Never reuse ambient state across queue items or long-lived worker requests.

Workspace context is server-derived metadata, not a permission. Reject an explicit resource/workspace mismatch with 404; unauthorized foreign resources are indistinguishable from missing ones. Authenticated users with no access cannot probe disabled features. Authorized disabled active actions return 409 with stable `capability_disabled` (or `workspace_archived`) code; unauthorized actions retain existing 403/404 contracts. Missing authentication remains 401. No workspace scope on `/me`, logout, OTP, Microsoft callback or session revocation.

Do not accept `X-Workspace-ID`, a query parameter, body `workspace_id` or a stored active-workspace preference as authority. Canonical route context wins; conflicting context fields are rejected, not used to reassign resources. Creation assigns workspace from the trusted context. Existing resources cannot be moved between workspaces by update payload.

### 7.2 Existing institutional and direct URLs

| Existing surface | Compatibility rule |
| --- | --- |
| `/api/school` and parentless legacy school collections/actions | Resolve explicitly to `Wc`, validate membership/existing role/context; never use the user's currently selected generic workspace. Empty results or denied access do not fall back to another workspace. |
| `/api/school/offerings/{id}`, assessments, groups, years, threads | Legacy aliases accept only resources in `Wc`, then execute normal scope/capability/history and existing policy checks. New institutional workspaces use explicit workspace-prefixed routes. |
| `/api/classes/{id}`, `/api/assignments/{id}`, quizzes/attempts/cards/material/submission IDs | Resolve known legacy resource graph and require `Wc`. New generic records are served through canonical workspace routes, never these ambiguous aliases. Existing IDs are not reallocated. |
| `/api/me/deadlines`, progress, historical class lists, partner feeds | Existing workspace-less learning aggregates resolve to `Wc`. Introduce workspace-prefixed alternatives for new spaces; do not change them to all-membership aggregates. |
| `/api/notifications` and notification preferences | Explicit personal inbox/account preferences remain recipient-global; per-workspace inbox uses canonical scope. A personal receipt links to its source workspace after authorization, not the current selector. |
| Existing platform `/api/admin` identity/security/provider endpoints | Remain global admin-only and clearly platform-scoped. New workspace management endpoints do not proxy these powers. Existing domain record operations are additionally resource-contained; workspace managers gain no global access. |
| Existing `/app/school/...`, `/app/classes/...`, assignment/message/detail deep links | Resolve their API aliases to `Wc`; initially render unchanged or replace-navigate to the authorized canonical `Wc` location without altering entity ID. Query/filter parameters are preserved only if allowlisted. Do not redirect before verifying access. |
| `/app` and `/app/school` home during transition | Existing users initially enter `Wc` with no onboarding. New canonical links specify workspace; later home selection can use a validated preference but cannot retarget existing deep links or API aliases. |
| Login return path / callback | Authentication remains unchanged. A return URL is navigation intent only; destination independently verifies context. Canonical `/app/w/...` paths must remain within the existing safe same-origin return-path policy. |
| `/v2-review` | Unchanged synthetic route. No workspace persistence, real context resolution, account linking or production calls are added in this phase. |

Do not silently create memberships on GET to make old links work. Compatibility associations are seeded or explicitly established by authorized domain actions. A user can use `Wc` links even while another workspace is selected only if the original permissions still hold.

### 7.3 Notifications, jobs and non-HTTP execution

- New event payloads include trusted workspace/source metadata generated from the committed resource graph. Do not trust a URL string or recipient membership to determine source scope.
- Legacy payloads are resolved through a versioned event-type/source-ID mapping to `Wc`. Unknown/deleted ambiguous sources show neutral recipient-only history without a live link; raw payloads do not enter workspace feeds.
- Notifications always recheck source/history access on read/navigation. No private grade values or message bodies in broad notifications. A stored receipt never guarantees current resource access.
- Job records include workspace, authoritative source, initiating actor when relevant, requested action and enqueue configuration revision. Execution reloads actor/membership/resource/current configuration; queued actor permissions are not snapshots granting permanent access.
- Global outbox dispatchers can select work across workspaces, but each event gets an independent verified context. New writes/deliveries cannot default a missing context to `Wc` after cutover. Pre-cutover jobs use a reviewed decoder that resolves an existing legacy job/source to `Wc`; unknown work is quarantined, not executed.
- Maintenance system actors must use an explicit audited system action, not impersonate an owner. Scheduled reports/exports require named scope and approved visibility; background execution cannot bypass history restrictions.
- Queue retries are idempotent and reauthorize. Context is cleared in `finally`/equivalent even on failure; keys/locks include workspace where they protect workspace data.

## 8. Safe implementation order and reversibility

No stage below is executed by Phase A.1. Runtime tests, database rehearsals and deployments belong to a separately approved implementation phase.

| Stage | Additive change / rollout order | Required gate | Safe rollback boundary |
| --- | --- | --- | --- |
| 0. Manifest and rehearsal | Inventory schema/data relationships, global query paths, root counts, IDs and unresolved orphans. Define exact compatibility key, manager designations, job/event decoder and rollback checkpoints. Use a restored isolated copy for later rehearsal, not production inspection by app bootstrap. | Owner remains unassigned; eligible management identified; backup/restore and write-freeze procedure approved. No hidden name-based mapping. | Documentation/manifest only; no data changes. |
| 1. Expand, dormant | Add workspace/configuration/membership/manager/onboarding and scope-metadata tables. Add nullable workspace FKs to roots without dropping old columns/uniqueness. Add indexes without rewriting domain records. | Migrations exercised later on PostgreSQL/SQLite; existing app tolerates additive schema. Independent workspace creation remains off. | Revert app to old version while no new workspace data exists; leave harmless additive tables/columns in place. Drop only demonstrably empty structures in a separately reviewed action. |
| 2. Compatible writers | Deploy a compatibility-only application and workers that assign `Wc` on every legacy root creation and attach scope metadata. Seed `Wc` transactionally before accepting these writers. Drain/stop old workers and switch all writers; otherwise hold writes until this is true. | No old writer remains; no independent workspace routes open. Durable checkpoint records code/configuration versions. | Roll back to old app only while single-workspace data and old constraints still hold; stop writes and reconcile new nullable rows before resuming migration. |
| 3. Idempotent backfill | Batch roots -> parent consistency -> memberships/manager grants -> event scopes. Keep original FKs, rows and files. Seed OFPPT configuration/current capabilities and migration-completed onboarding. Capture writer deltas and rerun checkpoints. | Counts and exact-ID reconciliation; no unresolved security-critical roots; pending/denied/inactive auth unchanged. Do not use model audit updates that violate append-only rules. | Retain added metadata; do not undo by deleting users/history. Can resume from checkpoints. Old app fallback still conditional on single-workspace invariant. |
| 4. Tenant-aware enforcement, still single workspace | Deploy resource-scoped APIs/policies/history projections, job context, cache namespacing and download checks. Drain/replace incompatible jobs. Expire old previews and stop reading unscoped AI/content caches. Briefly freeze writes for final delta/validation/cutover. | Both API families and all query families reviewed; cross-workspace negative tests with isolated synthetic data; no ambient/default tenant bypass. No unscoped old worker or mixed old binary serves after cutover. | Return only to the last known scoped compatibility release; disable writes/creation if validation fails. Preserve additive metadata and checkpoints. |
| 5. Enforce integrity / local uniqueness | Validate then enforce workspace FKs/non-null on true roots and cross-parent consistency. Create workspace/year-name and workspace/module-code uniqueness before dropping the old global uniqueness. Existing year-ID/group-code and year-ID/active-student uniqueness continue. Keep global login uniqueness. | Constraint and query-plan verification; explicit lock/DDL window for PostgreSQL/SQLite differences. No institution-specific invariant removed. | Before duplicate local codes exist, old uniqueness can be restored after verification. After duplicates, never force old uniqueness by deleting/renaming data; forward-fix or retain scoped version. |
| 6. Generic entities and learner bridge | Add Learner/link periods, generic courses/classes/context/FKs and management/invite lifecycle. Exact-ID migration bridge for existing students; keep user school identifier as compatibility field while new identity lives on Learner. Introduce parent-mode expansion separately when generic content is ready. | Link/account authorization and same-workspace constraints tested; legacy grade/enrollment identities unchanged. Existing global school identifier uniqueness is not relaxed until every lookup is migrated. | Disable generic creation/linking, keep new records intact. No generic FK/data teardown once used; only a tenant-aware app can read it. |
| 7. New-workspace opening | Enable eligible creation, validated generic workflows, settings and resumable onboarding. Future capabilities remain unavailable. New explicit scoped URLs generate notifications. | End-to-end isolated organization/teacher/tutor cases, role/privacy regressions, all required suites/build/browser checks in later phase. No old global lookup/caches/jobs remain. | Close creation and affected writes; deploy last safe tenant-aware release. Never deploy pre-workspace binaries or flatten workspaces back into `Wc`. |
| 8. Optional later contraction | Retire obsolete aliases/columns only with measured usage, explicit approval and archival/compatibility plan. No contraction is required for initial release. | API consumers and retained historical links accounted for. | Prefer retaining adapters/columns. Destructive down-migrations are not a rollback strategy. |

### 8.1 Backfill specifics and immutable records

- Seed `Wc` by immutable compatibility key, not a hard-coded numeric ID or workspace display name. Record its actual ID in the migration ledger.
- Use stable original primary keys for root mappings; preserve all archived/departed records, not just active classes/users. Existing identity association is not proof of teaching access.
- School identifiers move conceptually to workspace-local Learner identity; preserve current global field/read compatibility until all institutional import/lookup paths use an explicit workspace. No name/email-based identity merge.
- Audit associations are new immutable sidecars; original audit rows are not edited. Existing notification payloads and published grades are not rewritten to embed new URLs; adapters resolve them safely.
- Missing parents, offering mismatches or inconsistent student identity halt the affected cutover gate and require explicit repair approval. Records are retained, not dropped to make validation pass.
- Old preview/cache data is ephemeral: invalidate rather than attempt to promote an unverified preview into a new workspace. Preserve committed import effects and durable job outcomes.
- No file relocation is needed to establish tenancy. Keep authorized metadata binding and quotas accurate; prefixes can be adopted for new files without making old paths public.

### 8.2 Reversibility does not mean destructive reversal

Before any new workspace/generic writes, compatibility-only app rollback can be safe under verified single-workspace and old-constraint invariants. After independent data exists, rollback means closing affected workflows and returning to a **tenant-aware** release while retaining all data. A pre-workspace binary would expose organizations through global queries and is not a valid rollback target.

Backups are for disaster recovery with explicit reconciliation of post-backup writes, not routine phase rollback. Migration stages never delete/reassign grades, accounts, messages, submissions or revisions to restore an old schema. Each later migration must declare its own lossless rollback boundary; if no safe automatic down exists, document forward repair rather than a misleading destructive `down`.

## 9. Design resolution and remaining execution prerequisites

| Phase A blocker | Resolution in this design |
| --- | --- |
| Resource/query ownership | Sections 2.1–2.3 define global/root/inherited ownership, mapping strategy, risks and query coverage. |
| Concrete relationships | Section 3 selects explicit memberships/manager grants, owner reference, capability rows, learner link periods and constrained generic context parent modes. |
| Disabled/history rules | Section 4 defines active/history actions, per-domain projections, exports/files/notifications/jobs, departure versus suspension and concurrency. |
| Ownership lifecycle | Section 5 defines creator ownership, `Wc` unassigned state, managers/invitations, transfer, last-authority recovery and archive. |
| Learner-account linking | Section 6 defines account-less creation, two-sided verified linking, uniqueness/conflicts, unlinking and provenance privacy. |
| Workspace context | Section 7 defines canonical/legacy routes, source-verified direct links, personal history, notification and job contexts. |

**Execution prerequisites, not invented product decisions:** reviewed initial manager manifest; isolated database inventory/rehearsal and deployment maintenance window; verification of PostgreSQL/SQLite constraint mechanics and identity-provider verification evidence in the eventual implementation. Explicit designation of `Wc` owner is required only before assigning permanent ownership, not before additive containment/backfill: the approved unassigned state supports that migration. No owner is inferred and no live database is accessed to resolve these during documentation.

Generic self-signup, teacher-as-learner roles, permanent deletion/retention policy, guardian/proxy accounts, public avatar visibility, institutional average/certification rules and future capability semantics remain outside this schema migration's scope. They are not prerequisites to the additive containment design and must not be silently implemented alongside it.

## 10. Evidence and documentation-phase verification

Read alongside the approved architecture's source map. Additional inspected evidence:

- `backend/database/migrations/2026_10_07_000010_add_institutional_school_model.php`: existing relationships, active keys and global catalog uniqueness.
- `backend/database/migrations/2026_10_07_000011_create_official_assessments.php`: grade snapshots/revisions/imports and notification outbox.
- `backend/database/migrations/2026_10_07_000012_create_school_communication.php`, `2026_10_07_000017_create_private_school_reports.php`: explicit participants and assigned reports.
- `backend/database/migrations/2026_10_07_000014_add_school_roster_imports.php`: global user school identifier and actor/group-bound batches.
- `backend/app/Models/AuditLog.php`: append-only audit behavior requiring separate scope associations.
- `backend/app/Policies/AssignmentPolicy.php`, `SubmissionPolicy.php`: exact existing submission/read restrictions.
- `backend/app/Services/SchoolCommunicationService.php`, `SchoolNoticeService.php`: participant/continuing eligibility, parentless notices, global audience and preview cache seams.
- `backend/app/Services/NotificationService.php`, `backend/app/Http/Controllers/NotificationController.php`, `backend/app/Jobs/DeliverSchoolNotifications.php`: recipient receipts and global outbox dispatcher.
- `backend/app/Jobs/ProcessAiGeneration.php`, `backend/app/Services/AiService.php`: asynchronous authorization checks, global providers and unscoped content-hash cache seam.
- `backend/app/Http/Controllers/AdminController.php`, `SchoolController.php`: platform aggregates, people discovery and institutional raw-query scope seams.

This phase changes this document only. Verification is documentation consistency, scope inspection and whitespace checks (including the new untracked file). No backend/frontend tests, build, database commands, dependencies, application modifications, commit or push are part of Phase A.1.

**Stop here for review before schema implementation.**
