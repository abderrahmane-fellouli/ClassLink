# ClassLink — Figma design implementation map

**Status: mapping baseline plus implementation decisions.** See
[`FIGMA_IMPLEMENTATION_REPORT.md`](FIGMA_IMPLEMENTATION_REPORT.md) for the actual
implementation and verification record. “Current” implementation descriptions below
refer to the pre-redesign mapping baseline unless superseded here.

### Implementation decisions resolving the mapping

- The shared system is implemented in `index.css`; both themes use semantic
  foreground/background/border pairs. Inter is used throughout; token and font
  decisions are based on the exported direction and consistent design judgment,
  not a claim that native variable IDs were programmatically recovered.
- A frontend-only `ThemeProvider` persists `classlink.theme`, with OS preference as
  the initial fallback. It does not change profile, locale, authentication or APIs.
- Permanent navigation begins at 1024px; tablet uses the existing accessible dialog
  as a drawer. Locale remains directly reachable in the compact header. Mobile theme
  selection is in the drawer/profile; wider headers also expose it directly.
- Student bottom navigation uses existing home, grades, messages and deadline
  destinations. All historical/admission/partner/profile routes remain reachable.
- The priority surface presents the first existing deadline as **next deadline**,
  not an invented assignment eligibility/urgency decision. No new result/resource
  feed or unsupported submission status is fabricated to fill the screenshot.
- Assignment uses the actual instructions and own submission in a responsive
  two-column/stacked workspace. Mock document attachments and unsupported context
  joins are not invented.
- Grade save/template actions move above the existing grid; all original state,
  revision, validation, correction and publication guards remain unchanged.
- Roster preview displays actual valid rows in a responsive table and numbered
  localized errors separately. No inferred invalid-row identity or row correlation
  is added to the API contract.
- The live shell, shared components and all rendered routes inherit V2, rather than
  migrating only the four foundation surfaces. See the report's route inventory.

The Figma references determine visual presentation. The existing application,
[`FEATURE_UX_VALIDATION.md`](FEATURE_UX_VALIDATION.md), and
[`UX_SATISFACTION_AUDIT.md`](UX_SATISFACTION_AUDIT.md) determine behavior, routes,
data scope, permissions, authorization, and lifecycle rules. A visible control or
sample result in a screenshot is not permission to add a workflow or expose data.

## 1. Evidence, priority, and extraction limits

All five PNGs were opened and visually inspected. Pillow verified their PNG
structure; the files are nonempty and rendered without obvious corruption.
These are **multi-artboard reference boards**, not five application routes or
single-viewport screenshots. Their bitmap dimensions are not CSS viewport sizes.

| Priority | Reference under `design/figma/exports/` | Actual bitmap |     Bytes | Interpretation                                                                                                         |
| -------- | --------------------------------------- | ------------- | --------: | ---------------------------------------------------------------------------------------------------------------------- |
| 1        | `student-v2-responsive-light-dark.png`  | 3136 × 5652   | 1,080,417 | Primary V2 direction: authored 390, 768, and 1440 compositions in Light and Dark                                       |
| 2        | `foundation-v2-explorations.png`        | 4736 × 2320   |   648,944 | Assignment Detail, Teacher Grade Grid, and Admin Roster/Import: presentation explorations, predominantly desktop Light |
| 3        | `responsive-stress-findings.png`        | 1496 × 664    |   100,082 | Explicit constraints for nine widths; adaptive compositions, not scaled desktop layouts                                |
| 4        | `localization-stress-findings.png`      | 1464 × 938    |   144,237 | FR/EN expansion, state geometry, long-content and validation constraints                                               |
| 5        | `original-baseline.png`                 | 6364 × 12900  | 4,388,825 | Historical comparison only; not a fallback specification for missing V2 screens                                        |

Native source: [`design/figma/ClassLink.fig`](../design/figma/ClassLink.fig).
The native file is retained unchanged. PNG inspection does not expose its variable
IDs, font metadata, component properties, Auto Layout constraints, or exact state
tokens. Values below distinguish **observed raster colors**, **written constraints**,
and **proposed implementation measurements**. Confirm native variable bindings in
Figma before claiming an exact token or pixel-perfect implementation.

Source review covered `frontend/src/router.tsx`, `index.css`, `components/UI.tsx`,
`components/AppShell.tsx`, the foundation screen implementations, their frontend
contracts, i18n utilities, and both verified UX documents. Historical test totals in
those documents describe their earlier validation run; they are not new V2 test
results. No application, API, browser journey, or database mutation was run for this map.

The Figma README now documents the five supplied reference boards; earlier references
to missing six-file exports have been removed. The native source and images remain
unchanged.

## 2. Extracted design system

### 2.1 Visual hierarchy

Student V2 is an editorial workspace, not a grid of equally prominent cards:

1. Compact shell and contextual identity/year.
2. A greeting and one visually dominant assignment/task surface.
3. Chronological deadlines, with date, meaningful title, context, and action.
4. One integrated, quieter updates surface rather than multiple nested cards.
5. Module/resource support links in a subordinate section.

White/raised surfaces are reserved for priority or interactive work. Other content
sits directly on the canvas or on a tonal section. Dark mode preserves hierarchy
through distinct layers, rather than making every surface the same dark color.
Do not copy the historical board's older composition where it conflicts with V2.

### 2.2 Colors and Light/Dark surfaces

The following flat RGB colors occur in the current V2 exports. Semantic names are
**mapping proposals**, not recovered native Figma variable names. Antialiased text
pixels and bitmap prevalence alone do not establish an authoritative token.

| Proposed semantic role         | Light raster evidence                             | Dark raster evidence                                                         | Intended use                                                                 |
| ------------------------------ | ------------------------------------------------- | ---------------------------------------------------------------------------- | ---------------------------------------------------------------------------- |
| Canvas                         | `#F5F4F0`                                         | `#111A24`                                                                    | Ordinary page background                                                     |
| Quiet shell/integrated section | `#EDEFEF`                                         | `#202E3D`                                                                    | Sidebar and updates layer; avoid white-card nesting                          |
| Priority task surface          | `#FFFFFF`                                         | `#253343`                                                                    | The dominant Student V2 assignment block                                     |
| Secondary raised/panel surface | `#FCFCFA` in foundation panels                    | `#1B2734` is present, but exact panel-role binding needs native confirmation | Submission/action panels; do not flatten all dark layers                     |
| Primary text                   | `#182A3B`                                         | `#ECF1F5`                                                                    | Titles and readable body emphasis                                            |
| Secondary text                 | `#465768`                                         | `#BBC8D4`                                                                    | Context, metadata, descriptions; still readable at small sizes               |
| Primary action/link            | `#345F86`                                         | `#A4C6E6`                                                                    | Filled primary action and readable links; separate foreground token per mode |
| Selected navigation/row tint   | `#E3EBF2` / `#E6EDF3`                             | `#30465B` / `#2C4155`                                                        | Selected item, focused-row context; not a permission indicator               |
| Info/status tint               | `#E7EFF5`                                         | `#293F55`                                                                    | Informational chips and contextual guidance                                  |
| Neutral divider/control edge   | `#D4DBDF`; subtle warm edge `#DDDCD8` also occurs | `#435566` occurs                                                             | Thin separators and control boundaries; verify exact bindings                |
| Disabled surface               | `#E5E9EC` in foundation board                     | Not fully specified by these V2 boards                                       | Disabled is a distinct state, not hidden text                                |

Status evidence: the foundation board uses muted green ready/open states
(`#E5F0EA` fill), amber correction/dirty states (`#F6EDDC` fill, `#A88A51`
edge/emphasis), and the localization board uses a red validation treatment
(`#963F3B` occurs in error text). Full Light/Dark status foreground, border, hover,
and disabled pairs need native confirmation. Do not borrow unapproved dark status
colors from the historical baseline merely because they appear there.

Map to semantic variables in `frontend/src/index.css` and its existing `@theme`
aliases. Keep separate tokens for primary foreground, shell foreground, selected
background, focus ring, and each status foreground/background/border. A single
`--primary` plus generic white text is insufficient for the pale-blue Dark button.
Measure actual text/control contrast after implementation; a PNG is not a contrast
or accessibility certification.

### 2.3 Typography

- Observed: restrained sans-serif, semibold headings, quieter metadata, readable
  multiline titles; less decorative display typography than current Poppins headings.
- Written responsive constraint: **14px metadata and 44px controls at the authored
  390px composition**. Do not reproduce visually tiny text by scaling a board preview.
- Proposed hierarchy for native-source confirmation: body 16px; metadata 14px;
  section headings 18–20px; desktop page heading 28–32px; phone page heading
  22–24px; long task/detail titles 20–24px with comfortable multiline leading.
  These are implementation starting ranges, not measured Figma font declarations.
- Current `index.css` loads Inter body, Poppins display, JetBrains Mono. Inter is a
  plausible reuse candidate, but exact V2 family/weight must be checked in Figma.
  Avoid assuming a screenshot proves a font family. Preserve useful technical
  monospace where identifiers genuinely benefit from it.
- Use real heading levels, not visual heading sizes on arbitrary paragraphs. Keep
  body/instruction measure around 60–75 characters as a proposed readability bound.

### 2.4 Spacing, radii, borders, elevation, and focus

| Property     | Extracted direction                                                                                               | Proposed implementation mapping / confirmation                                                                                                  |
| ------------ | ----------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------- |
| Spacing      | Clear section breathing room, tighter related metadata, aligned columns; much less repeated panel padding         | Tokenize a 4/8/12/16/24/32/48px scale; verify individual Figma gaps rather than scatter literals                                                |
| Gutters      | Narrow but usable phone inset; broader desktop gutter                                                             | Start with 16px phone, 24–32px tablet, up to 48px desktop; native confirmation required                                                         |
| Radii        | Modest rounded task and action panels, smaller controls/chips, mostly square integrated sections                  | Separate control/chip/panel tokens; approximately 4–8px controls and 12–16px priority panels as provisional ranges; no universal pill treatment |
| Borders      | Quiet 1px dividers, contextual table row lines, explicit field edges                                              | Distinguish dividers from input boundaries and selected/focused edges                                                                           |
| Elevation    | Tonal separation dominates; not a shadow on every list or section                                                 | Default flat sections; very low panel elevation; reserve overlay elevation for dialogs; exact shadows not recoverable from PNGs                 |
| Focus        | Foundation grid shows explicit blue focus around an input                                                         | Keep visible mode-aware focus on all controls; existing 2px offset outline is a reusable starting point, not a confirmed Figma measurement      |
| Motion/state | Localization note requires stable geometry across default/hover/pressed/focus/loading/disabled for the same label | No scale-transform press behavior; reserve spinner space; borders/rings must not shift layout; preserve reduced-motion support                  |

### 2.5 Controls and status semantics

- Primary: muted-blue filled rectangle in Light, pale-blue filled rectangle with
  dark text in Dark. Secondary: quiet outlined/neutral action. Tertiary: text link.
- Native editable input/select/textarea behavior remains. A screenshot's filename
  field is a selected-file display, not authorization to edit a file path manually.
- Inputs have labels above and errors below. Native single-line text fields may
  scroll horizontally while editing; surrounding layout and errors must reflow.
- Statuses are compact rounded rectangles with text, sometimes border/tint, not
  large saturated pills. Wrap long labels. Use icons/text as well as color.
- Keep existing grade statuses `graded`, `ungraded`, `absent`, `exempt`, `makeup`;
  assignment publication states; import errors; draft/correction/published states.
  A visual label such as “ready” must derive from actual validation, not a new rule.

## 3. App shell mapping

**Current owner:** `frontend/src/components/AppShell.tsx` (`AppShell`, `navFor`,
`NotificationPanel`), with shell classes in `index.css`.

| Area    | V2 visual target                                                                                                                      | Current mapping and preserved behavior                                                                                                                                                                                |
| ------- | ------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Sidebar | Quiet gray Light / slate Dark column; compact wordmark, small line icons, tinted active row, separated identity/profile/language area | Refactor `sidebarContent` and CSS tokens, retaining role-specific `navFor` destinations, authorized links, sign-out confirmation and locale save/recovery                                                             |
| Topbar  | Persistent shell header on desktop as well as smaller widths; context on the left, notification/account cluster on the right          | Current desktop has only a notification button inside `<main>`; refactor presentation into one shell header. Reuse notification loading/read/all-read/error/deep-link behavior; no new account menu workflow inferred |
| Desktop | Sidebar beside centered content; full task surface, three-column editorial deadlines, two-column updates/support                      | Keep one `.app-content` width owner and `min-width: 0`; avoid screen wrappers adding a second outer gutter                                                                                                            |
| Tablet  | Authored 768px board has a compact header and **no persistent sidebar**; task remains stacked, selective two-column support           | Current sidebar appears at `md`/768 and consumes 248px. Move permanent sidebar to a confirmed desktop breakpoint; use existing accessible menu dialog for remaining navigation                                        |
| Mobile  | Compact menu/wordmark/bell/avatar header; single-column content and four useful bottom destinations                                   | Reuse menu `Dialog`, skip link, active links and safe-area treatment. Keep every existing destination reachable; do not reduce role capabilities to the four visible items                                            |
| Width   | Responsive findings cap ordinary dashboard content at 1200px on a 1920px screen                                                       | Reuse `--page-width: 1200px`; center within main area after sidebar. Comparison regions can use their own bounded inner scroll, not body overflow                                                                     |

The Student V2 four visible destinations map to existing routes: institutional home
(`/app`, with `/app/school` retained), `/app/deadlines`, `/app/school/grades`, and
`/app/school/messages`. Current student bottom navigation instead includes both
home and school, without deadlines. Proposed change: show one home entry and the
existing deadlines entry; keep aliases and all historical/partner/admission/profile
links in secondary navigation. This navigation regrouping needs review, not a new
route or permissions model. Preserve active-route treatment for nested pages.

The apparent desktop sidebar is approximately 240px in the authored composition;
use this only as a sizing candidate against the current 248px token. A proposed
1024px desktop switch fits the written laptop constraint, but exact 1024px sidebar
behavior is not explicitly drawn and must be reviewed for usable main-column width.
At 768px, the supplied tablet composition is unambiguous.

There is no existing theme provider in the inspected shell/bootstrap. Dark visuals
require semantic theme values and an agreed frontend initialization/preference
policy, potentially in `App.tsx`/`main.tsx`. These are future integration points,
not files changed now. Do not invent a backend profile preference or a theme-toggle
page. Existing locale persistence is not theme persistence.

## 4. Shared component implementation mapping

“Replace” below means replace visual markup/treatment **inside the existing owner**,
not replace its API contract, event handling, authorization, or route.

| Figma pattern → current React owner/path                                                                                                  | Decision                                               | Implementation notes                                                                                                                                                                                         |
| ----------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Filled/outlined/text actions → `components/UI.tsx: Btn`                                                                                   | Refactor                                               | Semantic mode-aware variants, stable state geometry and wrapping; replace current hard-coded hover and `active:scale-[0.98]`; preserve type, disabled, pending and handlers                                  |
| Form input/error → `UI.tsx: Field, Input, Textarea`; `SchoolScreens.tsx: Form, inputClass`                                                | Reuse semantics; refactor styling                      | Consolidate duplicated field styles. Preserve label IDs, `aria-describedby`, `aria-invalid`, native constraints, controlled values and retained failed input                                                 |
| Native selects → `UI.tsx: Select`; inline selects in `Form`, grade editor, roster and admin screens                                       | Refactor                                               | One field-height/border/focus system; long selected values remain inspectable; no custom combobox behavior required by screenshots                                                                           |
| Compact status chip → `UI.tsx: Badge`, inline lifecycle/preview status text                                                               | Refactor                                               | Replace global rounded-full/saturated palettes with semantic tinted/bordered chips; preserve all machine statuses and translated labels                                                                      |
| Guidance, dirty/error/success callout → `UI.tsx: Alert`, inline preview messages                                                          | Refactor                                               | Quiet tonal surface and top-aligned icon for multiline text; keep `role=alert` vs `role=status` and explicit blocked-action reasons                                                                          |
| Section tabs → `UI.tsx: Tabs`, `SchoolScreens.tsx: ModuleSpace`, profile/class tab consumers                                              | Refactor appearance; reuse interactions                | V2 boards do not define a complete tab system. Extend their type/divider/active treatment conservatively; preserve Arrow/Home/End, focus and tab selection. Do not remove content tabs                       |
| Modal/confirmation → `UI.tsx: Dialog, ConfirmButton`; `components/EditButton.tsx`                                                         | Reuse behavioral core; refactor surface                | Mode-aware overlay/panel and wrapping footer. Preserve Escape, focus trap/return, close, body scroll lock, confirmation and in-flight guards; no new modal workflow inferred                                 |
| Editorial deadline/update lists → `SchoolScreens.tsx: SchoolDeadlinesPanel, SchoolStudentUpdates`                                         | Replace visual composition                             | Dividers and columns on desktop, chronological rows on tablet, stacked phone rows; preserve returned URLs, full announcement bodies and scoped class notices                                                 |
| Grade comparison table → `SchoolScreens.tsx: OfficialGradeEditor`; `.grade-table` in `index.css`                                          | Refactor                                               | Native table on desktop; contextual selected row/focus and quieter header. Keep real keyed inputs, row labels and error association; labelled mobile rows remain a valid implementation fallback             |
| Roster/import preview table → `SchoolScreens.tsx: RosterPanel`; admin `.ui-table`/`.ui-responsive-table` consumers                        | Replace preview/list presentation where data permits   | Ready/correction counts, blocking explanation and row reasons; retain batch IDs, pagination, role guards and full stable identities. Do not fabricate absent row fields                                      |
| Priority card / action panel / integrated section → `UI.tsx: Card`; `SchoolScreens.tsx: panelClass`; local screen `<section>`/`<article>` | Refactor into explicit visual variants                 | A universal bordered/shadowed Card must not wrap everything. Preserve interactive Card keyboard behavior where used; static sections remain static, explicit links remain links                              |
| Empty state → `UI.tsx: EmptyState`; local workspace/import empty messages                                                                 | Reuse; refactor density                                | Explain genuine no-group/no-assignment/no-search-results state with only allowed next action; do not populate empty screens with screenshot fixtures                                                         |
| Loading → `UI.tsx: Spinner, Skeleton, SkeletonList, AsyncBoundary`; `SchoolScreens.tsx: State`                                            | Reuse; theme/refine                                    | Preserve announced progress and initial/background loading distinction; do not remount forms or reset local files/search during refresh                                                                      |
| Error/recovery → `UI.tsx: ErrorState, AsyncBoundary`; `router.tsx: RouteErrorScreen`; `components/guards.tsx`                             | Reuse; refactor surface                                | Keep retry and auth/403/404/network distinctions, sanitized messages and preserved return path; visual polish cannot convert denial into access                                                              |
| Resource/file row → `StudentScreens.tsx: MaterialList, AssignmentDetailScreen`; `SchoolScreens.tsx: ModuleSpace` material branch          | Refactor / extract shared presentational row if useful | Full filename/title, context and download/open action; replace truncation in submission filename. Keep `api.download`/endpoint helpers and external-link safeguards; no public/private-file URL substitution |
| File selection/submission/import → native file inputs in `AssignmentDetailScreen`, `RosterPanel`, `OfficialGradeEditor`                   | Reuse; replace decorative dropzone treatment           | Clearly separate selected filename, choose/change file and submit/preview action; retain native keyboard file chooser, size/type validation and preview invalidation on file change                          |
| Page title/context/breadcrumb → `UI.tsx: PageHeader`, foundation screen headings and existing `Link`s                                     | Refactor                                               | Context → full title → status/deadline → section actions; no new route for screenshot breadcrumbs; make long titles wrap                                                                                     |
| Avatar/notification/language → `UI.tsx: Avatar, Icons, LocaleSwitch`; shell's inline initials and `NotificationPanel`                     | Consolidate/refactor visuals                           | V2 initials are compact rounded squares rather than current circles. Use only authenticated identity; preserve the existing language selector and all notification behavior                                  |
| Switches, progress and stats on non-foundation screens → `UI.tsx: Toggle, ProgressBar, StatTile`                                          | Reuse; restyle                                         | Not fully specified by V2 boards. Inherit semantic colors/type/spacing without adding dashboard metrics or changing switch meaning                                                                           |
| Pagination/copy/edit utilities → `SchoolScreens.tsx: Pages`, `components/CopyButton.tsx`, `components/EditButton.tsx`                     | Reuse; inherit controls                                | No new pagination workflow; preserve hidden one-page pagination and existing edit/copy feedback                                                                                                              |

All paths above are relative to `frontend/src/`. There is currently no dedicated
shared Table or FileRow component. Such extractions would be future presentation
refactors, not existing component names to import today.

## 5. Foundation-screen mapping and behavioral boundaries

### 5.1 Student V2 dashboard → institutional home

**Mounted owner:** `SchoolWorkspace` in `screens/SchoolScreens.tsx`, reached through
`RoleHome` at `/app` and directly at `/app/school`. `StudentDashboard` exported from
`StudentScreens.tsx` is **not mounted**; do not implement the redesign there and
mistake it for the live home. `SchoolWorkspace` also serves teacher/admin variants.

**Visual restructuring:**

- Add V2 greeting/context hierarchy for the student branch, not admin setup.
- Recompose the existing deadline information into a dominant eligible task plus
  the chronological list, with deadline beside title on desktop and below it on
  smaller widths. Preserve chronology and dates, not a new urgency algorithm.
- Rework `SchoolStudentUpdates` into one tonal updates surface. Retain class-notice
  links and announcement bodies, even where the mock shows only a subset.
- Present actual authorized modules as quieter support links rather than nested
  group/module cards of equal prominence. Keep group search/selection where needed
  for multi-group or historical access; the single-group fixture does not remove it.
- Keep delegate responsibility guidance, no-group admission guidance, module-only
  restrictions and read-only/archived context visible when applicable.

**Existing data:** `/school` overview for groups/offerings/teachers/delegates;
`/school/deadlines` for `SchoolDeadline` (kind, ID, title, due timestamp, URL);
`/me/announcements` and `/school/threads` for existing updates. Keep the API's
authorized scope and returned links. Assessment dates are date-only where the
existing renderer uses `formatDate`; assignment timestamps use `formatDateTime`.

**Data/design gaps:** the deadline DTO does not supply module subtitle, publication
status, submission eligibility or a task-priority decision. The current updates
panel does not fetch personal official results, and the overview is not a global
resource feed. A screenshot's priority choice, result summary and resource list are
not new API fields. Use an existing authorized contract only after confirming its
relationship to the selected item; otherwise retain the existing truthful content
or defer that portion. Do not infer “awaiting submission” from a title/deadline alone,
invent ranking, fetch peer results, or issue unbounded per-module requests to fill
decorative rows. A result link stays personal and goes to existing grades routes.

### 5.2 Assignment Detail V2 → existing detail/submission screen

**Owner:** `AssignmentDetailScreen`, `screens/StudentScreens.tsx`,
`/app/assignments/:assignmentId`.

**Visual restructuring:** replace the current narrow `max-w-2xl` vertical pile with
context/title/status/deadline followed by a readable instruction column and a
secondary submission panel on sufficiently wide screens. Use a single-column
reading/submission sequence on phone/tablet, without forcing a tiny side panel.
Restyle submitted-file/feedback states as well as the not-yet-submitted panel.
Use quiet document rows when a real authorized resource relationship exists.

**Preserve:** `assignments.show`, `assignments.submit`,
`assignments.downloadSubmission` in `lib/endpoints.ts`; `my_submission` isolation;
single-submission behavior; selected `File`; the existing 10 MiB client limit and
server type/size checks; pending/error/retry/success; closed-publication guard;
server-provided `is_late`, grade and feedback. Do not add resubmission, multiple
attachments, filename editing, autosave, or a client deadline ban inferred from
“send before” wording. Preserve the actual server rules for late work.

**Gaps:** `ApiAssignment` in `lib/types.ts` provides classroom ID, instructions,
deadline and own submission, but no explicit assignment-document collection or
offering ID in its declared contract. The six mock documents are not proof of
assignment attachments. Module resources must be labelled as module resources and
loaded only through a verified existing authorized context. Do not request a blocked
legacy class record just to reconstruct an official breadcrumb. Current back action
uses browser history; agree a safe existing module-return context before replacing it.

### 5.3 Teacher Grade Grid V2 → official assessment editor

**Owner:** `OfficialGradeEditor`, `screens/SchoolScreens.tsx`,
`/app/school/assessments/:id`. This is **not** the legacy `GradeScreen` for assignment
submissions and not practice quiz results.

**Visual restructuring:** context/title/scale → saved/dirty revision and primary
save/import/template actions in a compact panel → labelled comparison grid →
publication section with reason for any disabled action → correction/history.
Use a quiet header, horizontal rules, clear focused row/cell and full student names.
Keep mobile labelled student rows and a non-obscuring inline action group; introduce
bounded comparison scrolling only where preserving column comparison requires it.

**Preserve exactly:**

- `GradeEditorData`, server `read_only`, version and roster-version checks; row
  identity is `student_id`, never display name or visual row position.
- Dirty buffer, `dirtyRef`, `versionRef`, route/unload blocker, retained edits during
  locale/background refresh, and actual server conflict handling.
- Explicit draft save, no autosave. Typing a number selects `graded`; blank is not
  zero; **0 remains a valid score**. Absence/exemption/makeup clear numeric scores.
- The assessment's real `maximum_score`, not a hard-coded `/20` from the fixture.
- CSV/XLSX template download, preview and confirmed commit; template context/IDs,
  localized row errors, pending and read-only guards.
- Save/validity/completion/summary requirements for publication; confirmation;
  correction reason, published-result continuity, history and roster reconciliation.
  These controls cannot disappear because a single mock snapshot does not show them.
- Existing teacher route guard/assigned-assessment authorization. Admin screenshot
  navigation elsewhere does not grant admin access to this teacher-only route.

The exploration's saved version and unsaved counts should derive from actual state;
do not display invented server revision history or counts. Group/module context can
only join through authorized existing overview/offering data. Keep action handlers
and endpoint payloads even if action placement changes.

### 5.4 Admin Roster V2 → official roster/import workflow

**Owner:** `RosterPanel` nested in `SchoolWorkspace`, `screens/SchoolScreens.tsx`,
under `/app/school` (also reachable via shared `/app` workspace). There is no current
standalone “admin roster/import” route. `/app/admin/users` manages accounts and is
not this import workflow. The operator-only local-school CLI is also not this UI.

**Visual restructuring:** contextual import title → selected file/preview counts →
explicit “no enrollment changed yet” callout → correction/change-file and confirmed
commit controls → labelled preview rows with ready/correction status and localized
reason → institutional scope explanation. Keep actual roster/search/pagination,
manual admission, pending decisions, transfers and delegates in contextual sections.
The preview is an in-workflow state, not an invented new page.

**Preserve:** admin or specifically roster-authorized coordinator scope; teacher
assignment alone grants no roster management; admin-only branches remain admin-only
(including current manual account lookup/admission, transfers and delegate actions).
Keep `group.is_read_only`, archived checks, accepted membership and scoped identity
visibility; a student roster must not gain identifiers or emails from the admin mock.

Keep file selection invalidating preview, `.csv/.txt` input, preview via
`/school/groups/:id/roster-imports/preview`, server `batch_id`, zero mutation at preview,
errors blocking commit, confirmation and commit via the corresponding `/commit`.
Stable institutional identity comes from supplied data, not display-name matching.
Transfer conflicts require the existing explicit admin action; no silent movement
or grade migration and no automatic delegate appointment.

**Important contract limit:** current local preview typing contains valid rows with
`student_identifier`/`display_name`, errors with `row`/`reason`, `new_accounts`, and
`batch_id`. It does not declare a complete ordered record for every invalid CSV row
with its original name/identifier. Do not invent a unified six-row table from that
type, assume rows align by index, or copy raw invalid identities from fixtures. Render
verified valid rows plus a clear numbered error section until row correlation is
confirmed against the existing API contract. Any required contract change is a
separate product/API review, outside a visual-only refactor. “Correct file” can reuse
the chooser; it must not silently rewrite the source CSV.

## 6. Responsive implementation strategy

The stress board explicitly requires breakpoint-selected phone/tablet/desktop
compositions, never scaled duplicates. The following viewport outcomes are required;
CSS mechanics between sampled widths are proposed, not an inferred Figma rule.

| Width | Required transformation / acceptance criterion                                                                                                                                       |
| ----: | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
|   320 | Single column, full long titles. Primary action may legitimately move below the initial viewport. No clipping, ellipsis, forced fixed-height hero or body-level horizontal scrolling |
|   360 | Single task → chronological deadlines → updates → resources. Actions wrap or fill width; preserve reading order and no page overflow                                                 |
|   390 | Match authored Light/Dark phone hierarchy; 44px controls and readable 14px metadata; compact header and four student navigation destinations                                         |
|   430 | Phone grows naturally, not a miniature desktop or prematurely split side panel                                                                                                       |
|   768 | Match authored tablet: header/menu rather than permanent sidebar; task/deadline content stays stacked; selective two-column updates/support only if long content fits                |
|  1024 | Laptop layout remains readable. Candidate desktop sidebar switch at 1024, subject to review; collapse side content rather than create narrow one-field columns                       |
|  1280 | Desktop editorial deadline columns and integrated update/support sections are scannable; full context/title/action alignment                                                         |
|  1440 | Match authored Light/Dark desktop hierarchy and deliberately distinct surfaces; foundation workspaces fit readable comparison/action regions                                         |
|  1920 | Ordinary dashboard main container capped at 1200px and centered; text/cards do not stretch across the monitor                                                                        |

**Proposed layout mechanics:** mobile-first single column; optional support columns
at tablet; desktop sidebar at an agreed large breakpoint; CSS grid with `minmax(0,1fr)`
and flexible action wrapping. Do not apply one breakpoint blindly to every child:
an assignment's submission rail should appear only when both columns remain useful.

**Lists and tables:** ordinary deadline/resource lists become stacked labelled rows
on phone and aligned/columnar sections on desktop. Roster and grade rows retain
name/identity, every meaningful status, feedback/error and action when stacked.
The current `.grade-table` and `.ui-responsive-table` mobile transformations already
provide an implementation base; restyle them rather than delete mobile affordances.
Headers retain table semantics even when visually moved to row labels.

**Comparison scrolling:** for a genuine wide comparison table, constrain overflow
to its own named, keyboard-reachable region within the main column; use contextual
labels and a visible scroll affordance. Keep names/actions discoverable and focus
visible. Do not hide overflow globally or force users to pan the entire page. Decide
stacked editing versus comparison scrolling by task; the V2 foundation board does
not provide a mobile grade/roster specification, and the old baseline cannot supply
one by default. Do not introduce dual independent editable copies with diverging state.

**Width/scroll ownership:** `.app-content` owns outer maximum width/gutters. Give
prose a narrower readable measure, allow tables the available bounded width, and
avoid stacking another `max-w-2xl` on the full assignment workspace. Keep bottom-nav
safe-area clearance; no grade/save toolbar may obscure the final field or submit
button. Actual device zoom/reflow, virtual keyboard overlap, scroll/focus and screen
reader announcements remain implementation tests, as the stress board itself states.

## 7. Localization and long-content strategy

Authority: `localization-stress-findings.png`. Its names, labels and content are
synthetic expansion fixtures, not new product content, routes or rules.

| Constraint                 | Current owner and future handling                                                                                                                                                                                                     |
| -------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| FR/EN text                 | Reuse `i18n/index.tsx`, `fr.ts`, `en.ts`, `school.ts`, `ux.ts`; translate UI keys, not institution-authored titles/instructions or names                                                                                              |
| Wrapping                   | Full task/module titles and body copy wrap; flexible grid/flex items use `min-width: 0`; emergency wrapping for long unbroken identifiers/URLs only where needed                                                                      |
| Flexible buttons           | `Btn`, action groups and dialog footers can wrap and increase height. For a given locale/label, hover/pressed/focus/loading/disabled preserve geometry; reserve loading indicator space; never shorten the actual action to force fit |
| Form errors                | Retain `Field` label/description/error association; errors can occupy multiple lines without covering the next control. Do not rely on border color alone                                                                             |
| Navigation                 | Sidebar items and bottom-nav labels can wrap or grow vertically. All secondary destinations remain reachable in the menu. Review long FR/EN labels rather than shrinking fonts or removing navigation                                 |
| Table headers              | Remove universal `white-space: nowrap` where long headers need wrapping; allow multiline labels and useful column minimums. Stacked rows use complete localized labels                                                                |
| Long identities/files      | Do not destructively truncate student names, institutional IDs, filename differences, assessment titles or validation reasons. Show full text/wrapping or an accessible full-value mechanism when a native single-line editor scrolls |
| Dates/numbers              | Keep `formatDate`, `formatDateTime`, `formatNumber`, and `Africa/Casablanca`; distinguish date-only assessment context from zoned deadlines. Preserve decimal comma/dot handling and backend score validation                         |
| Locale changes during work | Preserve selected file, search, dirty grade rows, original optimistic version and open contextual workflow across a locale refresh; existing grade protection is intentional                                                          |
| Language availability      | FR/EN only in this scope. The findings explicitly do not claim current RTL support                                                                                                                                                    |

Current shell identity `truncate`, submission filename `truncate`, admin audit
`truncate max-w-xs`, `Badge` desktop no-wrap, tab no-shrink labels and the table
header rule need content-specific review. Do not remove an identity distinction
needed to safely match/import/grade a person. Compact display cannot be the only
place the user can inspect a full value. Native select/input text-selection and
scrolling, locale-specific dates/numbers and extreme 320px titles still require
real implementation validation; the PNG note does not certify them.

## 8. Conflicts with the current frontend

| Existing implementation evidence                                                                                          | Conflict with primary V2 direction                                                                                  | Migration approach                                                                                                                 |
| ------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------- |
| `index.css :root`: canvas `#faf6ee`, primary/accent `#1e5aa8`, muted `#ede2cf`                                            | More yellow canvas and more saturated blue/beige than sampled V2 neutrals and muted blue                            | Replace through semantic Light/Dark tokens, not scattered literal edits                                                            |
| `--sidebar: #0f2a4a`; `AppShell` white text/white-opacity hover literals                                                  | Permanently dark Light-mode sidebar; new Light shell is quiet neutral                                               | Give shell its own mode-aware background/ink/selected/focus tokens                                                                 |
| No inspected theme provider; numerous `bg-white`, `background: white` in UI, shell, school panels, route error and fields | Changing root background alone cannot implement Dark surfaces                                                       | Inventory and replace hard-coded surface/foreground pairs in a shared-token pass; agree frontend theme policy                      |
| `font-display` uses Poppins and sets `color: var(--sidebar)`; `.school-page h2` also uses sidebar color                   | Typography feels different; changing sidebar to a light surface would make headings unreadable                      | Separate heading ink from sidebar surface. Confirm V2 font family before replacing hierarchy                                       |
| `--radius: 8px`, `--radius-card: 12px`; pills in `Badge`/`Avatar`, ad hoc `rounded-lg/xl`                                 | One radius plus global pills cannot represent subtle controls/chips, square integrated surfaces and priority panels | Separate role-specific radii; keep numeric values provisional until native inspection                                              |
| `UI.tsx Btn`: border-0, secondary filled blue, saturated variants, hard-coded hover, active scale                         | V2 outlined secondary and stable state geometry are not met                                                         | Refactor shared variants; keep handler/disabled/native button behavior                                                             |
| `UI.tsx fieldClasses`, `SchoolScreens inputClass`, admin inline select styles                                             | Duplicate borders/heights/backgrounds and focus treatments                                                          | Consolidate semantic field visuals while retaining specialized grade/status semantics                                              |
| School `panelClass` repeats border/white/padding; group → module → section nesting                                        | V2 has fewer equal-weight boxed sections                                                                            | Distinguish priority panel, flat section and integrated tonal section; remove redundant visual wrappers, not content               |
| `.app-content` 24px padding, mobile 20/16; child-specific padding and widths                                              | Gutter ownership and spacing rhythm differ; full assignment is capped at `max-w-2xl`                                | One outer width/gutter system, narrow prose only, task-specific inner grids                                                        |
| Permanent sidebar at `md`/768; desktop notification action inside main; student duplicate home/school bottom links        | Authored tablet has topbar/no sidebar; desktop has a real topbar; visible V2 bottom destinations differ             | Refactor shell presentation and review primary/secondary navigation regrouping, preserving existing routes                         |
| `.grade-actions` sticky desktop / static mobile; current save controls below table                                        | Exploration places compact draft actions above table and publication below                                          | Reposition visually, retain all guards and avoid a sticky mobile obstruction                                                       |
| `.ui-table th { white-space: nowrap }`; admin `min-w-[640px]`; saturated table header/row literals                        | Long FR/EN headers and new quiet comparison treatment need adaptation                                               | Keep native semantics, labelled mobile rows and bounded scroll where necessary; tokenize header, selection, error and row surfaces |
| Current `Badge` uses Tailwind green/orange/red/blue palettes; `Alert` uses generic bright tints                           | Not the restrained V2 state system or complete Dark variants                                                        | Map semantic status foreground/fill/edge without changing underlying meaning                                                       |
| Icons are mostly filled SVG; wordmark uses colored stacked rectangles                                                     | V2 uses smaller line-like icons and a simpler wordmark treatment                                                    | Review icon style/brand source; do not globally change SVG fills into strokes or alter Microsoft's brand logo                      |
| `SchoolWorkspace` has no live priority-task composition; `SchoolStudentUpdates` is separately boxed                       | Student home still reflects current product presentation, not V2                                                    | Refactor the mounted student branch; preserve multi-group/delegate/empty states and flag missing data                              |

The visual gap is not limited to four screens. Public/auth/profile/legacy practice,
messages/personal grades, admin operations, dialogs and fallback screens still use
the current shared tokens and per-screen legacy patterns. A shared CSS replacement
alone does not guarantee their layout has migrated. The historical Figma baseline
is also not proof that its visuals exactly match today's React implementation.

## 9. Remaining existing screens to migrate

Inventory reconciled against `router.tsx` and `FEATURE_UX_VALIDATION.md`:
**38 route definitions: 35 rendered entries and 3 compatibility redirects**.
The four foundation-rendered entries are `/app`, `/app/school`,
`/app/assignments/:assignmentId`, and `/app/school/assessments/:id`.
Admin Roster is nested in the existing workspace, not an additional route.
Teacher/admin home variants and other workspace controls remain only partially
represented by the Student/roster boards.

The following **31 other rendered entries** can inherit the agreed V2 visual system;
their detailed compositions are not explicitly approved by these exports. Preserve
each route's current guard and verified states. Paths below are relative to `/`.

| Existing route                                            | Current component / file under `frontend/src/`                                                      | Migration scope                                                                                              |
| --------------------------------------------------------- | --------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------ |
| `/`                                                       | `HomeScreen`, `screens/PublicScreens.tsx`                                                           | Public presentation and illustrative preview; not a new authenticated dashboard                              |
| `/login`                                                  | `LoginScreen`, `screens/PublicScreens.tsx`                                                          | Auth fields, Microsoft/OTP choices, pending/errors, return path                                              |
| `/auth/microsoft/callback`                                | `MicrosoftCallbackScreen`, `screens/PublicScreens.tsx`                                              | Progress/recovery states; token/state behavior untouched                                                     |
| `/privacy`                                                | `PrivacyScreen`, `screens/PublicScreens.tsx`                                                        | Readable prose and public shell                                                                              |
| `/pending`                                                | `PendingScreen`, `screens/PublicScreens.tsx`                                                        | Approval guidance; no invented approval action                                                               |
| `/denied`                                                 | `AccessDeniedScreen`, `components/guards.tsx`                                                       | Truthful unavailable-account recovery                                                                        |
| `/app/profile`                                            | `ProfileScreen`, `screens/ProfileScreen.tsx`                                                        | Account, language, notifications/preferences/security tabs                                                   |
| `/app/classes`                                            | `RoleClasses` → `MyClassesScreen` / `TeacherDashboard`, `StudentScreens.tsx` / `TeacherScreens.tsx` | Historical learner/teacher list variants, not official setup                                                 |
| `/app/classes/:id`                                        | `StudentClassScreen`, `screens/StudentScreens.tsx`                                                  | Historical class content tabs and scoped member views                                                        |
| `/app/classes/:classroomId/quizzes/:id`                   | `QuizAttemptScreen`, `screens/StudentScreens.tsx`                                                   | Start/resume, timer, answers, submit/results; preserve offering query context                                |
| `/app/flashcard-decks/:deckId`                            | `FlashcardStudyScreen`, `screens/StudentScreens.tsx`                                                | Study/review and currently authorized manage/edit states                                                     |
| `/app/deadlines`                                          | `DeadlinesScreen`, `screens/StudentScreens.tsx`                                                     | Full chronological work list and existing destinations                                                       |
| `/app/partners`                                           | `PartnersScreen`, `screens/StudentScreens.tsx`                                                      | Opt-in/profile/candidates/request tabs; peer privacy                                                         |
| `/app/join`                                               | `JoinClassScreen`, `screens/StudentScreens.tsx`                                                     | Code request, cooldown/history and authorized-manager guidance                                               |
| `/app/requests`                                           | `TeacherRequestsScreen`, `screens/TeacherScreens.tsx`                                               | Historical membership decisions, not official import                                                         |
| `/app/progression`                                        | `RoleProgress` → `StudentProgressScreen` / `ClassProgressScreen`, corresponding screen files        | Student practice history and teacher class analytics; no certified-average claim                             |
| `/app/classes/:id/manage`                                 | `TeacherClassScreen`, `screens/TeacherClassScreens.tsx`                                             | Historical management tabs, resource/content dialogs, AI/manual fallback                                     |
| `/app/classes/:id/manage/quizzes/new`                     | `QuizEditorScreen`, `screens/TeacherClassScreens.tsx`                                               | Manual settings/question creation                                                                            |
| `/app/classes/:id/manage/quizzes/:quizId/edit`            | `QuizEditorScreen`, `screens/TeacherClassScreens.tsx`                                               | Draft editor; published content still uses results                                                           |
| `/app/classes/:id/manage/quizzes/:quizId/results`         | `QuizResultsScreen`, `screens/TeacherClassScreens.tsx`                                              | Practice analytics/CSV, not official assessment grid                                                         |
| `/app/classes/:id/manage/assignments/:assignmentId/grade` | `GradeScreen`, `screens/TeacherClassScreens.tsx`                                                    | Submission selection, private file download and feedback; distinct from official grades                      |
| `/app/admin`                                              | `AdminDashboard`, `screens/AdminScreens.tsx`                                                        | Operational counts/links, not the roster preview screenshot                                                  |
| `/app/admin/users`                                        | `AdminUsersScreen`, `screens/AdminScreens.tsx`                                                      | Search/filter, approval/suspension/role confirmation, mobile rows                                            |
| `/app/admin/classes`                                      | `AdminClassesScreen`, `screens/AdminScreens.tsx`                                                    | Historical ownership transfer/archive, excluding official groups                                             |
| `/app/admin/ai`                                           | `AdminAiScreen`, `screens/AdminScreens.tsx`                                                         | Provider controls/capacity/status; no visual claim of live availability                                      |
| `/app/admin/audit`                                        | `AdminAuditScreen`, `screens/AdminScreens.tsx`                                                      | Technical event filters, full relevant detail, pagination/recovery                                           |
| `/app/school/offerings/:offeringId`                       | `ModuleSpace`, `screens/SchoolScreens.tsx`                                                          | Resources, assignments, announcements, practice, flashcards, assessments and authorized creation/edit states |
| `/app/school/grades`                                      | `PersonalGradesScreen`, `screens/SchoolScreens.tsx`                                                 | Own published results, export, filters, private clarification                                                |
| `/app/school/messages`                                    | `SchoolMessagesScreen`, `screens/SchoolScreens.tsx`                                                 | Inbox, compose, eligible contacts, support and audience preview/publication                                  |
| `/app/school/messages/:id`                                | `SchoolThreadScreen`, `screens/SchoolScreens.tsx`                                                   | Participant-only thread, read/reply/resolve/report; no attachments or inherited private history              |
| `*`                                                       | `NotFoundScreen`, `router.tsx`                                                                      | Existing unknown-URL recovery                                                                                |

Compatibility redirects remain redirects, not pages to design:

- `/app/dashboard` → `/app`.
- `/app/classes/new` → `/app/school` (teacher guard retained).
- `/app/teacher/classes/:id` → `/app/classes/:id/manage`.

`RouteErrorScreen` is the shared `errorElement`, not a 39th route. Notification and
menu dialogs are shell states, not pages. `StudentDashboard` and `CreateClassScreen`
are deliberately unmounted compatibility exports; do not invent routes for them.
Operator-only local imports/backups/initial-admin commands are not end-user screens.

### Nested existing workflows beyond the four foundation snapshots

Retain and restyle the documented year/group/module setup and archive/restore,
named co-teaching/revocation, coordinator grant, admission/remove/transfer,
delegate appointment/revocation/notice permission, exceptional module access,
teacher assignment/setup requests, resource create/edit/delete, announcement
body/edit/delete, assignment draft/publish/close, quiz/flashcard manual and AI
review/fallback, grade import/reconciliation/correction/history, private/delegate
communication, audience preview/confirmed publication, private support reports,
notification read/all-read/deep links, eighteen preferences, locale/session/logout,
and all failure/recovery/confirmation states. These are existing nested capabilities
from the verified feature matrix, not permission to introduce new pages.

## 10. Open questions and implementation readiness

| Unclear or incomplete reference                                                      | Consequence / review decision needed                                                                                                                          |
| ------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Native variable IDs, exact typography, spacing, radii and state colors not exported  | Confirm in native Figma; sampled colors and proposed measurements are not a final token contract                                                              |
| Dark foundation screens are not authored in the current V2 exploration board         | Derive shared semantics from Student V2, but review assignment/grid/roster Dark states before calling them approved; do not reuse historical baseline layouts |
| No explicit current V2 mobile/tablet assignment, grade-grid or roster compositions   | Use stress constraints and proven mobile behavior as implementation candidates; obtain visual review of these adaptations                                     |
| Desktop sidebar switch at 1024 and intermediate gutter/column thresholds             | Review content-fit behavior at 1024/1280, particularly expanded FR/EN navigation and grade controls                                                           |
| Four visible student destinations versus current wider navigation                    | Review regrouping with full route reachability, including historical, admission, partner and profile access                                                   |
| Theme selection, startup, persistence and public-route scope                         | Agree frontend policy; no backend preference or new route inferred                                                                                            |
| Hero task eligibility/priority and data relationship to module/submission            | Confirm existing source/selection semantics before implementation; no fabricated “to submit” or new urgency calculation                                       |
| Home result/resource summaries and assignment document/context relationships         | Resolve through verified authorized contracts or defer those blocks; fixtures cannot become fallback product data                                             |
| Complete row-level import preview table versus declared valid-row/error payload      | Confirm real row correlation or use truthful separate valid/error views; no guessed identity or backend change in a visual-only pass                          |
| Tabs, menus, overlays, blank/error/denied/archived/correction states not fully drawn | Preserve established behavior and apply shared visual language; review representative states rather than treating one happy-path screenshot as complete       |
| Font/contrast, zoom, device keyboard, actual scroll/focus and announcements          | Must be tested in implementation, as both stress notes acknowledge; Figma review alone is not certification                                                   |
| README manifest differs from supplied board names                                    | Reconcile export documentation in a separate approved documentation update                                                                                    |

**Sufficiency:** the references are sufficient to begin a controlled shared-token,
shell and Student V2 presentation implementation **after this mapping is reviewed**.
They are not sufficient to assert a finalized pixel-perfect full-product redesign,
invent absent data, or consider the three foundation explorations approved in every
mode/viewport/state. Data-dependent portions above need explicit confirmation or a
documented deferment. No implementation is authorized or performed by this document.

## 11. Proposed implementation sequence and acceptance gates

1. Confirm native token/type/state measurements and theme policy; establish semantic
   Light/Dark values and shared control variants before individual screen CSS.
2. Refactor shell presentation, review navigation regrouping, and verify 768px
   tablet versus desktop layout without route/guard changes.
3. Implement the **mounted** student workspace composition, retaining all current
   no-group, multi-group, module-only, delegate, archived and failure states. Resolve
   the priority/result/resource data gaps before adding those optional visual blocks.
4. Apply foundation patterns to assignment, official grade editor and in-workspace
   roster/import. Preserve existing action/state machinery and the identified
   contract limits; review candidate mobile and Dark adaptations.
5. Migrate remaining routed screens through the shared system and content-specific
   layouts; compare route inventory against the verified matrix so no capability
   disappears merely because it lacks an exported mock.
6. Validate real FR/EN long content at 320/360/390/430/768/1024/1280/1440/1920 in
   Light/Dark; retain the existing 375px regression width as additional coverage.
   Check no document overflow, full labels/titles/identities, bounded comparison
   regions, safe-area clearance, keyboard focus, state geometry and zoom/reflow.
7. Re-run appropriate frontend tests/typecheck/build and the existing synthetic
   browser/authorization journeys when implementation happens. Specifically retain
   dirty-grade locale/conflict tests, 0-vs-blank scoring, blocked publication/import,
   single submission/private downloads, role/delegate revocation and recovery.
   Do not rerun these against real roster data or production services to validate styling.

**Mapping review readiness: YES. Full-product visual sign-off: not claimed.**
