# ClassLink — OFPPT Compatibility Backfill Plan

**Phase B1.1: proposed operational design, not an executed backfill.**

References: [approved architecture](CLASSLINK_ADAPTIVE_PRODUCT_ARCHITECTURE.md), [approved migration/ownership design](CLASSLINK_WORKSPACE_MIGRATION_DESIGN.md), [B1 schema report](CLASSLINK_WORKSPACE_SCHEMA_REPORT.md), and [PostgreSQL rehearsal evidence](CLASSLINK_POSTGRESQL_WORKSPACE_REHEARSAL.md).

## 1. Evidence boundary and approval gate

Only the local SQLite population and isolated PostgreSQL copies were inspected. No production connection, schema inspection or account discovery was made. Observed local counts cannot be substituted for a production manifest. The PostgreSQL rehearsal proves B1 preserves the inspected existing state, not that the unimplemented ownership backfill or tenant enforcement is already safe.

Local evidence: 31 users (1 admin, 5 teachers, 25 students), 1 academic year, 4 historical classrooms, 1 official group, 4 modules/offerings/teaching assignments, 25 enrollment rows and 26 classroom memberships. All observed accounts are active/role-locked. Local assignment/quiz parent mismatch and active-enrollment/membership drift checks returned zero. Actual deployment must obtain its own authorized inventory/export and reviewed manifest before execution; the assistant must not connect to production to fill those gaps under this phase's instruction.

No executor, migration column, compatibility workspace, membership or grant is created by this plan. Code/schema steps below require their own approval and rehearsal.

## 2. Exact proposed compatibility workspace

| Field / relation | Planned value |
| --- | --- |
| Public identity | `public_key = ofppt-compatibility`; resolve actual database ID from this unique key, never assume ID 1. |
| Display name | `ClassLink — OFPPT` (presentation name, not a claim about a legal entity). |
| Kind | `institutional`. |
| Timezone | `Africa/Casablanca`, preserving current institutional date interpretation. |
| Lifecycle | Start `provisioning`; set `active` only after complete validated configuration, membership/manager manifest and approved backfill reconciliation. |
| Ownership | `ownership_state = unassigned_compatibility`; `owner_membership_id = NULL` throughout backfill. |
| Version | Initial 1; final activation increments using the model/version contract. Record actual values in ledger. |
| Configuration | One row: `preset_id = ofppt`, `preset_version = 1`, `terminology_preset_id = ofppt`, `terminology_version = 1`, `revision = 1`, no invented terminology overrides/preferences. |
| Onboarding | One row: version 1, completed, `completion_origin = migration`, `completed_at = run timestamp`, `current_step = NULL`, empty draft answers. Do not pretend existing users answered onboarding. |
| Workspace memberships | One association for every existing global user by exact ID. No role/identity/activation changes. |
| Managers | Explicitly approved eligible existing administrators only; see manifest gate below. |
| Owner | Intentionally unassigned; no first-admin/oldest-account/classroom-teacher inference. |

For the initial installation-wide association, seed membership `status = active`, `joined_at = association timestamp`, `ended_at = NULL`, `version = 1`. This records installation association, not historic enrollment. A globally inactive/pending/denied account remains globally blocked; an active workspace association never makes it eligible. Do not copy global role into a workspace role column or convert global account restrictions into permanent workspace suspensions. Preserve any preexisting explicit workspace suspension/left state on retries; do not reactivate it by upsert.

### Capability snapshot

Create explicit rows for the reviewed registry snapshot, not a runtime wildcard that enables future additions.

**Enabled (22):** `resources`, `announcements`, `messaging`, `calendar`, `assignments`, `submissions`, `quizzes`, `flashcards`, `academic_years`, `institutional_modules`, `teaching_assignments`, `official_grades`, `grade_import`, `delegates`, `admissions`, `roster_import`, `transfers`, `study_partners`, `historical_classes`, `quiz_progress`, `ai_tools`, `advanced_administration`.

**Disabled/unavailable (4):** `attendance`, `progress_tracking`, `certificates`, `lesson_scheduling`.

Validate the enabled set against B1 registry dependencies and record registry/preset version plus source-code revision in the run manifest. Calendar means existing deadlines; quiz progress means existing analytics. The snapshot confers no permission and does not create missing institutional AI/generic workflows. New registry identifiers require a reviewed plan update, not automatic enablement.

## 3. Proposed manager manifest and current authority

### 3.1 Observed local candidate

| Candidate | Evidence / qualification | Existing authority | Proposed grant | Explicit exclusions / disposition |
| --- | --- | --- | --- | --- |
| Local user ID **1**, demo-seeder administrator identity | `role=admin`, active, role-locked; no Microsoft verification timestamp. Identity matches `DemoSeeder::seedAdmin`. Mechanically satisfies B1 manager eligibility, but role flags do not establish real institutional legitimacy. | Existing global admin routes permit account approval/role/activation, historical-class administration, platform AI-provider configuration and audit; `SchoolAccess::admin` permits institutional setup/assignment/enrollment/delegate operations. None is derived from workspace management. | **Conditional local rehearsal proposal only:** one `Wc` membership plus one active `WorkspaceManager` grant with explicit assigning actor/time after human designation. No grant was executed. | Not a production-manager recommendation and never owner. New grant adds configuration scope only; no new grade access, private inbox access, teaching assignment or global privilege. Existing global powers remain attributable to the existing role, not the grant. |

No additional local admin accounts were found. Current teachers/coordinators/delegates are not proposed as compatibility managers merely because of their teaching or roster responsibility. They retain those existing contextual powers.

**Production manager manifest: unpopulated, execution blocked pending explicit designation.** The local demo admin must not be copied/provisioned into production or silently used to satisfy this gate. No real production admin has been inspected in this phase. If the target has no approved legitimate manager, stop and request a separate account/manager decision; do not create a synthetic admin or infer permanent ownership.

### 3.2 Required reviewed manifest fields

For each target grant record: environment identifier, exact existing user ID, independently confirmed identity reference held privately, expected global role/active/role_locked state, reason/designation reference, intended `Wc` manager responsibility, assigning existing admin actor ID, approval reference and manifest hash. Do not publish credentials, tokens or private account exports in documentation.

Execution rechecks all eligibility under locks. It aborts on missing/duplicate/mismatched identity, stale role/activation, an already differently scoped grant, missing assigning actor, or an empty eligible manager list. Existing admins are candidates, not an automatic `WHERE role='admin'` grant to every account.

The B1 `grantManager` service deliberately requires an owned workspace; it is **not** a compatibility bootstrap API. A later separately approved, environment-bound migration command must create these explicit bootstrap grants without pretending an owner exists. `granted_by` references the authorized assigning admin, including an explicitly designated self-grant only if the reviewed manifest authorizes that action. One active grant per member; retries preserve original grant provenance and do not reopen revoked grants. No bootstrap route or permanent emergency bypass is introduced.

## 4. Future resource ownership backfill matrix

`Wc` is the sole compatibility root. A **root write** below means adding nullable scope metadata in a later additive schema phase, then populating it by exact primary key. **Inherited** means resolve through unchanged foreign keys and validate the graph; no redundant workspace column or FK rewrite is required. All methods are proposed, not executed here.

| Current root/domain | Future workspace root | Exact backfill method | Ambiguity/conflict rule |
| --- | --- | --- | --- |
| Academic years | `academic_years.workspace_id -> Wc` | Update only null ownership on the approved existing ID set, including archived years. | Existing non-Wc value or missing ID => stop; no renaming/merging equal labels. |
| Official classrooms/groups | `classrooms.workspace_id -> Wc` | Backfill official rows; validate referenced year is Wc. Retain official flag, code, null teacher owner, coordinator and roster version. | Missing/mismatched year, official content provenance error or non-Wc value => reviewed repair, not fabricated year/owner. |
| Historical classrooms | Same classroom root -> Wc | Backfill `is_official=false` rows including archive; preserve teacher owner and join behavior. | Do not convert to official/generic classes or guess an offering by name. |
| Modules | `school_modules.workspace_id -> Wc` | Backfill exact catalog IDs and retain codes/status/version. | No code-based merge; non-Wc ownership stops. Workspace-local uniqueness is later and separately validated. |
| Offerings | Inherit group workspace; module must agree | Validate each offering's group and module resolve to Wc; preserve ID/group-module pair. | Missing/cross-workspace parent => stop; never attach to a similar group/module. |
| Teaching assignments | Inherit offering | Preserve teacher/assigner/period/active key; create only workspace association for existing referenced account if needed. | Missing users/offering or illegal active-key state => stop. Do not renew/revoke/reassign teaching to make mapping succeed. |
| Classroom memberships | Inherit classroom | Preserve pending/accepted/removed state, dates/decision actor; workspace memberships are separate new rows. | Report enrollment/member drift; no automatic acceptance or deletion. |
| Enrollments | Inherit group, agree with year | Preserve student IDs, periods, active keys and old transfer history. | Missing user, wrong group/year or conflicting active primary enrollment => stop for institutional resolution. |
| Delegates | Inherit group | Preserve appointment slots, dates, actor, revocation and enrollment relationship. | Do not restart expiry or appoint managers as delegates. Illegal slot/enrollment => report/repair separately. |
| Assignments | `assignments.workspace_id -> Wc` | Root metadata from existing classroom; validate official offering/group consistency. Keep deadlines/status/authorship. | Official row lacking valid offering => quarantine/report, not legacy-policy fallback. |
| Submissions | Inherit assignment | Preserve assignment/student IDs, score, feedback, lateness and attachment metadata. | No learner/link rewrite; missing parent/file metadata enters repair report. Missing storage bytes do not justify deleting submission. |
| Quizzes / attempts / questions / answers | Quiz root metadata -> Wc; children inherit | Backfill quiz root; preserve publication/review state, attempts/score/question-order snapshots. | Offering/group mismatch or absent user/quiz => stop; no republishing or completing attempts. |
| Flashcards | Deck root metadata -> Wc; cards/reviews inherit | Preserve decks/cards and existing learner study history. | Missing deck/card or cross-parent graph => stop; no new review/publication. |
| Official assessments/grades | Inherit offering -> assessment -> revisions/candidates/entries | Validate existing pointers/composite FKs and snapshot identity; retain every row and score exactly. | Any revision/assessment/candidate mismatch => stop. No regrading, revised publication, actor substitution or transfer of old results. |
| Announcements | Announcement root metadata -> Wc | Resolve classroom/offering, preserve publication/audience. Thread-backed notices follow thread mapping. | Never reclassify notice into ordinary announcement to bypass participants. |
| Conversations/messages | Thread root metadata -> Wc; messages/participants inherit | Include parentless all-account notices by approved pre-cutover installation cohort. Validate optional group/offering/assessment graph. Keep participant IDs, eligibility, read cursor and author. | Unknown origin or inconsistent parents => restricted unresolved scope, no owner/manager inbox grant. Never regenerate participants from today's member list. |
| Resources/files | Material root metadata -> Wc; submission/AI objects inherit their record | Backfill metadata, validate owner record and keep all existing object keys/bytes/quota reservations. Downloads later resolve trusted record scope. | No path-prefix inference, global file move or shared-hash permission. Missing file => preserve record and report, not delete. |
| Notifications/outbox | Explicit workspace-event scope -> Wc; account/security events remain platform | Add side metadata from reviewed event type/source ID and immutable recipient; preserve receipt payload/dedupe identity. | Unknown/deleted ambiguous source => neutral recipient history, restricted unresolved scope, no active link. Recipient's current workspace is not proof. |
| Audit | Immutable event-scope sidecar; platform events stay global | Associate only unambiguous target/action events; never update/delete original `audit_logs`. | Actor's current memberships do not establish scope; unknown events remain platform-restricted. Redact unrelated private context from workspace audit. |
| Support/reports | Inherit thread workspace; platform account support stays platform | Retain named reporter/assignee and private report state. | No automatic reassignment to Wc managers and no implicit thread-read entitlement. |
| AI configuration | Providers/credentials/provider quotas stay platform | **No ownership backfill on provider configuration.** Attribute job roots to Wc through original classroom and outputs through record context. Namespace generated-content caches before tenant opening. | Unknown job classroom => quarantine. Never expose provider secrets/reset authority through workspace settings; do not reuse old unscoped content cache for other workspaces. |
| Setup/assignment requests | Request root metadata -> Wc, including parentless setup requests | Preserve actor/decision/version; derive known offering parent or approved pre-cutover cohort. | No name-based offering creation/attachment; unresolved references stop relevant inbox opening. |
| Grade/roster import batches | Inherit assessment/group; actor binding retained | Preserve committed effects; expire pending previews before cutover and require regeneration under scoped code. | A valid old preview ID cannot authorize commit after scope/revision change. |
| Study partners | Workspace-local profile seeded only in Wc; requests inherit class/group | Preserve existing opt-in in Wc only; no consent propagation to future memberships. | Ambiguous request parent => no matching/discovery; no email exposure or automatic re-opt-in. |
| Global users / auth / storage accounting | Users/auth remain global; associate users to Wc; accounting remains platform with later attribution | Exact user IDs only; no account/role/token changes and no copying the local DB wholesale to deployment. Preserve aggregate storage usage. | Do not match email domain/Microsoft tenant/name to establish workspace or legal ownership. |

Data outside this reviewed installation cohort is never silently attached to Wc. After the cutover marker, every new root must be written by a compatible/scoped writer; null does not mean “assume OFPPT forever.” Independent workspace creation remains disabled throughout backfill.

## 5. Idempotency, invariants and reconciliation

The future executor must consume a reviewed manifest and maintain a durable run ledger (separate additive design) recording environment, code/schema/registry versions, immutable Wc key/ID, run timestamp, approved actor/manager IDs, cutoff, per-table ID bounds, before hashes/counts, checkpoints, affected rows and unresolved counts.

- Create/find Wc by unique key under lock. An existing key with wrong kind/ownership/configuration is a conflict, not permission to reset it. Never clear a later designated owner to rerun the old plan.
- Seed configuration/capability/onboarding rows only if absent and compatible. Assert exact expected initial snapshot; do not overwrite user edits, bump revisions unnecessarily or mark new unrelated workspaces migrated.
- Memberships are unique by workspace/user. Insert missing associations; preserve existing explicit lifecycle and global eligibility. Grants are unique-active and preserve original actor/time; no regrant after revocation without fresh designation.
- Root batches use stable primary-key ranges, not offsets/names. Update null scope only; assert every preexisting nonnull value equals Wc. Commit each batch with its ledger checkpoint in the same transaction. Resume verifies previous hashes and does not reinterpret changed data.
- Backfill changes only new scope metadata/new association rows. Legacy IDs, old columns/values, revision pointers, participant lists, files and global role values must hash identically before/after, excluding explicitly accounted normal writes during live mode. Do not update old `updated_at` timestamps just to attach new metadata.
- Validate parent agreement, all relevant FKs, no missing-root scope, no mismatched ownership, no new authority, exactly one intended Wc, owner null, exact capability snapshot, completed migration onboarding and manifest-matched manager counts.
- Local non-ambiguity is not evidence of target non-ambiguity. Unresolved roots are a hard stop before scoped reads/enforcement, not records to delete to reach zero.
- Validate real authorization before/after with approved synthetic actors on an isolated representative target: admin still cannot grade by role alone, unrelated teachers remain denied, delegate remains Student and private conversation participants remain explicit.

No writer/backfill executor or root metadata is implemented in B1.1. The native-copy rehearsal script copies only into disposable test databases; it must not be repurposed as a deployment backfill command.

## 6. Writer and worker cutover

### 6.1 Preferred sequence: bounded write freeze

This is the simplest exact sequence for the single-organization transition:

1. **Expand:** deploy approved dormant additive tables, then later approved nullable root/event metadata. Keep legacy constraints and independent workspace creation off. No new scoped reads yet.
2. **Quiesce:** drain in-flight write requests; stop all old workers and scheduler invocations; block external maintenance/digest triggers and import commits. Inventory in-flight uploads/provider calls/exports. Reads may remain available only if they cause no writes; otherwise use maintenance mode.
3. **Compatibility workspace:** verify manager manifest, seed Wc in provisioning state and its exact configuration/onboarding/association state using the approved run identity. Owner stays null.
4. **Backfill:** populate scope by stable-ID batches, reconcile complete graph and record checkpoints while all unscoped writers remain paused.
5. **Compatible writers:** deploy web/worker/scheduler code that transactionally keeps original domain writes plus new verified scope/event metadata. This is not two copies of grades or messages. Start no old binary. Reconcile final deltas, activate Wc only after validation, and reopen writes only on this compatible release.
6. **Scoped reads:** enable verified Wc query/read adapters, safe legacy aliases and source-scoped aggregates. Compare results/privacy against the compatibility baseline. No fallback to a global query on empty results.
7. **Enforcement:** enable capability/history/lifecycle checks at domain boundaries, notifications/files/jobs included. All current Wc capabilities start on; authorization remains authoritative. Validate all writer/read paths before the next step.
8. **Constraints:** enforce approved non-null root/same-parent integrity and workspace-local uniqueness only after validation. Preserve original global identity uniqueness and institutional invariants.
9. **Enable new workspaces:** a separate future approval after isolation, generic workflows, lifecycle and onboarding are actually ready. Backfill approval alone never enables this switch.

Compatible writers are mandatory **before** writes resume. They can follow bulk backfill only because the preferred sequence holds a write freeze throughout; there is no unscoped-write window between backfill and cutover.

### 6.2 If bounded downtime is impractical

Deploy compatible writers **before** online backfill, across every web/worker/scheduler instance. They emit original records and scope metadata atomically, with Wc association for explicitly legacy-only paths. Capture inserts, updates, removals and identity/lifecycle changes, not only maximum IDs. Batch backfill can then run online with narrow locks and conflict checks. A final short freeze/drain validates deltas and removes all old writers before scoped reads/enforcement. Do not call an offset scan plus high-water mark sufficient to capture updates to old records.

### 6.3 Per-writer requirements

| Writer / side effect | Cutover requirement |
| --- | --- |
| Web requests | Drain requests across all replicas, not just one process. Every domain mutation uses a trusted root and one transaction for old record + new metadata. Reject inconsistent workspace hints; no request-body ownership override. |
| Background/queued jobs | Stop old workers, inventory reserved/in-flight/retry/deferred jobs, and restart only compatible binaries. Explicit source/workspace/actor/revision context is rechecked at execution and commit. Missing/unrecognized context is quarantined, not defaulted globally. |
| School notifications | Pause `DeliverSchoolNotifications`; resolve each outbox event independently and preserve delivery/dedupe state. Recheck participant/source eligibility. Do not replay suppressed events or expand audience from membership changes. |
| Announcements/notices | Expire preview caches, regenerate audiences in Wc under current existing permissions. `all` becomes eligible Wc members; old private participant lists stay unchanged. |
| Imports | Freeze preview/commit windows, preserve already committed effects, expire uncommitted old batches. Regenerated preview binds actor, group/assessment, workspace, roster/version and configuration revision. |
| Exports | Finish/cancel active exports before transition or require reauthorization when generated/downloaded. Do not deliver a pre-scope snapshot merely because a job existed before cutover. No new bulk export permissions. |
| Files/uploads/downloads | Reconcile in-flight storage reservations and metadata commits. No object relocation is required. New signed URL issuance checks record scope; prior issued links retain existing expiry and cannot be claimed instantly revoked. |
| AI/provider calls | Stop dispatch; allow already-sent calls to finish/cancel by reviewed policy, record outcome without publishing on revoked context. Scope content cache by workspace before multi-tenant use; platform provider budgets/secrets remain global. |
| Scheduled work | Pause daily digest, prune, expired-attempt finalization, outbox delivery, delegate-expiry callback and AI quota reset during the freeze. Resume reviewed scoped domain operations and explicit platform maintenance independently. |
| External scheduled endpoints | Coordinate GitHub/digest or other authenticated maintenance callers as well as Laravel scheduler. Maintenance-mode UI alone does not stop cron, `--force` jobs or internal service requests. |
| CLI/admin scripts | Inventory commands, disable unreviewed bulk/local import tooling on deployment, and require explicit environment/actor/manifest. Existing local-only SQLite importer is not a production backfill tool. |

Observed scheduled work is declared in `backend/routes/console.php`; commands include daily digest, prune, finalize-attempts, storage reconciliation and institutional mapping. Retain audit/security expiry; do not conflate preserving historical records with retaining every expired cache or OTP indefinitely. No scheduler/worker was deployed or changed by this phase.

## 7. Safe migration checkpoints

| Checkpoint | Schema/data present | Old/new code support | Can users be served? | Rollback boundary |
| --- | --- | --- | --- | --- |
| C0: now | B1 commits local; isolated rehearsal only. Original local DB still legacy. | Existing application unchanged. | Existing environment unchanged; no deployment performed. | No target data rollback needed. |
| C1: dormant B1 deployed later | Six empty tables; no legacy scope metadata. | Existing code supports additive schema; B1 knows structures but exposes no creation. | Yes, existing workflows. | Empty-table B1 down is supported; retain harmless schema if unsure. |
| C2: approved nullable expand | Nullable root/event metadata, old data still authoritative; no new workspace data required yet. | Old code reads/writes old columns only until coordinated freeze; new code can prepare mappings. | Yes, legacy serving before freeze; old writers must not coexist once final backfill begins. | Remove only unused new metadata by explicit review, or leave it; never touch legacy values. |
| C3: Wc provisioning / partial backfill | Wc header/configuration, reviewed associations/grants and partial ownership/checkpoints. No permanent owner. | Old reads can still use old graph; no new scoped enforcement or generic creation. | Preferred mode: maintenance or strictly verified read-only; workers paused. | Resume ledger or return to compatible legacy-read mode retaining all metadata. Populated B1 down is forbidden. |
| C4: reconciled backfill + compatible writers | All mapped roots, complete associations, Wc active, owner null; no foreign roots. | Compatible web/workers maintain scope; old binaries no longer permitted as writers. | Yes, one Wc under compatible release. | Stop writes and revert only to a verified compatible checkpoint; preserve rows/metadata and reconcile deltas. |
| C5: scoped reads + enforcement | Trusted Wc reads/aliases/history and all writer boundaries enforced; new spaces off. | Scoped release supports this state. | Yes after authorization/privacy regression gates. | Prefer last tenant-aware release; never use global-query fallback to hide errors. |
| C6: integrity enforced | Validated required root scope, parent consistency, workspace-local catalog uniqueness. | Only code supplying valid scope works; old inserts may violate constraints. | Yes on compatible/scoped release. | Do not remove constraints to admit unscoped writers; forward repair/last scoped release. Restoring global uniqueness requires proof no conflicting data exists. |
| C7: independent workspaces, later approval | Multiple workspace data and actual supported generic features. | Tenant-aware releases only. | Yes only after separate product/isolation gate. | Close affected workflows/creation and keep all tenant data. Never roll back to pre-workspace binaries or merge into Wc. |

Every checkpoint records schema/application/worker version, manifest hash, counts/fingerprints, active feature flags, paused/resumed jobs and residual risks. A backup restore is disaster recovery with post-backup-write reconciliation, not a normal way to undo user history. No down migration may delete grades, messages, accounts or revisions to simulate reversibility.

## 8. Blockers before actual backfill

1. Explicit approval for the next additive schema/executor stage and any deployment; B1.1 approval alone is not permission to run production migration/backfill.
2. A target-environment inventory supplied through an authorized process, including actual admin identity verification, root graph/orphans, existing lifecycle constraints, size and maintenance estimates. This phase makes no production claims.
3. A reviewed nonempty manager manifest and real assigning actor. Local demo user 1 is not a production substitute. Permanent owner remains deliberately unassigned and can be designated later.
4. Implement and rehearse nullable root/event scope metadata, audit sidecars, run/checkpoint ledger, bootstrap command and idempotent backfill against representative target data. None exists yet beyond this design.
5. Implement compatible writers/workers and the coordinated freeze/drain/delta plan. Inventory every scheduler/external caller and in-flight file/import/export/provider operation.
6. Implement scoped reads/legacy aliases, history enforcement and cache/file/job isolation, then prove authorization equivalence before constraints or independent workspace opening.
7. Validate planned DDL lock windows and lossless rollback boundaries against the actual target's PostgreSQL version and dataset. PostgreSQL 16.15 rehearsal does not establish target version/scale.

## 9. Phase gate

PostgreSQL B1 behavior and the local preservation rehearsal pass; the compatibility-backfill plan and conditional local manager evidence are ready for review. Production manifest, deployment, backfill, independent workspace creation and later product/UI work remain blocked on their separate approvals and implementations.

**No compatibility backfill has been executed. No push or deployment is authorized here.**
