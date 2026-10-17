# ClassLink Adaptive V3 — Product Architecture

**Status:** Phase A architecture, ready for review; not an implementation claim.

**Scope:** Architecture documentation only. No schema, application behavior, dependencies, or review-mode changes.

**Foundation:** Preserve ClassLink V2 and the existing institutional domain. Evolve one product through workspace configuration, not separate applications or rigid editions.

## 1. Approved product decisions

1. Approved Teacher and Admin accounts may initially create new workspaces. Existing account eligibility and authentication remain authoritative.
2. Workspace owners/managers may configure identity, preset, terminology, capabilities, and explicitly authorized new generic workflows supported by their workspace.
3. Ownership does not grant global Super Admin, institutional official-grade access, private-conversation access, or unrelated institutional permissions.
4. Existing institutional data migrates into one explicit **OFPPT compatibility workspace**. Existing admins may become managers. Its permanent owner must be designated explicitly later, never inferred automatically.
5. Disabling a capability hides/disables active workflows, preserves data and appropriate authorized read-only history, and permits restoration on re-enablement. It never deletes grades, assignments, submissions, messages, or revisions.
6. Preserve the existing global role model. Different roles per workspace are deferred; membership architecture must leave room for that later extension.
7. Existing users and migrated workspaces receive the OFPPT preset with **all currently available capabilities enabled**. Onboarding is only for newly created workspaces.

Approval of this architecture does not authorize implementation phases automatically. Each subsequent phase requires its own review gate. No commit or push is part of this documentation phase.

## 2. Current architecture and evidence

Findings describe inspected source, not a fresh runtime, deployment, or test verification.

| Area | Current source and architectural significance |
| --- | --- |
| API families | `backend/routes/api.php` and `backend/routes/school.php`: historical/shared endpoints coexist with institutional endpoints. |
| Global roles | `backend/app/Enums/Role.php`: student, teacher, admin, pending, denied. No per-workspace role model. |
| Institutional structure | `backend/database/migrations/2026_10_07_000010_add_institutional_school_model.php`: years, official groups, modules, offerings, assignments, enrollment periods, delegates. |
| Contextual access | `backend/app/Services/SchoolAccess.php`: active assignments, enrollment, grants, coordinator powers, delegate eligibility, lifecycle constraints. |
| Institutional lifecycle | `backend/app/Services/SchoolSetupService.php`: setup, enrollment, transfers, appointments. |
| Compatibility boundary | `backend/app/Http/Middleware/InstitutionalLegacyBoundary.php` and `backend/app/Models/Concerns/ScopedToOffering.php`: official records cannot safely be treated as ordinary legacy ownership. |
| Official grades | `backend/app/Services/OfficialGradeService.php`: candidate snapshots, drafts, publication, correction revisions, imports, concurrency/roster checks. |
| Privacy | `backend/app/Services/SchoolCommunicationService.php`: explicit participants and continuing eligibility, not unrestricted role-based inbox access. |
| Identity | `backend/app/Services/MicrosoftAccountService.php`, `backend/app/Services/OtpService.php`, `backend/app/Support/RoleDetector.php`: existing Microsoft/OTP and account eligibility. |
| UI/navigation | `frontend/src/router.tsx`, `frontend/src/components/guards.tsx`, `frontend/src/components/AppShell.tsx`: role guards, independent navigation definitions, institutional home. |
| Localization | `frontend/src/i18n/index.tsx`, `frontend/src/i18n/fr.ts`, `frontend/src/i18n/en.ts`, `frontend/src/i18n/school.ts`: existing FR/EN composition and interpolation. |
| Profile/storage | `backend/app/Http/Controllers/ProfileController.php`, `backend/app/Http/Resources/UserResource.php`, `backend/app/Services/MaterialStorageService.php`: initials/profile editing and private resource storage, not profile-photo support. |
| Review infrastructure | `frontend/src/review/README.md`: temporary synthetic presentation of real screens, separate from real authentication. |

### 2.1 No current tenant boundary

The interface calls its institutional home a workspace, but there is no persistent workspace entity isolating organizations. Academic-year names and module codes have global uniqueness; several people, notices, statistics, and administrative queries are application-wide. Microsoft tenant identity does not establish ownership of school data.

The current product contains both historical teacher-owned classrooms and official groups whose authority comes from offerings and contextual assignments. Adding presets or hiding navigation cannot establish independent-workspace isolation.

### 2.2 Current capabilities versus future work

| Workflow | Source status and limitation |
| --- | --- |
| Institutional structure | Implemented: years, groups, modules, offerings, teaching assignments, co-teaching/replacement, coordinators, enrollment periods, module access grants. |
| Admissions/rosters | Implemented: requests/decisions, roster import, enrollment/removal, transfers. |
| Delegates | Implemented: student responsibility, limits, dates, revocation and eligibility. |
| Official grades | Implemented: draft/publish/correction, candidate snapshots, import preview/commit, own published results and exports. Not a general certification/transcript engine. |
| Learning activities | Implemented: resources, announcements, assignments/submissions/feedback, practice quizzes, flashcards. |
| Communication | Implemented: participant-scoped conversations, notices, support/reporting with privacy rules. Not arbitrary organization-wide inbox access. |
| Calendar | Existing deadline aggregation. A general lesson scheduler is **future work**. |
| Progress | Existing quiz history, scores and participation analytics. Flexible skill/progress evaluation is **future work**. |
| Attendance | **Future capability**: no complete attendance sessions/register workflow. An assessment's absent status is not attendance tracking. |
| Certificates | **Future capability**: no complete issuance, verification or revocation workflow. |
| AI | Existing historical-class generation/provider workflows. Not universally integrated into all institutional or generic contexts. |
| Learners without accounts | Imported users can exist before first login, but a true independent learner without a login account is not implemented. |
| Profile pictures | Initials are implemented; upload/preview/replace/remove is future implementation. |

No registry flag may make an unavailable workflow appear implemented. Existing capabilities can also have context-specific coverage; enabling AI, for example, does not create an offering-native AI workflow.

## 3. Generic product and workspace model

ClassLink Core is the shared domain: people, classes, courses/subjects, calendar, resources and communication. Being a core concept does not require every workspace to display every associated workflow. Individual teaching must not require a class, academic year or institutional module.

```text
Global account and existing authentication
  -> Workspace membership
     -> Workspace
        -> People / learners
        -> Optional classes and courses
        -> Teaching contexts (class-based or individual)
        -> Configured workflows and content

Institutional extension within a workspace
  Academic year -> Official group -> Module offering
                                   -> Teaching assignments
                                   -> Official assessments / revisions
```

### 3.1 Workspace

A workspace is the ownership/configuration boundary for a teacher or organization, not a class and not a Microsoft tenant. It has an identity, kind, lifecycle, configuration and explicit ownership/management relationships.

Kinds such as personal, organization and institutional describe organization, not hard-coded feature editions. A preset recommends a configuration independently of kind. Initial multi-campus/legal-institution hierarchies are out of scope; do not invent a second institution hierarchy without a concrete need.

### 3.2 Workspace membership

A membership relates a global user to a workspace and records membership lifecycle. Membership is distinct from learner enrollment, teaching assignment, delegate appointment and conversation participation.

- One user may have multiple workspace memberships while retaining their existing global role.
- Removing a membership must not delete identity or historical academic records.
- A member list must not expose all application accounts.
- Student enrollment or teacher assignment can establish membership only through a deliberate, authorized workflow.
- Do not repurpose the membership table as a duplicate of global role strings. Future per-workspace roles can attach to membership without changing its identity.

### 3.3 Ownership and management

Personal-workspace owners and designated organization managers configure workspace identity, preset, terminology and features. New generic-resource policies can grant explicitly defined management/teaching operations within that workspace. Those are new scoped policies, not bypasses of existing institutional policies.

An owner remains their existing global Teacher/Admin role. Workspace ownership never substitutes for an offering assignment or thread participation. Workspace management does not permit changing global roles, authentication providers, system-wide AI provider credentials, or platform security settings.

The OFPPT compatibility workspace may initially have managers and **no designated permanent owner**. Model this transitional condition explicitly; do not pick the first admin, earliest user or legacy classroom teacher as owner. Ownership transfer and last-manager protections require definition before exposing those operations.

## 4. Learners versus login accounts

A global user is an authentication principal. A learner is a person being taught within a workspace. These concepts must be separable.

Proposed learner records are workspace-scoped and may optionally link to a global user. Account-less learners require neither a fabricated email nor an active login principal. Never reinterpret pending/denied accounts as offline learner records.

Existing institutional foreign keys and historical user-backed results remain intact. A later additive identity bridge can associate existing students with learner records without rewriting grade authorship, candidates, attempts or enrollment history.

Linking a learner to an account requires verified identity and authorized confirmation; matching a name or accepting an unverified email is insufficient. Unlinking must preserve provenance. Teacher-managed observations/homework and online learner submissions are distinct actions with different prerequisites.

## 5. Generic teaching contexts

A teaching context identifies where generic teaching activity belongs: a class or an individual learner engagement, with optional course/subject association. It enables reusable resources, homework, notes, future progress and scheduling without creating fake institutional structure.

The persisted shape is not selected here. Before schema implementation, choose explicit parent relationships or a small context entity with enforceable constraints; avoid unconstrained polymorphic references that lose workspace integrity.

Existing institutional offerings remain authoritative for their content and authorization. A context adapter may reference an offering, but must not rewrite its meaning or replace its checks. Generic course vocabulary must not mechanically rename every offering, classroom or school module into the same entity.

## 6. Capability architecture

### 6.1 Registry

Use a server-owned, typed registry of stable identifiers. Feature enablement and actor permission are separate decisions.

Each definition declares localized label/description keys, implementation availability, supported contexts, dependencies, recommendation metadata, API/service mappings, UI mappings and history policy. Registry definitions live in versioned application code; arbitrary workspace JSON must not define executable rules or permissions.

Proposed identifiers aligned to current concepts:

| Group | Identifiers |
| --- | --- |
| Shared learning | `resources`, `announcements`, `messaging`, `calendar`, `assignments`, `submissions`, `quizzes`, `flashcards` |
| Institutional | `academic_years`, `institutional_modules`, `teaching_assignments`, `official_grades`, `grade_import`, `delegates`, `admissions`, `roster_import`, `transfers` |
| Existing supporting workflows | `study_partners`, `historical_classes`, `quiz_progress`, `ai_tools`, `advanced_administration` |
| Future, unavailable until implemented | `attendance`, `progress_tracking`, `certificates`, `lesson_scheduling` |

The existing `calendar` identifier initially covers deadlines only. `quiz_progress` is intentionally distinct from future `progress_tracking`. A separate exam capability is not implied by assessment type: official assessments already have types; a generic exam workflow would need its own approved design.

Authentication, authorization, isolation, audit recording, integrity and necessary support/reporting safeguards are non-disableable foundations. `advanced_administration` controls supported workspace workflows, never the existence of global security administration or privacy protections.

### 6.2 Persistence

Minimum proposed persistent concepts, with names provisional:

| Concept | Responsibility |
| --- | --- |
| `Workspace` | Identity, kind, lifecycle, explicit owner relationship with supported compatibility transition. |
| `WorkspaceMembership` | User association, lifecycle, explicit workspace-configuration management assignment. |
| `WorkspaceConfiguration` | Preset ID/version, terminology preset/version, validated preferences, configuration revision. |
| `WorkspaceCapability` | Unique workspace/capability identifier and enabled state. |
| `WorkspaceOnboarding` | Version, status, current step, validated draft answers, completion time. |
| `Learner` / identity bridge | Future additive separation of teaching records from login principals. |

Use normalized capability rows and bounded, versioned JSON only for structured preferences/draft answers. Do not create one boolean column per feature or duplicate the feature set in multiple authoritative stores. Registry availability is not a workspace-editable flag.

Resolve each workspace's effective configuration from an explicit persisted snapshot, not a changing live preset. Unsupported/unknown identifiers cannot activate workflows. Missing configuration after migration is an integrity error, not an implicit all-features-on fallback. New registry additions do not silently enable themselves for every existing workspace.

### 6.3 Dependencies and prerequisites

- `submissions` requires `assignments`; online submission requires an eligible account-linked learner.
- `grade_import` requires `official_grades`.
- The current institutional official-grade implementation requires valid offering/year/teaching-assignment structure. This is a context prerequisite, not a universal requirement for all future evaluation methods.
- Delegates require institutional group enrollment and existing appointment rules.
- Flexible skill progress does not require official grades; numerical evaluation does not automatically mean official publication.
- Account participation affects which actions are possible, not whether an owner may enable teacher-managed homework or observations.

Compute the proposed dependent changes before saving. Explain conflicts; require an explicit valid configuration rather than silently enabling/disabling related workflows. Update configuration atomically using a revision check and record an audit event.

### 6.4 Enforcement contract

```text
Eligible authenticated account
AND trusted workspace/resource containment and membership
AND implemented, enabled capability with valid context
AND unchanged existing role/object authorization
AND lifecycle, privacy and integrity constraints
```

New generic endpoints add explicit scoped policies; they never weaken existing policies. Evaluate authorization/containment before returning resource-specific capability details to avoid discovery leaks. Disabled-workflow responses should use a stable machine-readable error contract, with final HTTP semantics selected during API design.

Enforce at backend service/workflow boundaries as well as routes. Cover both API families, direct record URLs, downloads, imports/templates/exports, aggregate queries, notifications and background jobs. Recheck configuration and authorization at execution/commit, not just when a job or import preview is created.

Frontend configuration is a projection for routes, navigation, tabs, actions, empty states and notification destinations. It is not an authorization source. A manually entered URL or stale client cannot bypass backend checks. Configuration refresh must cancel/handle stale actions without losing unsaved input unexpectedly.

## 7. Presets and terminology

### 7.1 Presets

Presets are versioned starting recommendations, not permanent product editions or role assignments. Applying one creates a configuration snapshot. Subsequent preset updates do not overwrite custom choices. Reapplying/changing a preset previews the diff and dependency consequences.

| Preset | Target configuration |
| --- | --- |
| OFPPT / vocational | All existing institutional capabilities; current terminology, workflows and permissions preserved. |
| Language teacher | Classes, resources, homework, communication, quizzes if selected; future attendance and skill progress. No default official-grade publication/delegates. |
| Private tutor | Individual learners, resources, homework, communication; future lesson scheduling, notes/progress experience. No required year/module/group. |
| Training center | Classes, teachers, resources, assignments and evaluations; future attendance/certificates. Scoped organization management. |
| University teacher | Courses/classes, assignments/submissions, resources, announcements, quizzes and selected evaluation. Institutional official grading only where its prerequisites are genuinely configured. |
| General school / club | Recommendations based on organization and teaching needs without mandatory OFPPT structure. |
| Custom | Available workflows chosen explicitly with dependency guidance. |

Future recommendations are roadmap descriptions, not selectable working features. Onboarding and settings must communicate only implemented capabilities as usable.

### 7.2 Terminology

Preserve the FR/EN provider, typed dictionary parity and fallback structure. Introduce semantic concepts and contextual phrase keys rather than replacing substrings or duplicating screens.

Resolution: approved workspace terminology override -> terminology preset -> generic locale default. Support singular/plural and contextual grammatical forms. Configuration stores semantic labels/forms, not arbitrary HTML or complete screen translations.

Examples: learner/student -> Stagiaire for OFPPT; teacher -> Formateur; class -> Groupe; course -> Module; official grade -> Note. Language-teacher skill evaluations remain evaluations/progress, not renamed official-grade records.

Keep teaching assignments distinct from homework assignments, practice scores distinct from official grades, and admission requests distinct from teaching requests. Localization does not change identity eligibility, legal claims or domain semantics. Existing OFPPT branding/legal copy needs a separately reviewed generic public presentation later.

## 8. Onboarding and workspace feature settings

Onboarding belongs to a newly created workspace. It is not triggered by every account's first login or by joining an existing workspace. Migrated users/workspaces bypass it permanently at the migration version. Pending/denied account restrictions remain unchanged.

Conversational, resumable steps:

1. Teaching profile -> recommended preset, not a permanent restriction.
2. Organization: classes, individuals, or both.
3. Evaluation: numerical, percentage, levels, pass/fail, skill progress, none; validate meaningful combinations and implementation availability.
4. Visual selection of available workflows; institutional options are contextual and discoverable without overwhelming simple users.
5. Student account participation: all, some, none. This is a workspace default, not a replacement for each learner's actual account linkage.
6. Collaboration: solo, several teachers, organization team.
7. Review effective configuration and enter workspace.

Persist validated draft answers with an onboarding version and current step. Final confirmation atomically applies a valid configuration and marks completion. Use revision checks for concurrent setup. Invitees do not acquire configuration authority by reaching an onboarding URL.

Settings -> Workspace -> Features later exposes enabled capabilities, prerequisites and disable consequences to authorized managers. Changes preserve history and are reversible. Preset/persona selection never grants a role or institutional permission.

## 9. Feature disabling and historical access

Disabling is configuration, not deletion or permission reassignment.

- Remove active entry points and reject active operations server-side, including queued work, writes, new imports and publication.
- Preserve records, files, authorship, candidate snapshots and revisions.
- Re-enablement restores access only where current authorization/lifecycle allows; it does not revive revoked assignments or delegate appointments.
- Explicit history surfaces may provide authorized read-only access, including a learner's own published results, previous submissions and eligible private conversation history.
- History access must be defined per resource/action. It does not imply that every disabled GET endpoint stays open or that every export is allowed.
- Existing privacy predicates continue to apply: workspace membership or management never opens private threads or classmates' grades.
- Where a person has left a workspace, retained-history eligibility must be explicit and resource-specific; do not require an active membership so broadly that existing personal-results access disappears.

Before implementation, produce an action-level history matrix covering results, assignments/submissions, messages, practice history, files and exports. Historical access does not permit replying, grading, publishing or correcting while its active workflow is disabled.

## 10. OFPPT compatibility mapping and authorization invariants

| Existing concept | Adaptive mapping |
| --- | --- |
| Existing application population | Explicit compatibility-workspace associations derived from approved existing records; no role changes. |
| Historical teacher-owned classroom | Historical class within compatibility workspace; retain ownership and content relationships. |
| Academic year / official group | Institutional structure owned by compatibility workspace. |
| Module / offering | Workspace-local institutional catalog and delivery context; retain IDs and assignments. |
| Enrollment / membership | Preserve distinct lifecycle records and their consistency; not interchangeable with workspace membership. |
| Coordinator / delegate | Existing scoped responsibility, not workspace role or owner. |
| Assessment / revision / candidate | Existing official-grade model unchanged. |
| Conversation / report | Existing explicit participation and privacy boundaries retained. |
| Global AI/security administration | Remains platform administration; never granted through workspace ownership. |

Preserve teacher assignments, co-teaching/replacement, delegate limits, roster locks, assessment versions, publish/correction separation, participant privacy, historical results, audit and auth behavior. Administration does not imply grading rights. Transfers do not move historical grades. Replacement personnel do not inherit private inboxes.

Workspace scoping must cover raw `DB::table` queries as well as Eloquent. Authoritative parent relationships establish resource containment; duplicated workspace IDs, if introduced, require consistency constraints. Never trust a client-supplied workspace ID as proof of ownership.

## 11. Phased migration order

These are implementation substeps for later approved phases, not permission to start them now.

1. **Inventory and contracts:** enumerate resources, raw queries, discovery, files, exports, jobs, cache keys and notifications; identify parent provenance and define API/history contracts. Inventory without exposing real data in fixtures.
2. **Additive containment:** introduce workspace/configuration/membership concepts and one OFPPT compatibility workspace; support deliberately unassigned permanent ownership. Preserve IDs and global roles.
3. **Controlled backfill:** associate existing domain roots and users, materialize all current capabilities enabled, OFPPT terminology/preset and completed migration onboarding. Never match or merge classrooms/offerings by name alone.
4. **Integrity and uniqueness:** make year names/module codes appropriately workspace-local; retain global login identity uniqueness. Define workspace-local learner identifiers. Validate parent consistency, enrollment constraints, indexes and backfill completeness before enforcing non-null constraints where applicable.
5. **Backend isolation and enforcement:** scope all paths, including compatibility endpoints, jobs and signed-download issuance; add capability checks without replacing policies. Preserve legacy API/notification links through verified adapters. Do not expose independent workspace creation before isolation passes.
6. **Configuration projection:** introduce frontend workspace selection/configuration/terminology and feature settings with unchanged V2 components and auth. Active context is validated; never silently fall back to compatibility data on an invalid workspace selection.
7. **Generic contexts and learner separation:** add individual/class-based workflows and verified learner-account linking alongside institutional models. No fake years, groups, emails or offerings.
8. **New-workspace onboarding:** enable creation for approved Teacher/Admin accounts once supported generic paths are real. Onboarding must not promise unavailable attendance, certificates, progress or scheduling.
9. **Future capabilities:** implement each separately with actual models, policies, UI, tests and honest availability metadata. Extend synthetic review only in its approved phase.

Use expand/backfill/validate/enforce sequencing with backups and verified rollback procedures. Do not copy a local database wholesale, generalize the local-only roster importer into a production migration, or delete/rewrite published records for reversibility. Do not roll back by removing newly created user data. Deployment order must prevent old application instances or workers from bypassing new containment during transition.

## 12. Target scenarios and acceptance boundaries

### Language teacher

An approved teacher creates a new workspace, selects Language Teacher, organizes classes, selects homework/resources/messages and available tools, and declines official grades. Once future progress is implemented, speaking, writing, vocabulary and participation can use configurable levels such as Needs attention/Developing/Good/Very good/Excellent. Numerical grading and institutional publication are not required. Do not claim this scenario complete until its generic paths and non-official evaluation actually work.

### Private tutor

An approved teacher manages one account-less learner directly, without a class/year/module. Resources and teacher-managed homework relate to the individual teaching context. Future lesson scheduling/notes and flexible progress have independent workflows; online submission/messaging needs actual account participation. This is not achieved by hiding an institutional setup screen.

### Organization / training center

Owners/managers configure identity and features; several approved teachers collaborate with scoped contexts. Membership does not grant access to every class or private record. Future attendance/certificates remain unavailable until implemented. Platform administration remains distinct from organization management.

### Institutional / OFPPT

Existing users enter the compatibility workspace with no onboarding and no capability loss. Existing years/groups/modules/offerings, admissions, assignments, delegates, grades, imports, communications, history and audit retain their current meaning and authorization. Owner designation is a deliberate later operation. A workspace manager cannot use configuration to grant themselves teaching or grading access.

## 13. V2, personalization and temporary review boundaries

Keep the current component system, semantic Light/Dark tokens, FR/EN behavior and accessibility foundation. No design-library replacement. Motion evaluation and the living-UI specification belong to later phases; this document installs no animation runtime and defines no animation implementation.

Profile-photo work needs a dedicated authorization/storage contract, visibility decision, image validation/re-encoding, metadata handling, replacement/removal lifecycle and accessible fallback. Existing private material storage is useful infrastructure, not an already implemented avatar API.

`/v2-review` remains temporary, synthetic and independent of production workspace architecture. Reuse production contracts through synthetic adapters later; never treat fixture success as proof of authorization. Do not mix it with the separate backend temporary real-account login mechanism.

Static inspection identified follow-up review-isolation concerns: a historical AI-review action performs hard browser navigation outside anchor interception, and quiz attempts use ordinary attempt-storage keys. Validate these in the later review phase before extending scenarios. No review code is changed in Phase A.

## 14. Known risks and decisions before schema implementation

### Blockers for the schema/contract design gate

1. **Containment inventory:** finish a resource-by-resource ownership and query matrix, including application-wide notices, people lookup, statistics, imports, jobs, caches, files and historical data. Define migration invariants and mixed-version deployment safety.
2. **Concrete schema shape:** choose generic teaching-context relationships, learner-account bridge, membership-management representation, transitional ownership constraints and workspace-scoped uniqueness. Preserve all existing institutional foreign-key semantics.
3. **History contract:** approve the action/resource matrix, particularly own-result access after membership departure, private-thread history, attachments and exports when disabled. Existing authorization remains the baseline.
4. **Management lifecycle:** define explicit permanent-owner designation, transfer, invitation and last-manager protection. Owner designation itself may occur later; the schema must permit the approved owner-unassigned compatibility state.
5. **Account linking:** specify proof/confirmation and conflict handling before implementing true account-less learner linkage. Generic signup/authentication expansion is separate and unapproved.
6. **Workspace API context:** select explicit workspace resolution/selection and cross-workspace error behavior, including how legacy URLs remain compatible without exposing another workspace.

These are detailed implementation decisions, not a reopening of the approved ownership/capability principles. Permanent owner identity must not be guessed to unblock migration.

### Additional risks and later decisions

- Raw queries and application-wide aggregates can bypass incomplete scoping; model scopes alone are insufficient.
- Enrollment rows and accepted memberships are distinct synchronized states; migration must detect drift rather than invent reconciliations.
- Revoked/future-dated assignments and archive rules need parity across institutional and compatibility endpoints.
- Storage accounting is currently application-wide; per-workspace quotas require a separate explicit design. Previously issued signed links remain usable until expiry under their existing contract.
- Current OFPPT identity eligibility and Casablanca timezone assumptions are broader than terminology. Preserve current auth and compatibility timezone; design per-workspace timezone before generic scheduling.
- Global roles constrain teacher-as-student scenarios across workspaces; this limitation is deliberate until separate authorization approval.
- Institutional averages, rounding, certification authority, attendance semantics and flexible evaluation scales require their own domain specifications. Do not invent consequential school rules.
- Profile-photo visibility and legal/public presentation changes require later decisions.
- Future preset defaults must never be marketed as working workflows before availability changes.

## 15. Verification strategy for later phases

Keep existing tests. Add meaningful negative and lifecycle tests for cross-workspace containment, disabled capabilities, history preservation, dependency validation, preset snapshots, migration/backfill, terminology, onboarding, concurrent configuration, learner linking, and unchanged role/object authorization.

Cover direct API calls, raw-query aggregates, downloads, jobs, both route families, stale previews and disabled-feature deep links. Explicitly test that owners/admins cannot gain grade/private-thread access, delegates stay students, and migrated users never receive onboarding.

Later implementation gates run the backend full suite, frontend full suite, TypeScript, production build, browser verification and `git diff --check`. Use isolated test configuration; do not bootstrap a production database for inspection/testing. Visual gates cover FR/EN, Light/Dark, keyboard/focus/reduced motion and representative widths 320–1920; the experience specification also covers intermediate mobile widths and responsive motion.

For **this documentation-only Phase A**, verification is limited to document review, file-scope inspection and `git diff --check`. No runtime behavior, schema, generic-workspace support, future capability, Motion integration or Rive mascot is claimed complete.

## 16. Phase gate

Phase A provides the architecture for review. Stop after the documentation report. Do not begin Phase B, schema work, product implementation, dependency installation, review-mode extension, commits or pushes without the applicable explicit instruction.
