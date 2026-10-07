# ClassLink — Jira CL proposed update list (LOCAL PROPOSAL — Jira NOT synchronized)

Status: **proposal only**. Jira was not contacted, read, or modified; no remote access or
authorization exists. Every identifier used below already appears in the local sources
`docs/JIRA-BOARD.md` (CL-1…CL-80) or `files/ClassLink_Jira_Import.csv` (CL-E1…CL-E12,
US-01…US-48). Proposed new work is listed **without invented Jira keys**; Jira would assign
real keys on import. Source of the work described here: the uncommitted working tree of
2026-10-07 (64 modified files, 52 new files, all tests green).

## A. Proposed status/evidence updates for existing issues

| Existing ID | Proposed update | Evidence in working tree |
|---|---|---|
| US-08 Créer une classe | Amend description: official groups are now created by the super admin (`POST /school/groups`); teacher-owned legacy classes remain for non-official scope | `SchoolController`, `SchoolSetupService`, `frontend/src/screens/SchoolScreens.tsx` |
| US-10 Régénérer ou désactiver le code | Done + new UI: join-state toggle, regenerate, invitation state labels | `GroupActions` in `SchoolScreens.tsx`; `POST /school/groups/{id}/invitation` |
| US-11 Archiver une classe | Extend: group archive **and restore**, academic-year archive, offering archive/restore with archived badges | `SchoolController@store`/`restore`, group/year/offering edit forms |
| US-18 Retirer un étudiant | Extend: per-row roster removal in the school workspace with confirmation, admin/coordinator only | `RosterPanel`, `DELETE /school/groups/{id}/enrollments/{studentId}` |
| US-19 Importer la liste officielle de la classe | Extend: reconciliation block (roster vs. entries diff, checkbox selection, versioned commit) | `OfficialGradeEditor` reconcile UI; `add_student_ids` payload |
| US-20 Être notifié de la décision | Extend: membership decisions emit `MEMBERSHIP_ACCEPTED` / `MEMBERSHIP_REJECTED` / `MEMBERSHIP_REMOVED` notifications | `SchoolSetupService::enroll/leave`, `SchoolController@decide` |
| US-35 Réviser avec des flashcards | Extend: school deck creation in module space + student add-card on decks | `POST /school/offerings/{id}/tools/flashcards`, `POST /flashcard-decks/{deck}/cards`, `AddCardForm` |
| US-45 Voir mes notifications | Extend: full 16-type inventory, preference toggles for every type, deep links | `NotificationService::types()`, `ProfileScreen` (16 toggles), `describeNotification` |
| CL-26 Flashcards (board) | Same evidence as US-35 | `FlashcardReviewTest` (+5 tests) |
| CL-70 Backend test suite (board) | Count update: 246/641 → **584 tests / 2402 assertions / 0 failures** (SQLite) | `php artisan test` |
| CL-71 Frontend tests (board) | Count update: 31 → **94/94** (8 files), `tsc -b --noEmit` clean, build clean | `npm test -- --run`, `npm run typecheck`, `npm run build` |
| CL-75 GitHub Actions CI | No change needed; existing jobs already run the full backend/frontend/infra matrix covering the new code | `.github/workflows/ci.yml` |
| CL-78 No secrets versioned | Re-verified: `backend/.env` untracked and ignored; 8-family secret scan over tracked+untracked tree returned 0 hits | `git status`, local secret scan |
| CL-80 Verification log | Historical log stays as delivered; add a follow-up log for the institutional pass (counts above, pilot, audits) | this proposal + final report |

## B. Proposed new issues (titles only — no Jira key exists yet)

Import as a new epic with the stories below; Jira assigns real keys. Suggested epic title:
**«École institutionnelle — modèle officiel»**.

1. **Années académiques : édition et cycle de vie** — edit name/start/end while active, archive, restore; frozen-year guard for writes. *Must.* Verified: `SchoolLifecycleTest`.
2. **Modules : édition** — edit code/name/status of module definitions. *Must.* Verified: `SchoolLifecycleTest`.
3. **Offres (groupe × module × année) : cycle de vie** — archive/restore offering, archived states in UI, writes blocked when archived. *Must.* Verified: `SchoolLifecycleTest`.
4. **Groupes officiels : édition et restauration** — edit name/filière/level, restore archived group; admin-only. *Must.* Verified: `SchoolLifecycleTest`.
5. **Affectations d'enseignement : notifications** — `TEACHING_ASSIGNMENT_CHANGED` (assigned/revoked) with deep link to `/app/school`; emitted after commit. *Must.* Verified: `SchoolLifecycleTest`, notification tests.
6. **Délégués : nomination, révocation, expiration** — `DELEGATE_CHANGED` notifications, two-slot invariant with concurrent test, automatic end of expired mandates (`school:end-expired-delegates` schedule). *Must.* Verified: `SchoolModelTest`, lifecycle tests.
7. **Notes officielles : saisie, import, publication, correction** — assessments with coefficient/maximum, template import (CSV/XLSX, decimal comma, formula-injection safe), draft→publish→correction, private student results, conflict detection, roster reconciliation. *Must.* Verified: `OfficialGradeTest`, `SchoolRosterAndNoticeTest`.
8. **Communication scolaire : annonces à audience** — audience preview/commit, participant rules, moderation privacy. *Must.* Verified: `SchoolCommunicationTest`, `SchoolReportPrivacyTest`.
9. **Flashcards : création dans l'espace module + ajout de cartes côté élève** — school teacher deck creation; student add-card preserving review gate. *Should.* Verified: `FlashcardReviewTest`, `school-workflows.test.tsx`.
10. **Rapport de correspondance legacy (read-only)** — `classlink:school-mapping-report` with per-row inventory, ambiguity flags, proposed context; never merges automatically. *Must.* Verified: read-only command, `writes: 0`.
11. **Quota de stockage private R2** — reservation/registry, 507 preflight refusal, usage report, reconcile command. *Must.* Verified: `StorageCapacityTest` (30 tests).
12. **Migrations institutionnelles + sessions en base** — 11 additive migrations with driver-conditional rollback, populated-upgrade coverage; deployed sessions table `2026_10_07_000002`. *Must.* Verified: `SchoolUpgradeTest`, `migrate:reset`/re-run locally; PostgreSQL engine pass runs in CI (no local pgsql harness on this host).
13. **Frontière legacy / routes `/api/school/*`** — `InstitutionalLegacyBoundary` + `InstitutionalWriteTransaction` middleware: official classrooms isolated from legacy routes, scoped writes inside DB transactions, `DB::afterCommit` notification safety. *Must.* Verified: `AuthorizationTest`, `SchoolTeachingTest`.
14. **Sécurisation IA (sans nouveau flux officiel)** — queued AI generation revalidates active teaching access under row lock before draft creation; sanitized failure logs; tightened `AiJobPolicy`. AI remains optional and never blocks core workflows. *Should.* Verified: `AiTest`.

## C. Explicitly unchanged / external (do not open as done)

- Microsoft Entra OFPPT consent — external blocker; OTP fallback preserved and tested.
- Institutional averages / certified transcripts — blocked pending approved OFPPT grading policy; feature deliberately absent.
- Real roster import and human-reviewed legacy mapping — external input required; report is read-only until reviewed.
- No issue above may be marked “Done in production”: everything listed is **verified locally only** (SQLite engine, synthetic accounts), pending the CI PostgreSQL run and a supervised school pilot.
