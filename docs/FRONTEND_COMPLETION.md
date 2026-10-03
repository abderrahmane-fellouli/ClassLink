# Frontend Completion Evidence

Date: 2026-10-03. Scope: frontend application and tests only, plus this new evidence document. No backend, environment examples, deployment configuration, commits, or deployment actions were made by this frontend implementation.

## Sources Reviewed

- Read all 45 pages of `cahier_des_charges/ClassLink_Cahier_des_Charges_Professionnel.pdf`, including functional requirements, acceptance criteria, journeys, accessibility, visual identity, REST routes, and test plan.
- Read all 662 lines of `docs/IMPLEMENTATION_AUDIT.md`, specifically Phase 2, frontend portions of Phase 3, and render-test gaps.
- Reviewed existing frontend components, routing/guards, translations, API helpers, response types, asynchronous hooks, and existing test suites before implementation.
- Inspected backend routes, controllers, requests, resources, services, and relevant feature tests read-only. Re-read contracts as the backend agent introduced deadlines, preferences, distributions, trends, roster reports, and saved answers.
- Preserved pre-existing dirty changes in authentication/session handling, API helpers/tests, notification translations/tests, and all other agents' files. `frontend/vercel.json` and environment examples belong to infrastructure and were not edited here.

## Implemented

| Audit Task | Frontend Evidence |
| --- | --- |
| 2.1 Confirmations | Shared portal-based `Dialog` and `ConfirmButton`; confirmations for logout, session revocation, accept-all, member removal, content/quiz/deck/assignment deletion, roster import, admin class transfer/archive, pending-role approval, and manual quiz submission. Existing teacher archive confirmation retained. |
| 2.2 Copy invite code | Clipboard buttons with localized copied feedback and failure state in class settings and class-creation success screen. |
| 2.3 Accessibility | Shared field label/control association, descriptions and invalid state; raw class/join/option/skill/file fields named; modal accessible name, `aria-modal`, initial focus, Tab/Shift+Tab containment, Escape, focus restoration, and body scroll locking. Notification and mobile-navigation overlays use the same dialog. |
| 2.4 Touch targets | CSS minimum 44px controls and switches; mobile links have minimum 44px height. Real viewport/browser measurements remain a release-validation step, not a jsdom claim. |
| 2.5 Student navigation | Fixed mobile bottom navigation with safe-area padding and content clearance; desktop/sidebar architecture retained. |
| 2.6 Edit/lifecycle journeys | Class, announcement/pinning, material title/chapter, assignment title/instructions/deadline edit dialogs; existing quiz editor extended to load and save drafts through settings PATCH and question add/update/delete/reorder routes. Manual deck creation, opening/review validation, publication and deletion are reachable. CSV import reports localized row errors. Admin transfers and pending-user queue are reachable. Same-tab quiz resume is implemented; server-side cross-device resume still needs the contract described below. |
| 2.7 Localization | Denied screen, loaders, shared Back/Retry, question types, flashcards/AI labels, file units, confirmation/import/preferences/trend labels, and notification payload statuses translated in FR/EN. `accepted`, `rejected`, `done`, `succeeded`, `failed`, and in-progress values are not shown as raw notification status codes. |
| 2.8 OTP | Ten-minute wall-clock countdown, expiry state, verify disabled on expiry, resend disabled during request, code cleared on resend. Join cooldown context and AI quota errors displayed. |
| 3.4 Distribution | Renders actual backend `distribution: [{ min, max, count }]`, with textual bucket counts and bars. |
| 3.5 Progression | Uses actual `trend.delta_percentage_points`; latest results ordered newest-first; teacher inactive-student panel uses returned display names rather than IDs. |
| 3.6 Deadlines | Quiz editor reads/writes actual nullable `due_at`; `/me/deadlines` renders/sorts actual assignment and quiz deadlines. No fabricated publication-date deadline. |
| 3.7 CSV reports | Multipart upload to existing members/import route; `imported`, `accepted`, and `errors` mirrored; row reasons `invalid_email`, `duplicate_email`, `active_student_not_found`, and `column_count` translated. |
| 3.9 Preferences | GET/PUT `/me/notification-preferences`; daily digest and all eleven backend notification types editable, server response updates UI, errors/retry and pending locks present. |
| 3.10 Visual identity | Spec Navy/Ocean/Sky/Ice/Cream/Sand/Camel/Red/Green/Amber/Ink tokens, Poppins/Inter/JetBrains Mono, 32/24/20/16px type scale, two text weights, 12px cards/8px controls, linked-bubble logo. Darker action colors preserve legibility. Established SVG glyphs render as one outline set; brand marks retain their fills. Large legacy shadows removed. |

## Additional Contract Fixes

- Member deletion now passes `member.user.id`, not `membership_id`, to `/classes/{classroom}/members/{studentId}`.
- Quiz submission and answer saving now send `answers[].option_ids`, matching `SubmitAttemptRequest`; `selected_option_ids` remains a result-response field only. The audit's blanket claim of exact existing frontend/API contracts did not catch this defect.
- Grading now reads the actual router's `:id` class parameter instead of an absent `:classroomId`, preventing `/classes/NaN/assignments` calls.
- Teacher class navigation is allowed to render the teacher class listing; admin class navigation points to `/app/admin/classes` rather than a student-only route.
- Sidebar content no longer remounts when notification loading completes, preserving open confirmation state.
- Quiz timer uses a wall-clock deadline and current answer reference; successful submission clears the timer/active attempt. Save answers and Next use the newly available PATCH `/attempts/{id}/answers` contract before advancing. All mutations using `useAction` have an immediate re-entrancy guard.
- Quiz questions preserve server IDs and retry state; a publication failure after creating a draft does not create another draft on retry.
- Local resume data is user/quiz scoped in sessionStorage and removed on successful submission, logout, or session invalidation. History links explicitly fetch the submitted attempt rather than inadvertently starting a new attempt.

## Verification

Commands were executed from `frontend/`:

- `npm test`: **71 tests passed across 6 files**, including **27 new render/interaction tests**. Final run started at 03:58:15 and completed in 6.49 seconds.
- `npm run typecheck`: **passed**, `tsc -b --noEmit`, no diagnostics.
- `npm run build`: **passed**, `tsc -b && vite build`, 43 modules transformed. Main app bundle 241.43 kB / 54.54 kB gzip; CSS 35.93 kB / 7.50 kB gzip. Build creates local ignored `dist/` output only; it is not a deployment.
- `git diff --check`: no whitespace errors; Git reported existing LF/CRLF normalization warnings.

New `frontend/tests/frontend-flows.test.tsx` exercises rendered components and interactions rather than source-string assertions. Coverage includes accessible fields/dialog keyboard behavior, student bottom navigation/logout confirmation, join requests, accepted-class filtering, quiz submission and exact option payloads, same-tab resume, saved answers/Next, assignment multipart upload, flashcard flip/review, deadlines/trends, partner responses/email privacy, teacher accept-all/removal IDs, announcement editing, draft editor controls, score distribution, CSV row reports, grading through the real route parameter, inactive names, deck creation/AI review gate, admin transfer/pending roles, notification status rendering/preferences, and OTP countdown/resend lock.

Existing authentication/session/API/i18n tests remain in place. Notification tests no longer assert that the raw string `accepted` appears in French.

### Files Changed By This Implementation

- `frontend/src/components/UI.tsx`: field accessibility, translated primitives, shared Dialog/ConfirmButton, logo and action styling.
- `frontend/src/components/EditButton.tsx` (new): small reusable edit dialog using existing field/action primitives.
- `frontend/src/components/AppShell.tsx`: dialogs, mobile bottom navigation, desktop notification bell, persistent locale action, localized notification statuses, sidebar stability and admin navigation.
- `frontend/src/components/guards.tsx`: localized loader/denied screen and logout confirmation.
- `frontend/src/index.css`: specification design tokens/fonts/type scale/outline glyph styling, touch-target minimums and mobile wrapping.
- `frontend/src/i18n/fr.ts`, `frontend/src/i18n/en.ts`: matching translations for the implemented journeys.
- `frontend/src/lib/endpoints.ts`, `frontend/src/lib/types.ts`: verified new helpers/response fields and corrected answer payload keys.
- `frontend/src/lib/session.ts`, `frontend/src/lib/useAsync.ts`: resume-data cleanup, localized fallback behavior and mutation re-entrancy guard.
- `frontend/src/router.tsx`: draft-edit route and role-adapted class listing.
- `frontend/src/screens/TeacherClassScreens.tsx`: edit/import/deck/quiz/settings/results journeys and grading route fix.
- `frontend/src/screens/TeacherScreens.tsx`: accept-all confirmation, accessible creation fields, copy feedback, active class filtering and inactive student names.
- `frontend/src/screens/StudentScreens.tsx`: active accepted-class filtering, progression/history, quiz resume/save/submit, named fields, deadline sorting and deck-review lifecycle.
- `frontend/src/screens/AdminScreens.tsx`: pending queue, transfer/archive confirmation, correction of locked roles, provider quota/priority editing and audit filters.
- `frontend/src/screens/ProfileScreen.tsx`: notification preferences/retry states and session-revocation confirmation.
- `frontend/src/screens/PublicScreens.tsx`: language selection, OTP countdown/resend lock and pending-account logout confirmation.
- `frontend/tests/frontend-flows.test.tsx` (new), `frontend/tests/notifications.test.ts`: render/interaction evidence and localized-status regression assertion.
- `docs/FRONTEND_COMPLETION.md` (new): this document, the only documentation edited by this implementation.

Pre-existing changes in `frontend/src/context/AuthContext.tsx`, `frontend/src/lib/api.ts`, `frontend/tests/api.test.ts`, and infrastructure-owned files are not attributed to this implementation.

## Remaining Integration And Validation

1. **Cross-device or storage-free attempt resume:** no existing route exposes active attempt identity plus server-frozen question order and remaining time as a resumable payload. The current UI safely reuses the original server-issued question snapshot in the same tab and checks GET `/attempts/{id}` for server finalization. Backend should expose an active attempt lookup/resume payload, without corrections before submission, before this can work across devices or when sessionStorage is unavailable. No speculative route was added.
2. **Answer durability:** PATCH `/attempts/{id}/answers` is integrated on Save answers and Next. Answers not yet saved/submitted are local only; closing the tab before saving cannot make them available to scheduled server finalization. Automatic durable per-answer synchronization would require a separately verified ordered-write/retry behavior.
3. **Flashcard content editing:** existing backend contracts support create/show/review/publish/delete, but no update-card/deck route. The UI supports the available lifecycle and mandatory opening/review validation; no invented flashcard edit API was introduced.
4. **Release verification:** tests use jsdom and mocked HTTP responses, not a live Laravel server or physical browser viewport. Confirm 360px layouts, actual target geometry, full-page contrast, browser Back/Forward after logout, and at least two browser engines before release. Google Fonts availability should also be verified under production network/CSP settings.
5. **Visual acceptance:** CSS normalizes the existing inline SVG set to outlines rather than adding another icon package. Confirm the resulting glyph contours and expanded 16px labels against the client's visual acceptance on desktop and mobile; no live Figma/browser comparison is claimed.

Backend and infrastructure changes are independently owned. Their test/deployment evidence must be recorded by their respective agents; this document does not claim their verification results.
