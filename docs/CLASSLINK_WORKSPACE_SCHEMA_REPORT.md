# ClassLink — Phase B1 Workspace Schema Foundation Report

## 1. Scope and checkpoint

Documentation checkpoint: **`c8ee26e` — `docs(product): define adaptive workspace architecture`**. That commit contains exactly:

- `docs/CLASSLINK_ADAPTIVE_PRODUCT_ARCHITECTURE.md`
- `docs/CLASSLINK_WORKSPACE_MIGRATION_DESIGN.md`

Phase B1 implements the dormant additive foundation described in those approved documents. There is no production migration, compatibility-workspace seed, existing-record backfill, legacy workspace column, query scope, middleware/route change, new authentication behavior or frontend change. No workspace creation API/UI is exposed. The internal foundation service is not called by any existing application workflow, observer or seeder.

There was no architectural mismatch requiring modification of the approved documents. The circular workspace/owner-membership relationship is constructed transactionally while provisioning: an owned provisioning row may temporarily have no owner, but cannot become active/archived in owned state without an owner membership. The internal draft creator returns only after the owner reference, configuration and onboarding draft exist.

## 2. Additive migration

File: `backend/database/migrations/2026_10_17_000001_create_workspace_foundation.php`.

**Exactly six new tables. No existing table is altered.** All have bigint primary key `id` and Laravel `created_at`/`updated_at` timestamps. Foreign-key deletion is restrictive, not cascading.

### `workspaces`

| Column | Definition / purpose |
| --- | --- |
| `public_key` | Unique string(64); UUID generated for normal model creation; stable identity. Reserved future compatibility key: `ofppt-compatibility`. |
| `name` | Required string(191). Model rejects blank name. |
| `kind` | string(24), default personal; personal / organization / institutional. |
| `timezone` | string(64), default UTC; model requires a valid timezone. No existing OFPPT timezone is changed. |
| `status` | string(24), indexed, default provisioning; provisioning / active / archived. |
| `ownership_state` | string(32), default owned; owned / unassigned_compatibility / recovery_required. |
| `owner_membership_id` | Nullable bigint; explicit ownership, not a user role. |
| `version` | Positive integer, default 1; model increments on update. |

Composite foreign key `(owner_membership_id, id)` -> `workspace_memberships(id, workspace_id)` prevents cross-workspace ownership. Model saving additionally validates eligible active owner membership except in explicit recovery state. `unassigned_compatibility` requires the reserved key, institutional kind and null owner. No row using that key is seeded.

### `workspace_memberships`

| Column | Definition / purpose |
| --- | --- |
| `workspace_id` | Required workspace FK. |
| `user_id` | Required existing global-user FK; no new role column. |
| `status` | string(16), default active; active / suspended / left. |
| `joined_at`, `ended_at` | Required start, nullable end; left requires end >= start; other states require null end. |
| `version` | Positive integer, default 1; model increments on update. |

Unique `(workspace_id, user_id)` preserves one association identity per account/workspace. Unique `(id, workspace_id)` supports the composite ownership FK. Index `(user_id, status)` supports account membership lookup. Model updates cannot reassign workspace or user identity. This table is separate from existing classroom `memberships`.

### `workspace_managers`

| Column | Definition / purpose |
| --- | --- |
| `membership_id` | Required workspace-membership FK; workspace inherited through membership. |
| `granted_by`, `granted_at` | Required user FK and timestamp for assignment provenance. |
| `revoked_at`, `revoked_by` | Nullable timestamp/user FK; both null for active grant, both set for completed revocation. |

Partial unique index `workspace_manager_active_unique` permits only one unrevoked grant per membership while retaining previous grants. Index `(membership_id, granted_at)` supports history. Revocation must not precede grant. Models preserve original grant provenance and do not reopen a revoked grant. Eligibility is global active, approved/role-locked Teacher/Admin plus active workspace membership; management is not a global role.

### `workspace_configurations`

| Column | Definition / purpose |
| --- | --- |
| `workspace_id` | Unique required workspace FK: one current configuration. |
| `preset_id`, `preset_version` | Code-registry reference string(32) and positive snapshot version, default version 1. |
| `terminology_preset_id`, `terminology_version` | Code-registry reference string(32) and positive version, default version 1. |
| `terminology_overrides`, `preferences` | Nullable JSON; data only, never executable authorization. |
| `revision` | Positive integer, default 1; internal service checks expected revision and updates capabilities/configuration atomically. |

Preset/terminology definitions live in typed code, not extra database lookup tables. Eloquent casts resolve identifiers to enums. Preset metadata changes do not automatically update existing configurations.

### `workspace_capabilities`

| Column | Definition / purpose |
| --- | --- |
| `workspace_id` | Required workspace FK. |
| `capability` | Required stable identifier string(64), cast to typed `Capability`. |
| `enabled` | Boolean, default false; database boolean invariant. |

Unique `(workspace_id, capability)`. Model persistence rejects enabling unavailable capabilities. The revision service validates the complete proposed enabled set, including dependencies, and refuses unknown/non-boolean states. Disabled rows remain stored. Registry availability is not workspace-editable.

This is persistence and validation foundation, **not active enforcement on existing teaching endpoints**. Future tenant/capability enforcement is a separately approved phase. Database administrators/raw SQL can bypass model/domain validation; registry availability must remain part of future runtime checks, never inferred solely from `enabled`.

### `workspace_onboardings`

| Column | Definition / purpose |
| --- | --- |
| `workspace_id` | Unique required workspace FK. |
| `version` | Positive integer, default 1. |
| `status` | string(16), default draft; draft / completed. |
| `current_step` | Nullable string(64). |
| `draft_answers` | Nullable JSON for resumable data. |
| `completed_at`, `completion_origin` | Null in draft; both required when completed. Origin user / migration. |

No onboarding is created for existing users. Migration-completed OFPPT onboarding will be explicitly populated in a later backfill phase. B1 has no onboarding UI or activation workflow.

### Integrity implementation

- PostgreSQL branch: named CHECK constraints, restrictive FKs and partial unique index.
- SQLite branch: equivalent insert/update invariant triggers, restrictive FKs and partial unique index.
- Owner/member composite FK applies on both paths.
- Unsupported database engines fail before creating any table, rather than silently omitting integrity.
- Existing institutional constraints, unique indexes, triggers and user-role semantics are unchanged.

## 3. Models and internal domain service

Six models: `Workspace`, `WorkspaceMembership`, `WorkspaceManager`, `WorkspaceConfiguration`, `WorkspaceCapability`, `WorkspaceOnboarding`.

- `Workspace` relates to memberships, explicit owner membership, configuration, capability states and onboarding.
- `WorkspaceMembership` relates to global user, workspace and manager-grant history.
- `WorkspaceManager` relates to membership, grantor and revoker; workspace is inherited.
- `BelongsToWorkspace` provides a relationship and model identity guard only. It deliberately installs **no global query scope**.
- `User` receives only an opt-in `workspaceMemberships()` relationship. Existing methods, role checks, classroom memberships and serialization contracts are unchanged.
- Five backed enums model workspace kind, lifecycle, ownership, membership status and onboarding status.

`WorkspaceFoundation` is an internal, currently unwired service:

1. `createOwnedDraft`: requires an approved active Teacher/Admin; transactionally creates a provisioning workspace, owner membership/reference, Custom/Generic configuration and onboarding draft. It applies no recommended preset, enables no capability, and creates no compatibility workspace. Used by synthetic tests only in this phase.
2. `reviseConfiguration`: requires explicit current workspace configuration authority; locks workspace/configuration, checks expected revision, validates allowed metadata and complete capability snapshot, writes atomically and increments revision. A stale or invalid request writes nothing.
3. `grantManager`: only an eligible owned-workspace owner can grant management to an eligible same-workspace active member. An existing grant is idempotent. Managers cannot promote other members through this service. Compatibility bootstrap is intentionally not implemented.
4. `revokeManager`: owner or authorized departing manager can revoke; refuses loss of last eligible authority. No rows/history are deleted. Existing global account restrictions are rechecked by configuration-authority evaluation.

`Workspace::canBeConfiguredBy` does not grant teaching, grading, platform-admin or private-thread permissions and is not plugged into existing policies. It refuses ineligible global accounts, inactive membership, archived workspace and recovery-required state.

Full membership/ownership lifecycle orchestration, invitations, owner transfer/recovery, append-only scope-aware audit integration and public API validation remain later work. B1 supplies state/provenance structures and narrow internal operations, not a production workspace-management surface.

## 4. Typed capability registry

`App\Workspace` contains backed identifiers, availability enum, immutable definitions, registry, presets, terminology and eligibility helper. Definitions carry stable ID, real FR/EN label/description keys, availability, dependencies, supported existing contexts, contextual prerequisites, preset recommendations and disable/history policy identifiers. No closure, role grant or executable policy is loaded from JSON.

**22 implemented identifiers:**

`resources`, `announcements`, `messaging`, `calendar`, `assignments`, `submissions`, `quizzes`, `flashcards`, `academic_years`, `institutional_modules`, `teaching_assignments`, `official_grades`, `grade_import`, `delegates`, `admissions`, `roster_import`, `transfers`, `study_partners`, `historical_classes`, `quiz_progress`, `ai_tools`, `advanced_administration`.

**4 future/unavailable identifiers:**

`attendance`, `progress_tracking`, `certificates`, `lesson_scheduling`.

Future features have no supported implemented context and cannot be enabled through the model/service. Calendar denotes existing deadlines; quiz_progress denotes existing quiz analytics. AI metadata is historical-context-only. Advanced administration is not a grant of platform authority. Generic workflow support is not claimed merely because a preset recommends an existing capability.

Dependencies: submissions -> assignments; grade_import -> official_grades. Resource prerequisites are separate metadata: official grading needs a real institutional offering/year/current teaching assignment; delegates need enrollment and existing limits; transfers need groups in the same academic year. These are not replacements for domain authorization or a requirement to force generic users into years/modules.

Disable metadata consistently preserves data and authorized history. Sensitive import provenance, own published results and continuing participant eligibility have distinct history identifiers. Runtime history projections, downloads, exports, notifications and jobs are **not wired to capabilities in B1**; their approved behavior remains specified in the migration design.

## 5. Preset and terminology metadata

Eight version-1 presets: OFPPT/vocational, Language teacher, Private tutor, Training center, University teacher, General school, Club/association and Custom.

- OFPPT recommends every currently implemented capability, not future features.
- Language teacher omits official grades/delegates and separately marks attendance/flexible progress as future recommendations.
- Private tutor has no recommended academic-year requirement and separately marks scheduling/progress as future.
- Other profiles recommend relevant tools without creating a class, user, year or workspace.
- Custom recommends nothing automatically.
- `availableRecommendations()` and `futureRecommendations()` keep roadmap items separate. Registry metadata does not provision a workspace or imply generic workflows already exist.

Terminology presets: Generic, OFPPT and Language school. The noun-form foundation supplies FR/EN singular/plural forms, keeps flexible evaluation distinct from official grades and provides OFPPT overrides. Language-school baseline currently uses generic terms; future contextual phrase resolution can build on it. Two new backend `workspace.php` translation files resolve metadata keys. Existing backend dictionaries and all frontend translations remain unchanged.

## 6. Verification performed

### Backend suite and source checks

- Full backend suite: **660 tests, 3,271 assertions passed** on PHP 8.5.1 with isolated in-memory SQLite.
- This includes 24 new foundation/registry tests and all 636 existing tests, including institutional authorization, grade lifecycle/privacy, communication, delegates, imports, lifecycle and upgrade tests.
- New tests cover dormant migration/no new routes, eligible/ineligible draft creation and atomicity, explicit owner separation, cross-workspace owner rejection, multiple memberships, manager authority/revocation/last-manager protection, configuration revision conflicts, dependency/future-state rejection, onboarding persistence, raw database invariants, real unrelated grade/thread access denial, metadata localization/presets and populated rollback refusal.
- PHP syntax lint passed for all 29 added/modified PHP files.
- Targeted Laravel Pint formatting/check passed. `git diff --check` and direct whitespace checks of every new untracked file passed.
- Frontend suite/build not rerun: no frontend/shared API contract, route, UserResource or dependency changes. No browser-visible functionality was introduced.

### Database safety and local-copy rehearsal

Command: `php scripts/verify-workspace-foundation.php`.

The verifier boots without a `.env` file, overrides cached configuration/DB URLs, asserts the selected SQLite target, and writes only isolated ignored artifacts. It opens `backend/database/database.sqlite` **read-only** and uses SQLite's backup API. It never issues Laravel migrations against the original local database or a remote database. It refuses an existing copy with pending older migrations rather than applying unrelated legacy changes.

| Check | Fresh test SQLite | Representative existing local SQLite copy |
| --- | --- | --- |
| Baseline | 50 legacy domain/infrastructure tables; 1 row | 50 legacy domain/infrastructure tables; 410 rows |
| Foundation migration | Passed; 6 empty tables | Passed; 6 empty tables |
| Legacy rows, IDs and schema fingerprints after apply | Unchanged | Unchanged |
| Foreign-key violations | 0 | 0 |
| Empty-foundation rollback | Passed; exact baseline rows/schema restored | Passed; exact baseline rows/schema restored |
| Existing migration records/IDs after rollback | Unchanged | Unchanged |
| Reapply | Passed | Passed |
| Original local source | Not a migration target | Read-only source fingerprints unchanged |

Counts exclude SQLite internal tables and migration bookkeeping; preexisting migration rows/IDs are checked separately. Fingerprints include all existing table values and their table/index/trigger definitions without writing private values to logs. Foundation tables remain empty: no compatibility seed/backfill.

Private report/copies from the rehearsal are ignored under `artifacts/workspace-foundation-20261017-022425-fd73f5/`. They are not committed. The script generates a separate isolated directory on each run.

**PostgreSQL limitation:** the PostgreSQL migration branch is implemented but no local PostgreSQL server was available for runtime rehearsal in this phase. SQLite success is not claimed as PostgreSQL runtime verification. Rehearse the PostgreSQL path on an isolated database before deploying/backfilling production. No production database was accessed.

### Rollback contract

The migration's `down()` checks all six tables before dropping anything. If any contains a row it throws, retaining the entire foundation. Empty rollback removes the circular ownership FK first, then the six new tables. This matches the approved non-destructive rollback boundary; it is deliberately not a data-deleting down migration. A populated-schema refusal is tested separately.

## 7. Compatibility result and remaining gates before backfill

Current OFPPT behavior is preserved by keeping the foundation dormant; all existing backend tests pass. Existing tables/IDs/constraints survive the local-copy migration/rollback/reapply unchanged. Global authentication/role checks and frontend/review mode are untouched. No existing account is assigned ownership or workspace membership in its original database.

Before any OFPPT backfill:

1. Review and approve B1; select an explicitly approved next migration stage.
2. Rehearse PostgreSQL migration/integrity/rollback on an isolated representative copy and confirm deployment/DDL windows.
3. Produce the resource-by-resource operational manifest, manager designation list, legacy-root mappings and unresolved-parent repair report. Permanent owner remains unassigned until explicitly designated; that does not block an approved additive backfill.
4. Add nullable legacy-root workspace FKs and immutable event/notification scope metadata in a later additive phase. B1 intentionally adds none of those columns/tables.
5. Implement the compatibility writer/worker deployment plan, exact `Wc` seed, idempotent checkpoints and delta reconciliation before switching constraints or exposing independent workspaces.
6. Add scoped audit, configuration validation/lifecycle APIs, backend query/capability enforcement, historical projections and file/job/cache isolation before multi-workspace opening. The B1 registry alone enforces none of the current teaching endpoints.
7. Keep Learner, generic classes/courses, contexts, linking, real onboarding/UI, Motion, companion and dashboards in their later approved phases.

## 8. Exact Phase B1 file scope

New:

```text
backend/app/Enums/WorkspaceKind.php
backend/app/Enums/WorkspaceMembershipStatus.php
backend/app/Enums/WorkspaceOnboardingStatus.php
backend/app/Enums/WorkspaceOwnershipState.php
backend/app/Enums/WorkspaceStatus.php
backend/app/Models/Concerns/BelongsToWorkspace.php
backend/app/Models/Workspace.php
backend/app/Models/WorkspaceCapability.php
backend/app/Models/WorkspaceConfiguration.php
backend/app/Models/WorkspaceManager.php
backend/app/Models/WorkspaceMembership.php
backend/app/Models/WorkspaceOnboarding.php
backend/app/Services/WorkspaceFoundation.php
backend/app/Workspace/Capability.php
backend/app/Workspace/CapabilityAvailability.php
backend/app/Workspace/CapabilityDefinition.php
backend/app/Workspace/CapabilityRegistry.php
backend/app/Workspace/Preset.php
backend/app/Workspace/PresetDefinition.php
backend/app/Workspace/PresetRegistry.php
backend/app/Workspace/TerminologyPreset.php
backend/app/Workspace/WorkspaceEligibility.php
backend/database/migrations/2026_10_17_000001_create_workspace_foundation.php
backend/lang/en/workspace.php
backend/lang/fr/workspace.php
backend/tests/Feature/WorkspaceFoundationTest.php
backend/tests/Unit/WorkspaceRegistryTest.php
scripts/verify-workspace-foundation.php
docs/CLASSLINK_WORKSPACE_SCHEMA_REPORT.md
```

Modified:

```text
backend/app/Models/User.php
```

The two approved architecture documents were committed separately and are not changed by B1. No pushes or later-phase implementation are part of this review gate.
