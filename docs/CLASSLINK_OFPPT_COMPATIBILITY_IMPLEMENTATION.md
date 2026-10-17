# ClassLink — Phase B2 OFPPT Compatibility Scope Foundation

## 1. Scope and approved checkpoint push

Before B2 edits, the working tree was clean, `origin/main..main` contained exactly the three approved commits and the reverse range was empty:

- `c8ee26e` — `docs(product): define adaptive workspace architecture`
- `6dba5fd` — `feat(workspace): add additive workspace foundation`
- `80a7514` — `test(workspace): verify PostgreSQL migration readiness`

They were pushed unchanged, without amendments or rerunning the earlier expensive matrix. After fetch, both refs equaled `80a7514351adf4e6b336f31f8379cbed22d8ddf3`.

B2 adds nullable root scope, sidecar event metadata, a migration ledger, an internal gated bootstrap and writer inheritance. **No production database was connected, no original local database was backfilled, no B2 push/deployment occurred, and independent workspace creation remains unavailable.** Apply was exercised only in isolated tests and disposable copies as required by this phase.

Read authorization, route behavior, global roles and authentication remain authoritative and unchanged. There is no tenant global scope or new workspace API. Frontend/navigation, learners/generic classes, onboarding UI, Motion, companion/profile redesign and `/v2-review` are untouched.

## 2. Additive schema

Migration: `backend/database/migrations/2026_10_17_000002_add_compatibility_workspace_scope.php`.

### 2.1 Eleven nullable root columns

Every row below adds **only** nullable `workspace_id BIGINT REFERENCES workspaces(id) ON DELETE RESTRICT` and an index named `<table>_workspace_scope`. Existing rows default to null. No uniqueness is removed and no legacy column becomes NOT NULL.

| Table | Why direct scope is needed | Authoritative relationship / fill order | Future enforcement |
| --- | --- | --- | --- |
| `academic_years` | Independently created institutional root. | Compatibility key for legacy creation; backfill first. | Required root ownership and workspace-local names after validation. |
| `classrooms` | Both historical and official class roots. | Official groups inherit year; historical creation uses active compatibility workspace. Backfill after years. | Required root ownership; official year agreement. |
| `school_modules` | Independently created institutional catalog. | Legacy catalog -> compatibility root; before offering validation. | Required ownership and workspace-local code uniqueness. |
| `assignments` | Directly addressed content root. | Classroom; validate offering/group where present. | Required root scope consistent with parent. |
| `quizzes` | Direct quiz/attempt lineage root. | Classroom and existing optional offering. | Same parent consistency; attempts/questions/answers inherit. |
| `flashcard_decks` | Direct deck/card lineage root. | Classroom and existing optional offering. | Cards/reviews inherit. |
| `announcements` | Direct published-content root. | Classroom and existing optional offering. | Existing audience/role authorization remains separate. |
| `materials` | Private resource/file ownership root. | Classroom and existing optional offering, not path/filename. | Authorized download must also verify scope in next phase. |
| `ai_jobs` | Durable context for delayed generation. | Classroom; generated quiz/deck inherits the same class graph. | Execution/source scope enforcement later; no provider permission grant. |
| `school_threads` | Direct private communication root, including parentless notices. | Group/offering/assessment agreement; proven class notices can use compatibility root. | Participant privacy remains mandatory. |
| `school_assignment_requests` | Setup requests may have no offering parent. | Offering when present, otherwise known legacy compatibility context. | Scope discovery/inbox later without changing request authority. |

Current writer metadata derives from persisted parents; supplied `workspace_id` is discarded/replaced. Already attached model roots cannot be moved to another workspace through model updates. Before a compatibility workspace is active, old writers continue producing nullable scope. This is intentional: actual cutover must quiesce writers until bootstrap completes and deploy compatible writers everywhere before reopening writes.

### 2.2 Inherited domains — no redundant workspace columns

- Offerings inherit classroom scope and must agree with module scope; official offering creation now validates that agreement.
- Teaching assignments, enrollments, classroom memberships and delegates inherit offering/group scope.
- Submissions inherit assignment; attempts/questions/options/answers inherit quiz; cards/reviews inherit deck.
- Official assessments, candidates, grade revisions/entries and grade import batches inherit offering/assessment.
- Roster imports inherit group; exceptional grants inherit offering; partner requests inherit classroom.
- Messages/participants/reports inherit thread. File objects inherit the authorized material/submission/job record, not a browser selection or storage prefix.
- Global identities/auth/session records and platform AI provider configuration are not converted into workspace-owned rows. User association is a separate workspace membership; storage/provider accounting remains global.

`LegacyWorkspaceScope::resolve()` exposes trusted graph resolution for internal tools/jobs. It does not filter existing business queries or authorize an actor.

### 2.3 Three event sidecars

| Table | Parent identity | Other fields |
| --- | --- | --- |
| `audit_event_scopes` | `audit_id`, PK/FK -> `audit_logs.id` | Nullable workspace FK, classification, recorded_at. |
| `notification_scopes` | `notification_id`, PK/FK -> `notifications.id` | Same. |
| `school_outbox_scopes` | `outbox_id`, PK/FK -> `school_notification_outbox.id` | Same. |

Parent/workspace deletions are restrictive. Index `(workspace_id, parent_id)` supports later scoped lookup. Classification is `workspace`, `platform` or `unresolved`; a CHECK on PostgreSQL / invariant triggers on SQLite require workspace ID exactly for workspace classification.

The original audit rows, message bodies, notification payloads and receipt/deduplication IDs are never rewritten. Scope is inferred from authoritative IDs already persisted in server-generated context/payload. Recipient membership, user role and browser state are not evidence of event ownership. Authentication/global-user/provider audit events stay platform scope. Unknown/deleted ambiguous sources remain unresolved, not guessed into a private workspace audience. Conflicting known roots refuse the stage.

Event writer hooks are dormant before active compatibility bootstrap. Bootstrap classifies existing events explicitly after root mapping. Existing sidecars are reused only if identical; incompatible metadata is not overwritten. Future read projections must restrict unresolved metadata; B2 does not yet change those projections.

### 2.4 Checkpoint ledger

`workspace_migration_runs` stores:

- `operation`, integer `version`, canonical `manifest_hash`, `target_hash`, `inventory_hash`.
- `mode`: dry_run / apply; `state`: running / completed / failed; current `stage`.
- `attempts`, `started_at`, `completed_at`, `failed_at`, sanitized `failure_code`, normal timestamps.
- JSON `counts`, `checkpoints`, `history` containing only stage names, counts, last IDs and timestamps/error codes; no copied row bodies, credentials, tokens, emails or SQL exception messages.
- Unique `(operation, version, manifest_hash, mode)` prevents duplicate operation identities.

Checkpoints are committed with their stage data. Root checkpoints include last ID and changed-row count. Failed-attempt history is retained across successful retry. Root reads/writes are chunked at 250 records; transactions/checkpoints are currently **stage-level**, not one transaction per 250 rows. Retry rescans null ownership safely instead of trusting a cursor to skip new data. Large-target stage duration remains an operational rehearsal requirement.

Dry-run intentionally writes **only control-plane ledger state and a private report**, not workspace/domain/auth rows. Pure `inspect()` is available internally without even ledger mutation. Completed apply reruns validate the snapshot and return `no_op=true` without changing seed relationships or ledger completion state. Resuming an unfinished operation requires the original inventory; changed inventory needs fresh review/operation identity.

## 3. Reviewed manager manifest

Version-1 JSON shape (placeholders are not an executable production manifest):

```json
{
  "operation": "ofppt-compatibility",
  "version": 1,
  "environment": "testing",
  "target_hash": "<resolved target fingerprint>",
  "approval_reference": "<review-reference>",
  "actor": { "user_id": 123, "identity_sha256": "<identity binding>" },
  "managers": [{ "user_id": 123, "identity_sha256": "<identity binding>" }],
  "owner": null,
  "allow_demo_managers": false
}
```

Identity binding is SHA-256 of trimmed, lowercased existing email, used only to detect mistaken/reused IDs, not as authentication. Actor/managers must be existing active role-locked global Admin accounts. Optional explicit owner may be an approved active Teacher/Admin; ownership still does not change global role or teaching/private permissions. Unknown fields, duplicates, missing managers, mismatched identity, eligibility or environment/target are refused.

There is **no hard-coded production manager ID**. The known demo-seeder identity requires explicit demo acknowledgement and a local/testing database context; production use of that acknowledgement is refused. Rehearsal manifests use local/synthetic accounts and are private artifacts, not deployment candidates. Production manager legitimacy still requires human designation. The bootstrap never creates a global user or promotes a role.

Owner defaults to null and `unassigned_compatibility`. Only the optional reviewed owner identity can establish ownership; an existing different owner is a conflict, never overwritten. Revoked/suspended manager state is not silently restored. Existing active managers outside the reviewed manifest cause a conflict. Manager provenance retains original grantor/time on reuse.

## 4. Bootstrap workflow and safety gates

Internal service: `OfpptCompatibilityBootstrap`. CLI: `classlink:ofppt-compatibility`.

Default mode is inspection. `--dry-run` is an explicit synonym; combining it with `--apply` is refused. The manifest is JSON only, bounded to 64 KiB and depth 16. No executable permissions or application code is accepted in JSON.

Dry-run calculates root/null counts, create/reuse plans, missing memberships/configuration/onboarding/capability/grant counts, manager count and conflict samples. Inventory hashes include root IDs/parents, inherited record IDs/parents and global role/eligibility states, not private learning content. Invalid official content provenance, missing/foreign parents, wrong workspace state and independent workspaces are reported/refused rather than repaired by name.

Apply requires **all** of:

1. Configuration gate `workspace_compatibility.apply_enabled`, default false, explicitly set via `OFPPT_COMPATIBILITY_APPLY_ENABLED` for an approved execution environment.
2. A valid reviewed manifest bound to the resolved database driver/host/port/database and application environment. URLs are resolved through Laravel connection configuration; passwords/URLs are not written to reports.
3. `--apply`, matching `--confirm-manifest`, `--confirm-target` and `--confirm-inventory` hashes from current inspection.
4. `--writers-quiesced` acknowledgement. **This does not stop web replicas, cron, jobs, exports or uploads automatically.** The approved cutover's external orchestration is mandatory.
5. Zero preflight conflicts and a serialized operation lock: PostgreSQL advisory lock or nonblocking local SQLite file lock.

The service rechecks reviewed identities under lock, creates/reuses only the reserved compatibility workspace, configuration/onboarding/capability snapshot, all existing account associations and approved manager grants. It preserves explicit existing lifecycle states and never creates generic classes/learners. The approved snapshot is 22 implemented capabilities on, four future capabilities off; a changed registry cardinality requires operation-version review.

Root metadata is filled by exact ID where null, with no updates to old fields or old `updated_at`. Event metadata follows after all roots. Each stage and ledger checkpoint commit together; a failure leaves the workspace provisioning until safe retry. Final reconciliation rechecks inventory, membership completeness, roots and the exact seed snapshot before activation/completion. Successful rerun does not create another workspace, grant or membership.

Do not use `WorkspaceFoundation::createOwnedDraft` or its owner-only grant method to fake a compatibility owner. Bootstrap has its own explicit reviewed admin-actor path and leaves ownership unassigned by default.

Private reports go to `backend/storage/app/private/ofppt-compatibility/<uuid>.json`, with restrictive requested directory/file permissions. CLI prints only path and confirmation hashes. Failure reports contain sanitized codes, not stack traces or private DB values. No CLI/HTTP entry runs automatically during migration/deployment/scheduling.

## 5. Compatible writers and unchanged reads

| Writer / workflow | B2 behavior |
| --- | --- |
| Academic years/modules/setup requests | Current raw SQL creation now calls the compatibility stamping helper; no new permissions/routes. |
| Official/historical classrooms | Model trait derives year scope or reserved legacy compatibility context; does not accept client ownership. |
| Offerings | Scope inherited; group/module consistency validated at current creation boundaries. |
| Assignments/quizzes/decks/announcements/materials | Model trait stamps authoritative class/offering lineage. Existing publication, ownership and offering guards remain. |
| Memberships/enrollments/teacher assignments/delegates | No redundant field: unchanged FK graph derives workspace. Existing approval/limits/dates/role predicates remain. |
| Assessments/grades/corrections/import batches | Inherit offering/assessment, with no score/revision/candidate/author rewrite. |
| Threads/notices | Raw creation now stamps trusted group/offering/assessment or legitimate class-notice compatibility context. Participants remain explicit. |
| Outbox | Shared insertion boundary retains deduplication and persists source-derived event scope transactionally. |
| Audit/notifications | Created hooks attach sidecar metadata after bootstrap. Original payload/audit/session data and API resources are unchanged. |
| New global account | Only after active compatibility bootstrap, add missing compatibility membership. Do not change role, activation, OTP/Microsoft eligibility, token/session or existing classroom membership. |
| Local-only roster importer | Existing SQLite/local safeguards remain; its year/module/offering writes use the same helpers. It is not a production bootstrap tool. |

No tenant predicate was added to existing read queries. Models hide internal `workspace_id`, and existing raw year/module/thread JSON projections omit it to preserve frontend contracts; projection is not tenant enforcement. Existing URLs/role guards continue working. No frontend test/build was necessary because no shared client contract or frontend behavior changed.

## 6. Queued/delayed context compatibility

- AI jobs persist root workspace from classroom. Existing `ProcessAiGeneration` still rechecks its existing actor/class ownership; output model roots inherit scope. Runtime tenant filtering and workspace-namespaced AI caches remain next-phase work before any independent workspace opens.
- Outbox rows have sidecar context before dispatch. Notifications retain source IDs and receipt identity; their scope can be derived from persisted source graph. Delivery authorization/history filtering is not switched in B2.
- Grade/roster import batches derive scope from assessment/group, not a browser-selected workspace. Existing actor/version/expiry checks remain. Cutover must expire old previews and revalidate pending work under compatible code.
- Current exports/downloads remain authorized by their existing record policies; new scope is available through their parent graph. No file keys/bytes are moved and no signed URLs are newly exposed. Tenant-aware history/download issuance remains pending.
- OTP/security mail remains account-global. Digest/queued-mail content is still governed by existing behavior; any later workspace aggregation must explicitly derive sources and exclude foreign data before multi-workspace opening.
- Scheduled finalization, delegate expiry, notifications, prune/digest and provider quotas retain current behavior. Cutover must pause old workers/schedulers/external callers; the bootstrap command does not orchestrate those processes or claim old binaries are compatible.

## 7. Migration/rollback safety finding

B2 up uses native nullable-column addition and indexes without reconstructing legacy tables or replacing their triggers. Empty-metadata down refuses if any root is scoped or ledger/event history exists. Otherwise it transactionally removes new metadata tables, indexes and columns.

The populated SQLite rehearsal caught an unsafe prototype rollback that reconstructed parent tables: existing ON DELETE CASCADE relationships could remove descendant rows even while FK checking was deferred. This happened only in a disposable copy; the original database remained read-only/unchanged. That fallback was removed. Final rollback uses native DROP COLUMN and never DROP/recreate of a legacy parent. If unrelated later DDL makes native removal unsupported, rollback fails atomically and requires forward repair rather than weakening integrity or losing rows.

Upgrade regression tests now temporarily remove B2 before reconstructing an older institutional/B1 schema, then reapply it; all original preservation/authorization assertions remain. The B1-only rehearsal scripts now select migrations strictly before B1, so future migrations do not accidentally enter their old baseline.

## 8. Four-target rehearsal

Command: `php scripts/rehearse-ofppt-compatibility.php`. It never loads `.env`, opens original local SQLite read-only, and verifies the isolated PostgreSQL cluster identity/loopback port/role. PostgreSQL copy comes from the B1.1 native legacy source, not production. All test applies are target-bound to disposable paths/databases.

Final evidence: `artifacts/ofppt-compatibility-20261017-093105-808f6d/` (private/ignored). Fresh targets contain one synthetic approved admin plus the migration-created storage singleton; populated copies contain the actual 410 local legacy rows. Synthetic/local manifests are never promoted as production manager manifests.

| Result | Fresh SQLite | Local SQLite copy | Fresh PostgreSQL 16.15 | Native PostgreSQL copy |
| --- | --- | --- | --- | --- |
| Legacy tables / rows | 50 / 2 | 50 / 410 | 50 / 2 | 50 / 410 |
| Schema migrate / empty rollback / reapply | Pass | Pass | Pass | Pass |
| Dry-run, no domain/auth mutation | Pass | Pass | Pass | Pass |
| Injected failure after classroom stage | Recorded | Recorded | Recorded | Recorded |
| Resume, same apply ledger, attempt 2 | Pass | Pass | Pass | Pass |
| Completed rerun | Verified no-op | Verified no-op | Verified no-op | Verified no-op |
| Compatibility workspace / manager grants | 1 / 1 | 1 / 1 | 1 / 1 | 1 / 1 |
| Capabilities on / future off | 22 / 4 | 22 / 4 | 22 / 4 | 22 / 4 |
| Permanent owner | Null | Null | Null | Null |
| Unscoped roots | 0 | 0 | 0 | 0 |
| Original legacy fields/IDs/auth unchanged | Pass | Pass | Pass | Pass |

Before/after comparison excludes only the new root column, canonicalizes attribute ordering, and hashes all old values/IDs including auth/session/audit rows; original metadata/relationships are not reconstructed. Repeated apply also compares all six seed tables exactly. Empty rollback checks original SQLite institutional triggers. Populated scope/ledger rollback refusal is tested. The original local source is independently fingerprinted before/after and is unchanged.

Populated copies have some empty domains; synthetic integration tests additionally backfill published grades/correction history, private messages, sessions/tokens and verify existing privacy rules. This is not a production-scale timing/load claim.

Observed event classification in the populated PostgreSQL copy: 126 platform and 34 workspace audit associations; 2 workspace and 2 unresolved notification associations; no outbox rows. Unresolved historical receipts are explicitly classified, not orphaned roots or inferred access rights. Their neutral/authorized history behavior must be implemented before scoped reads; B2 leaves their existing read behavior unchanged.

## 9. Verification and remaining gates

Final complete B2 verification: **678 SQLite tests / 3,415 assertions** and **671 PostgreSQL-compatible tests / 3,367 assertions** passed. The seven SQLite-only local-import tests are included in the SQLite full suite, not claimed on PostgreSQL. New B2 tests cover CLI gates/private reports, dry-run ledger, manifest conflicts, owner separation, stable IDs/private data, stage failure/retry, writer inheritance, API projection and safe rollback.

Syntax and targeted Pint passed for every changed/new PHP file. The two engine suites run sequentially because existing tests share a fake filesystem. Git whitespace/scope checks are part of the final checkpoint. No production or original-local apply was performed. The temporary PostgreSQL server is stopped at completion.

Before scoped-read enforcement:

1. Approve B2 and separately authorize its deployment/backfill; this phase does not push B2.
2. Obtain a legitimate target-specific manager manifest/inventory. Local demo administrator is not a production candidate. Owner remains unassigned unless explicitly reviewed.
3. Rehearse target PostgreSQL version/size/DDL windows and stage durations. Coordinate a real writer/worker/scheduler freeze, compatible release rollout and final delta validation; an acknowledgement flag is not orchestration.
4. Execute and verify the actual compatibility backfill only under that approval. No business read may switch to tenant-only results while its roots remain null.
5. Implement trusted workspace context and read predicates across both API families, discovery/aggregates, privacy, exports/files, jobs, notifications, audit/support and caches. Add capability/history enforcement without substituting for permissions.
6. Validate all-root completion, parent consistency and runtime authorization before later NOT NULL/same-parent/local-uniqueness enforcement. SQLite or PostgreSQL migration success alone does not prove multi-tenant isolation.
7. Keep independent workspace creation, learner separation, generic classes/contexts, onboarding/UI/motion and temporary-review extension behind their own phase gates.

## 10. Exact B2 file scope

New:

```text
backend/app/Console/Commands/OfpptCompatibility.php
backend/app/Models/Concerns/InheritsLegacyWorkspace.php
backend/app/Models/WorkspaceMigrationRun.php
backend/app/Services/OfpptCompatibilityBootstrap.php
backend/app/Workspace/LegacyWorkspaceScope.php
backend/app/Workspace/WorkspaceEventContext.php
backend/config/workspace_compatibility.php
backend/database/migrations/2026_10_17_000002_add_compatibility_workspace_scope.php
backend/tests/Feature/OfpptCompatibilityTest.php
scripts/rehearse-ofppt-compatibility.php
docs/CLASSLINK_OFPPT_COMPATIBILITY_IMPLEMENTATION.md
```

Modified:

```text
backend/app/Http/Controllers/SchoolCommunicationController.php
backend/app/Http/Controllers/SchoolController.php
backend/app/Http/Controllers/SchoolTeachingController.php
backend/app/Models/AiJob.php
backend/app/Models/Announcement.php
backend/app/Models/AppNotification.php
backend/app/Models/Assignment.php
backend/app/Models/AuditLog.php
backend/app/Models/Classroom.php
backend/app/Models/FlashcardDeck.php
backend/app/Models/Material.php
backend/app/Models/Quiz.php
backend/app/Models/User.php
backend/app/Services/LocalSchoolAccountImport.php
backend/app/Services/OfficialGradeService.php
backend/app/Services/SchoolCommunicationService.php
backend/app/Services/SchoolNoticeService.php
backend/tests/Feature/SchoolUpgradeTest.php
backend/tests/Feature/WorkspaceFoundationTest.php
backend/tests/Feature/WorkspaceUpgradePreservationTest.php
scripts/rehearse-postgresql-workspace.php
scripts/verify-workspace-foundation.php
```

Private reports, scratch databases and debugging artifacts are ignored and excluded. Stop at B2 review; do not enable scoped reads or push B2 as part of this phase.
