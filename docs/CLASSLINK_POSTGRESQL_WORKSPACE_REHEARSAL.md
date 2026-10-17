# ClassLink — Phase B1.1 PostgreSQL Workspace Rehearsal

## 1. Result and scope

**Result: passed on isolated PostgreSQL 16.15.** B1 applies, empty-rolls-back and reapplies on both a fresh native schema and a populated native PostgreSQL clone. The migration adds exactly six empty workspace tables and changes no preexisting rows, IDs, roles or legacy table definitions.

This is rehearsal evidence, not a deployment or backfill approval. No production connection, production dump, compatibility seed, workspace backfill, legacy workspace column, frontend/API change or dependency installation was used. The B1 application code and migration are unchanged. See [the backfill plan](CLASSLINK_OFPPT_BACKFILL_PLAN.md) for the separate operational gate.

Previously approved local checkpoints remain `c8ee26e` (architecture) and `6dba5fd` (B1 foundation). Neither was pushed during this phase.

## 2. Isolation and reproducibility

An already available PostgreSQL distribution was used from the approved temporary tool directory; no project package was installed. A **new** cluster was initialized at:

```text
C:\Users\abder\AppData\Local\Temp\opencode\classlink-workspace-b11-pg
```

Server: PostgreSQL 16.15, loopback only, `127.0.0.1:15439`, temporary role `classlink_rehearsal`. Local trust authentication belongs only to this disposable cluster; it is not application authentication or a deployment setting. The cluster was stopped after the checks.

| Database | Use |
| --- | --- |
| `classlink_b11_fresh` | Fresh native legacy migrations, then B1 apply/rollback/reapply. |
| `classlink_b11_legacy` | Native legacy schema populated from read-only local SQLite; retained as the pre-B1 native source. |
| `classlink_b11_copy` | PostgreSQL `CREATE DATABASE ... TEMPLATE` clone of the native source; populated B1 rehearsal target. |
| `classlink_b11_test` | Disposable PHPUnit database, separate from both rehearsal datasets. |

An initial test database named `classlink_b11_tests` was also created; it was superseded by the `_test` name required by the existing development-auth safety guard. No application guard was relaxed to accommodate the harness.

Rehearsal command:

```text
php scripts/rehearse-postgresql-workspace.php
```

The script deliberately targets only these local rehearsal names/endpoint. It verifies server address, port, role and cluster directory, refuses nonempty initial targets or an existing native-copy database, boots Laravel without a `.env` file, and overrides cached configuration and DB URLs. It is **not** a compatibility-backfill executor and is not suitable for deployment use.

Evidence is private and ignored under:

```text
artifacts/workspace-postgresql-20261017-030140-702c23/
  native-legacy-baseline.json
  fresh-before.json
  fresh-after.json
  representativeNativeCopy-before.json
  representativeNativeCopy-after.json
  report.json
```

The reports contain counts and hashes, not raw grade/message/account contents. Local admin evidence records ID and eligibility/provenance flags, not tokens or credentials. The original local SQLite source remained unchanged.

## 3. Representative dataset construction

Production was neither contacted nor assumed to match local data. The actual source was `backend/database/database.sqlite`, opened with `SQLITE3_OPEN_READONLY`.

1. Run all pre-B1 migrations on empty native PostgreSQL databases, preserving their PostgreSQL-specific checks/functions/indexes.
2. Copy the local legacy rows into the newly constructed native source, preserving IDs and normalizing SQLite boolean representation to PostgreSQL boolean input.
3. Load parents before children using actual PostgreSQL FK metadata. Assessment draft/published revision pointers are nullable during initial insertion and restored after revisions, if present. No FK/check trigger or `session_replication_role` is disabled.
4. Reset native serial sequences to copied IDs, compare source/target ID sets, row counts and global role mappings, and validate all 90 existing FKs with explicit anti-join checks.
5. Clone the native PostgreSQL source with `TEMPLATE` after releasing source connections. B1 runs on the clone, not the original local source.

The local dataset has **31 users: 1 admin, 5 teachers, 25 students**, all active and role-locked; **4 historical classrooms and 1 official group**. Checked assignment/quiz parent mismatches and active-enrollment/accepted-membership drift were all zero. These are local observations, not production inventory.

Some local domains are empty, including official grades, private threads, submissions and resources. `WorkspaceUpgradePreservationTest` supplements the copy with a separate synthetic pre-B1 scenario on both engines: published official grades, a correction revision, private conversation/message, historical assignment/submission and resource metadata. It snapshots every preexisting row across the migration and verifies real allowed/forbidden API results before/after. No real workspace is seeded in that scenario.

## 4. Migration and preservation results

Counts below exclude the `migrations` bookkeeping table. Including it, physical public-table counts are 51 before and 57 after.

| Verification | Fresh PostgreSQL | Populated native PostgreSQL clone |
| --- | --- | --- |
| Legacy tables before | 50 | 50 |
| Legacy rows before | 1 | 410 |
| Tables after B1 | 56 | 56 |
| New workspace rows | 0 | 0 |
| B1 migrate | Passed | Passed |
| Empty-foundation rollback | Passed | Passed |
| B1 reapply | Passed | Passed |
| Preexisting rows / IDs / role values | Unchanged | Unchanged |
| Legacy columns / defaults / constraints / indexes / triggers | Unchanged | Unchanged |
| Preexisting migration rows / IDs after rollback | Unchanged | Unchanged |
| FKs validated after B1 | 99, zero violations | 99, zero violations |

Exactly these tables were added: `workspaces`, `workspace_memberships`, `workspace_managers`, `workspace_configurations`, `workspace_capabilities`, `workspace_onboardings`.

Native before/after fingerprints hash complete row contents and table column/default/constraint/index/trigger definitions. Counts are not the sole preservation check. Rollback also checks that all six tables disappear and the original migration bookkeeping returns unchanged. Reapply repeats legacy fingerprint comparisons.

### Populated-copy row-count ledger

Every count in the table is identical before and after B1; hashes also match. Migration bookkeeping is verified separately.

| Table | Before | After |
| --- | ---: | ---: |
| academic_years | 1 | 1 |
| ai_jobs | 0 | 0 |
| ai_providers | 3 | 3 |
| announcements | 6 | 6 |
| assessment_candidates | 0 | 0 |
| assignments | 6 | 6 |
| attempt_answers | 10 | 10 |
| attempts | 2 | 2 |
| audit_logs | 160 | 160 |
| cache | 4 | 4 |
| cache_locks | 0 | 0 |
| class_delegates | 0 | 0 |
| classrooms | 5 | 5 |
| failed_jobs | 0 | 0 |
| flashcard_decks | 3 | 3 |
| flashcard_reviews | 0 | 0 |
| flashcards | 12 | 12 |
| grade_entries | 0 | 0 |
| grade_import_batches | 0 | 0 |
| grade_revisions | 0 | 0 |
| job_batches | 0 | 0 |
| jobs | 0 | 0 |
| materials | 0 | 0 |
| memberships | 26 | 26 |
| module_access_grants | 0 | 0 |
| module_offerings | 4 | 4 |
| notifications | 4 | 4 |
| official_assessments | 0 | 0 |
| options | 66 | 66 |
| otp_codes | 2 | 2 |
| partner_profiles | 1 | 1 |
| partner_requests | 0 | 0 |
| personal_access_tokens | 0 | 0 |
| questions | 24 | 24 |
| quizzes | 6 | 6 |
| roster_import_batches | 0 | 0 |
| school_assignment_requests | 0 | 0 |
| school_enrollments | 25 | 25 |
| school_messages | 0 | 0 |
| school_modules | 4 | 4 |
| school_notification_outbox | 0 | 0 |
| school_reports | 0 | 0 |
| school_thread_participants | 0 | 0 |
| school_threads | 0 | 0 |
| sessions | 0 | 0 |
| storage_objects | 0 | 0 |
| storage_usage | 1 | 1 |
| submissions | 0 | 0 |
| teaching_assignments | 4 | 4 |
| users | 31 | 31 |
| **Total** | **410** | **410** |

## 5. Integrity and cross-engine semantics

The same raw-write integrity tests run on both engines. PostgreSQL exceptions are asserted by SQLSTATE, not merely by accepting any failure.

| Contract | PostgreSQL result | SQLite equivalent |
| --- | --- | --- |
| Owner membership belongs to same workspace | Cross-workspace owner rejected, `23503` | Composite FK rejects it. |
| Manager references valid membership/grantor | Missing membership/user rejected, `23503` | Restrictive FKs reject them. |
| Workspace/user/owner-member deletion | Rejected without cascading history, `23503` | Restrictive FKs reject them. |
| One active manager grant per membership | Duplicate rejected, `23505`; new grant permitted after recorded revocation | Same partial unique index behavior. |
| Grant/revocation period and revoker | Invalid period/missing revoker rejected, `23514` | Insert/update invariant triggers reject them. |
| Membership/capability uniqueness; singleton config/onboarding | Duplicates rejected, `23505` | Unique constraints reject them. |
| Lifecycle, positive versions, completion state | Invalid raw updates rejected, `23514` | Invariant triggers reject them. |
| Configuration revision | Stale expected revision rejected; valid update increments atomically | Same service contract; SQLite locking implementation differs. |
| Onboarding persistence | Draft answers/version and explicit completion survive reload | Same persisted contract. |
| Populated migration rollback | Refuses before dropping anything | Same guard; empty rollback/reapply passes. |
| Manager eligibility / global-role separation | Domain checks require eligible active membership; no admin escalation or grade/thread inheritance | Same domain checks. These are not claimed as user-role CHECK constraints. |
| Future capabilities | Registry unavailable; model/service reject enablement | Same registry/model contract. |

No PostgreSQL constraint was weakened. PostgreSQL uses native CHECKs; SQLite uses the approved equivalent invariant triggers. Native decimal values may serialize as strings such as `16.00`, while SQLite may return numeric `16`; preservation compares native before/after values, and score assertions compare numeric meaning. No API normalization change was introduced.

Tests establish stale-revision behavior and database integrity, not a production-scale concurrency/load benchmark. Signed file objects, external providers and production performance were not exercised.

## 6. Tests and source checks

Final results:

| Run | Result |
| --- | --- |
| Full backend suite, SQLite | **666 tests, 3,317 assertions passed** |
| PostgreSQL-compatible backend suite | **659 tests, 3,270 assertions passed** |
| Workspace tests on PostgreSQL | 30 cases covered by the successful PostgreSQL run, including 5 new raw-integrity tests and 1 populated-upgrade preservation test. |
| Fresh/populated PostgreSQL migration harness | Passed apply, empty rollback, reapply and preservation checks. |
| PHP syntax, three new PHP files | Passed |
| Targeted Pint | Passed |
| Git whitespace/scope check | Passed for tracked changes and all five new files; no application source changes are included. |

The PostgreSQL suite command is `php vendor/bin/phpunit --filter '^(?!.*LocalSchoolImportTest)'`, with explicit process environment selecting `pgsql`, loopback port 15439, database `classlink_b11_test`, rehearsal role, empty URL/password and disabled local SSL. The seven `LocalSchoolImportTest` cases exercise a deliberately SQLite-only importer and are all included in the full SQLite run. They are not claimed to pass on PostgreSQL.

Run database-engine suites **sequentially**: their existing fake filesystem uses common paths even when databases differ. A concurrent run produced missing-fake-file failures; the final standalone PostgreSQL run passed without application changes. Initial harness corrections also included satisfying the existing `_test` database-name guard and comparing decimal scores without engine-specific PHP types. These were rehearsal/test corrections, not relaxed authorization or migration constraints.

No frontend suite/build was needed: shared contracts, frontend code and `/v2-review` were unchanged.

## 7. Findings and remaining limits

- B1's PostgreSQL path is now runtime-rehearsed, including empty rollback and restrictive deletion; no B1 migration/model correction was necessary.
- The representative copy is actual **local** legacy data translated into a native PostgreSQL schema, then natively cloned. It is not a production PostgreSQL dump and proves nothing about production account legitimacy, orphan counts, DB size or maintenance duration.
- Local populated-copy emptiness in some domains is explicit; synthetic upgrade/institutional tests cover populated grades, revisions, conversations and submission/resource metadata.
- The rehearsal did not add workspace columns to legacy tables or execute any compatibility workspace/membership backfill. Existing-role policies and classroom/module/offering behavior remain unchanged in the passing institutional suite and upgrade scenario.
- The local admin is a demo-seeder identity, not a basis for production ownership/management. The backfill plan keeps actual manager designation blocked on an explicitly reviewed environment-specific manifest.
- Production deployment, backfill and new workspace opening require separate approval and additional implementation. This phase supplies readiness evidence and a plan only.

## 8. Exact phase file scope

```text
backend/tests/Feature/WorkspaceDatabaseIntegrityTest.php
backend/tests/Feature/WorkspaceUpgradePreservationTest.php
scripts/rehearse-postgresql-workspace.php
docs/CLASSLINK_POSTGRESQL_WORKSPACE_REHEARSAL.md
docs/CLASSLINK_OFPPT_BACKFILL_PLAN.md
```

No B1 migration, application model/service, existing test, frontend or dependency file is changed. Private databases/reports remain outside versioned source. Stop for review before any deployment or backfill.
