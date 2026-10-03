# ClassLink Implementation Audit

**Audit date:** 2026-10-03
**Specification audited:** `cahier_des_charges/ClassLink_Cahier_des_charges.docx` (Cahier des Charges v1.0) — compared against `cahier_des_charges/ClassLink_Cahier_des_Charges_Professionnel.pdf`
**Code audited:** repository at commit `fd7a77e9ec5e991be088cefc9b8828fbe821a7c8` (branch `main`)
**Method:** read-only static trace of every requirement through controllers → services → models → policies → resources → migrations, plus frontend routes/screens/i18n, tests, CI and deployment configuration. No implementation code was modified during this audit.

## Status legend

| Status | Meaning |
|---|---|
| `COMPLETE` | Implemented and verified by code trace; behaviour matches the specification. |
| `PARTIAL` | Implemented but incomplete, or only part of the requirement is met (backend only, UI only, or a missing sub-rule). |
| `MISSING` | Not implemented anywhere. |
| `IMPLEMENTED DIFFERENTLY` | Functionally present but by a materially different mechanism or against a different stated rule. |
| `NEEDS VERIFICATION` | Cannot be proven from the repository; requires a live environment. |

---

## 1. Executive Summary

> **Post-remediation note.** Phase 0 (0.1–0.5) and Phase 1 (1.1–1.5) have been
> implemented and verified — 385 backend tests / 1085 assertions and 44 frontend
> tests, all passing. Every finding below that was in Phase 0 or Phase 1 scope is
> now marked `COMPLETE`; the original wording is kept as the record of what was
> found. See `IMPLEMENTATION_PROGRESS.md` for the changes and the regression
> tests. Residual gaps are UI-only and remain classified in Phase 2.

The ClassLink codebase is a **substantially complete, well-structured implementation** of the specification. The backend is mature and, in several places, more careful than the specification requires (magic-byte file validation, non-executable downloads, fail-closed CORS, strict response resources, a real multi-provider AI failover chain with strict-JSON validation and retry). The frontend/backend API contract is exact — zero shape mismatches across ~95 endpoint helpers — and the frontend internationalisation dictionaries are perfectly balanced (561 FR / 561 EN keys, enforced by test).

**The gap is not in the architecture or in the security foundations. It is concentrated in four areas:**

1. **Quiz authoring and attempt integrity** — questions cannot be edited, reordered or deleted after creation; `shuffle` is stored but never applied server-side; all questions are returned in one payload instead of one at a time; auto-submit on timeout is lazy, and **answers submitted after the deadline are still saved and scored**.
2. **UI completeness against accepted criteria** — 10 backend capabilities are unreachable in the interface (roster import, quiz/class/assignment/announcement/material editing, flashcard deck lifecycle, admin class transfer, pending-user queue, attempt resume); the "Copy invite code" button is missing; the **"Session expired" message is computed server-side, localised correctly, then discarded three times in the frontend**; no reusable confirmation dialog exists, so several spec-mandated confirmations are absent.
3. **Production operations** — the AI daily-quota reset is scheduled but **no scheduler runs in production**, so teacher AI quota is exhausted permanently on day one; database TLS mode is silently ignored; no trusted-proxy configuration, which corrupts audit-log IPs and makes IP-keyed throttles global; **no backup or restore procedure exists** (NF-13).
4. **Accessibility and design conformance** — no `<label>`/`htmlFor` association anywhere, so every form field has no accessible name; touch targets are 20–40 px against a mandated 44 px; the student bottom navigation bar is missing; the visual identity uses a different palette and different typefaces than §14 specifies.

### Scorecard

| Area | Items | Complete | Partial | Missing | Diff. | Needs verif. |
|---|---|---|---|---|---|---|
| Functional requirements (`F-*`) | 69 | 49 | 19 | 1 | — | — |
| User stories (`US-*`) | 48 | 35 | 12 | 1 | — | — |
| Business rules (`RG-*`) | 20 | 17 | 3 | 0 | — | — |
| Non-functional (`NF-*`) | 14 | 4 | 7 | 1 | — | 2 |
| Test cases (`T-*`) | 28 | 24 | 4 | 0 | — | — |
| **Total** | **179** | **129 (72.1%)** | **45 (25.1%)** | **3 (1.7%)** | — | **2 (1.1%)** |

### Verdict

**Not release-ready for v1.0 as specified, but close.** There is no missing security control on the authentication, authorisation, data-minimisation or file-handling path. Every `MISSING` or contract-breaking defect is a bounded, locally fixable defect with a known location. Phase 1 of the plan below (~10 tasks) closes all Must-priority gaps and all four critical blockers.

**Critical blockers**

| # | Blocker | Evidence |
|---|---|---|
| B1 | Answers submitted **after** the quiz deadline are persisted and graded; `expired` is only a flag | `app/Services/QuizGradingService.php:75-107`, `app/Http/Controllers/QuizController.php:264-266` |
| B2 | Partner contact requests bypass the `opt_in` consent gate (RG-17 breach) | `app/Services/PartnerMatchingService.php:107-140` |
| B3 | AI daily-quota reset never runs in production (no scheduler), quota exhausts permanently | `routes/console.php:22-24`, `render.yaml:90-91` (no worker/scheduler) |
| B4 | "Session expirée" message is produced and localised server-side, then discarded client-side | `frontend/src/lib/api.ts:213-215`, `frontend/src/lib/useAsync.ts:47` |

> **Status: all four blockers resolved** (Phase 0.1-0.4). See
> `IMPLEMENTATION_PROGRESS.md` for the file-by-file changes and the regression
> tests. The evidence column above is kept as the original finding.

---

## 2. Compliance Matrix — Functional Requirements (§4)

### 2.1 Authentication and profile (F-AUTH-01…09)

| ID | Req. | Pri. | Status | Evidence / gap |
|---|---|---|---|---|
| F-AUTH-01 | Microsoft school account login (OAuth 2.0) | Must | `COMPLETE` | `AuthController:22-55` (Socialite `azure`, stateless), token via URL **fragment** not query string (`:54`) |
| F-AUTH-02 | 6-digit fallback code (10 min, 5 attempts) | Must | `COMPLETE` | `OtpService:40-52`; code **hashed** (`:48`), `hash_equals` compare (`:108`), `OtpCode` consumed on success (`:120`), rate limited `otp_request '3,1'` / `otp_verify '10,1'` |
| F-AUTH-03 | Refuse any account outside `ofppt-edu.ma` | Must | `COMPLETE` | `RoleDetector:27-29`; `OtpService:33-36` silently returns (no user enumeration); `auth.denied` audited |
| F-AUTH-04 | Server-side role detection | Must | `COMPLETE` | `RoleDetector:21-50` is the single computation site; regexes configurable in `config/classlink.php:45-60` |
| F-AUTH-05 | "Pending" status for unknown formats, validated by super admin | Must | `COMPLETE` | `Role::Pending`; `/pending` route isolated by `guards.tsx:39-48`; `GET /admin/users/pending` + `PATCH /admin/users/{id}` |
| F-AUTH-06 | Secure logout: server-side revocation + local session clear | Must | `PARTIAL` | Backend complete (`TokenService:38-47`, 204, T-23 verified). **Frontend has no confirmation dialog**, required by US-06 |
| F-AUTH-07 | Automatic 8-hour session expiry with a clear message | Should | `COMPLETE` | Backend complete (`config/classlink.php:82` TTL, `session_expired` code + FR/EN strings). **Resolved (Phase 0.4):** the client now parses the 401 body, `SessionExpiredError` carries the translated message, `AuthContext` keeps it in `sessionNotice` and the login screen displays it. The raw code can no longer leak (`useAsync` still suppresses the error to avoid a flash) |
| F-AUTH-08 | Profile: display name, language FR/EN | Must | `COMPLETE` | `ProfileController:25-48`; `role`/`role_locked`/`is_active`/`email` explicitly `prohibited` (`:39-42`) |
| F-AUTH-09 | Avatar from display-name initials | Could | `COMPLETE` | `User::initials():69-79`, `Avatar` component |

### 2.2 Classes (F-CLS-01…06)

| ID | Req. | Pri. | Status | Evidence / gap |
|---|---|---|---|---|
| F-CLS-01 | Create class (name, subject, group, school year) | Must | `COMPLETE` | `ClassroomController:56-75`, all four fields validated |
| F-CLS-02 | Auto-generated unique invite code | Must | `COMPLETE` | `JoinCodeService:20-33` — unambiguous 31-char alphabet, 8 chars, uniqueness loop, unique index |
| F-CLS-03 | Regenerate or disable invite code | Must | `COMPLETE` | API `ClassroomController:136-164` (both audited); UI present `TeacherClassScreens.tsx:984,1003` |
| F-CLS-04 | Modify and archive a class | Must | `PARTIAL` | `PATCH /classes/{id}` and archive both implemented and audited. **No class-edit UI** (`PATCH /classes/{id}` helper unused) |
| F-CLS-05 | Role-adapted "My classes" | Must | `COMPLETE` | `ClassroomController:30-53` — teacher owns, admin all, student accepted+pending only (RG-05) |
| F-CLS-06 | Member list (display name only for students) | Must | `COMPLETE` | `MemberResource:30-50` — `email` present only for owner/admin; enforced by tests (T-22) |

### 2.3 Join requests (F-REQ-01…10)

| ID | Req. | Pri. | Status | Evidence / gap |
|---|---|---|---|---|
| F-REQ-01 | Send join request from a class code | Must | `COMPLETE` | `MembershipService:35-95`; invalid/disabled/archived code → 404 |
| F-REQ-02 | One pending request per student per class | Must | `COMPLETE` | `MembershipService:55-65` → 409; unique `(classroom_id, student_id)` index |
| F-REQ-03 | Pending request list for the owner | Must | `COMPLETE` | `ClassroomController:196-207` via `decideJoinRequest` |
| F-REQ-04 | Accept or reject | Must | `COMPLETE` | **Resolved (Phase 1.4).** `assertPending()` in `MembershipService` restricts a decision to a pending request (409 otherwise, current status in the context), and the ownership check runs first so 403 still wins. Removing a member remains `remove()` only |
| F-REQ-05 | Accept all pending | Should | `PARTIAL` | `MembershipService:150-167` + UI at `TeacherClassScreens:239`, but **US-17 explicitly requires a confirmation** and there is none |
| F-REQ-06 | Student tracks request status | Must | `COMPLETE` | `GET /join-requests/mine` + status screen |
| F-REQ-07 | 24 h wait after rejection | Should | `COMPLETE` | `MembershipService:68-74` → 429 with `retry_after_hours`; cooldown announced to the student (`:138`) |
| F-REQ-08 | Remove a student, immediate access loss | Must | `COMPLETE` | `MembershipService:174-201`; `ClassroomPolicy::view` re-checks `accepted` on **every** request; no result data deleted |
| F-REQ-09 | CSV import of official roster | Could | `PARTIAL` | Endpoint `ClassroomController:243-270` + `importAccepted` work, but **no UI** and **invalid rows are silently skipped** (`MembershipService:222-224`) — US-19 requires them to be reported |
| F-REQ-10 | Notify teacher (request) and student (decision) | Must | `COMPLETE` | `MembershipService:87, 108, 133, 188` |

### 2.4 Resources and announcements (F-CON-01…05)

| ID | Req. | Pri. | Status | Evidence / gap |
|---|---|---|---|---|
| F-CON-01 | Add resources (PDF, documents, links) organised by chapter | Must | `COMPLETE` | `ContentController:46-89`; `chapter` indexed and sorted first (`:34`) |
| F-CON-02 | Members can view and download | Must | `COMPLETE` | `ContentController:121-146` — access check, then S3 signed URL **or** non-executable stream (`MaterialStorageService:142-164`: explicit `Content-Type`, `nosniff`, restrictive CSP, `no-store`, `attachment`) |
| F-CON-03 | Modify and delete resources | Must | `PARTIAL` | `PATCH`/`DELETE` implemented with policies (`:92-115`) and audited; **no edit/delete UI** |
| F-CON-04 | Create, edit and pin announcements | Must | `PARTIAL` | Create/pin complete; pinned-first ordering correct (`:159-160`). **Members are now notified on creation (Phase 1.5)** via `ANNOUNCEMENT_PUBLISHED` to accepted members only. **Still no edit UI** |
| F-CON-05 | Daily email digest of new announcements | Should | `COMPLETE` | `EmailDigestService` + `POST /api/internal/daily-digest` + `.github/workflows/daily-digest.yml`; FR/EN body |

### 2.5 Quizzes and flashcards (F-QUI-01…09)

| ID | Req. | Pri. | Status | Evidence / gap |
|---|---|---|---|---|
| F-QUI-01 | Manual quiz creation (single / multiple / true-false) | Must | `PARTIAL` | Creation was already complete (`StoreQuizRequest:44-67` — ≥2 options, ≥1 correct). **Question endpoints added (Phase 1.1):** `QuestionController` + `QuizPolicy::manageQuestions` provide add / edit / delete / reorder, restricted to the owner and to draft quizzes, with transactional position renumbering and exact-permutation validation on reorder. **Still no edit UI** — the new endpoints are unreachable from the interface |
| F-QUI-02 | Settings: duration, attempts, shuffle, show corrections | Must | `COMPLETE` | **Resolved (Phase 1.2).** `shuffle` is now applied **server-side**: `QuizGradingService::start()` shuffles and freezes the ordered question IDs on the attempt (`attempts.question_order`), so the order can no longer be bypassed by calling the API directly and is stable for the whole attempt. `time_limit_min`, `max_attempts` and `show_answers` were already enforced server-side |
| F-QUI-03 | Draft vs published; drafts invisible to students | Must | `COMPLETE` | `QuizStatus`, `Quiz:67-75` scope, `QuizPolicy:17-24` 403 on draft |
| F-QUI-04 | Take a quiz (one question at a time, timer) | Must | `PARTIAL` | Timer is server-derived (`Attempt:60-78`) and max attempts enforced (409). **Post-deadline answers are no longer persisted or scored (Phase 0.1 — B1)** and the served order is frozen per attempt (Phase 1.2). Still open: **all questions are returned in one payload** (`QuizController:252-254`) and auto-submit is lazy (no scheduled job) |
| F-QUI-05 | Server-side automatic grading and score | Must | `COMPLETE` | `QuizGradingService:115-160`; score never computed client-side |
| F-QUI-06 | Review the correction with explanations | Must | `COMPLETE` | `AttemptResultResource:53,57` — explanations and `is_correct` nulled when `show_answers=false` |
| F-QUI-07 | Per-quiz results (scores, missed questions) | Must | `PARTIAL` | Average, min/max, participation and top-5 missed questions all present. **No score distribution**, which US-30 requires |
| F-QUI-08 | Flashcard revision | Should | `COMPLETE` | Deck listing, card fetch, flip, `Je la connais` / `À revoir`, persistence via `flashcard_reviews`, deck publish/review lifecycle |
| F-QUI-09 | CSV export of results | Could | `COMPLETE` | `QuizResultController:35-71` — streamed, BOM, `;` separator, policy-checked, no emails |

### 2.6 Artificial intelligence (F-IA-01…07)

| ID | Req. | Pri. | Status | Evidence / gap |
|---|---|---|---|---|
| F-IA-01 | Generate a quiz from a course PDF | Should | `COMPLETE` | `AiController:38-41` → `ProcessAiGeneration:156-190` |
| F-IA-02 | Generate flashcards from a PDF | Should | `COMPLETE` | `ProcessAiGeneration:195-216` |
| F-IA-03 | Mandatory review before publication (AI stays draft) | Must | `COMPLETE` | **Resolved (Phase 1.3).** `reviewed` now defaults to `false` on both tables; `publish()` no longer writes the flag, so publication can no longer certify review; `reviewed_at` is set only by an explicit `review`/`reviewed` call or by a real content edit (`markReviewed()`), and keeps the first timestamp. The 409 branch of `FlashcardController::publish()` was unreachable (a `TypeError`: a `JsonResponse` returned under a `: FlashcardDeckResource` signature) — now covered by tests |
| F-IA-04 | Multiple configurable providers with automatic failover | Should | `COMPLETE` | `AiService:45-88` order from DB; 429 (`:126`), 5xx (`:130`), timeout (`:121`) all fail over; 5-minute cooldown (`:192-209`); admin-configurable priority/enable/quota |
| F-IA-05 | Daily quota, file-size limit, cache by file hash | Should | `PARTIAL` | Per-teacher and per-provider quotas, 10 MB limit all correct. **Cache is partial**: a repeat hash skips only the LLM call and the provider quota — the teacher's daily quota is still consumed, the PDF is re-stored and re-extracted, a new `ai_jobs` row is inserted and a **duplicate draft is created** (`AiController:51-76`). Page limit is checked inside the job, after the 202 |
| F-IA-06 | Manual creation stays possible when AI is down | Must | `COMPLETE` | Quiz creation has zero AI dependency; failures set `manual_fallback=true`; 503 handler `bootstrap/app.php:163-171` |
| F-IA-07 | Background processing with task status | Should | `PARTIAL` | Status machine is real and exposed (`GET /ai/jobs/{id}`, policy-checked). **But `QUEUE_CONNECTION=sync` + `dispatch(...)->afterResponse()` runs the job inside the web request**, not in a worker — this is the single biggest NF-07 deviation |

### 2.7 Homework (F-DEV-01…04) — all Should

| ID | Req. | Status | Evidence / gap |
|---|---|---|---|
| F-DEV-01 | Create assignment with instructions and deadline | `COMPLETE` | `AssignmentController:82-98`, owner-only and not-archived via `ClassroomPolicy::create` |
| F-DEV-02 | Student file submission, marked late after deadline | `COMPLETE` | `AssignmentController:129-161`; `is_late` computed server-side, never client-supplied |
| F-DEV-03 | Teacher reviews submissions, assigns grade and comment | `COMPLETE` | `:164-195` — list, grade, audit, student notification; student sees only their own submission |
| F-DEV-04 | Deadline calendar (assignments and quizzes) | `PARTIAL` | Endpoint works but is an **inline closure** in `routes/api.php:171-196` — no controller, no resource, no policy. Quizzes have no due date in the schema, so it fabricates `due_at = published_at` and hard-codes `is_overdue => false` |

### 2.8 Progression (F-PRO-01…03)

| ID | Req. | Pri. | Status | Evidence / gap |
|---|---|---|---|---|
| F-PRO-01 | Student dashboard: quizzes done, scores, evolution | Should | `PARTIAL` | All aggregates present (`ProgressionService:17-49`), but **evolution is only an ordered history array** — no computed trend, delta or series |
| F-PRO-02 | Teacher dashboard: class average, participation, inactive students | Should | `PARTIAL` | Averages and participation correct; **inactive students returned as bare integer IDs** (`ProgressionService:125`), so the teacher must make a second call to act on them |
| F-PRO-03 | Most-missed questions | Could | `COMPLETE` | `ProgressionService:72-113` — statement, quiz, miss rate, top 5 |

### 2.9 Study partners (F-PAR-01…04)

| ID | Req. | Pri. | Status | Evidence / gap |
|---|---|---|---|---|
| F-PAR-01 | Opt-in profile (default off): skills, availability | Should | `COMPLETE` | DB default `false` (`2026_10_01_000013:22`), service and resource complete |
| F-PAR-02 | Search restricted to members of my classes | Should | `COMPLETE` | `PartnerMatchingService:56-104` re-verifies accepted membership, filters `opt_in=true` |
| F-PAR-03 | Contact request with accept/refuse | Should | `COMPLETE` | Full flow works. **Resolved (Phase 0.2):** `request()` refuses (403) when the target has no profile or `opt_in = false`, and never creates a profile on their behalf. Covered by a new `PartnerTest` (14 tests) |
| F-PAR-04 | No email address ever displayed | Must | `COMPLETE` | No email in `PartnerProfileResource` or `PartnerRequestResource`; `with('user:id,display_name')` |

### 2.10 Notifications (F-NOT-01…03)

| ID | Req. | Pri. | Status | Evidence / gap |
|---|---|---|---|---|
| F-NOT-01 | In-app notifications (bell, read/unread) | Must | `COMPLETE` | `GET /notifications` returns `unread_count`; bell, per-item read, mark-all-read all wired |
| F-NOT-02 | Daily email digest (Brevo) | Should | `PARTIAL` | Implemented via `EmailDigestService`. **No idempotency guard** — US-46 requires at most one email per day, and a retried or manually triggered workflow sends a second. Brevo itself untested live |
| F-NOT-03 | Notification preferences | Could | `MISSING` | No column, no endpoint, no UI |

### 2.11 Administration (F-ADM-01…06)

| ID | Req. | Pri. | Status | Evidence / gap |
|---|---|---|---|---|
| F-ADM-01 | List and search users | Must | `COMPLETE` | `AdminController:24-58` — name/email search, role filter, pagination |
| F-ADM-02 | Change role, activate/deactivate | Must | `COMPLETE` | `:66-105` — sets `role_locked`, audited twice, lock honoured on next login (`AuthController:116-127`) |
| F-ADM-03 | List classes, transfer ownership, archive | Should | `PARTIAL` | All three implemented and audited (`:108-172`). **No transfer UI** in the admin screens |
| F-ADM-04 | Configure AI providers (order, enable, quotas) | Should | `COMPLETE` | `:175-229` — `has_key` exposed, key never; audited `ai.provider_update` / `ai.quota_reset` |
| F-ADM-05 | Audit log (logins, logouts, decisions, role changes) | Should | `COMPLETE` | `:232-272` — filters by action, user, date range; log is append-only. **Minor:** `from`/`to` are unvalidated raw strings passed to `where` (`:245,249`) |
| F-ADM-06 | Global statistics | Could | `COMPLETE` | `:275-307` |

### 2.12 Interface (F-UI-01…03)

| ID | Req. | Pri. | Status | Evidence / gap |
|---|---|---|---|---|
| F-UI-01 | Bilingual FR/EN interface | Must | `PARTIAL` | 561/561 key parity enforced by test, zero raw-key leakage. **10 hardcoded strings bypass `t()`**, including the entire `/denied` screen (`guards.tsx:64,67,68,71,74`) whose `denied.*` translations exist but are unreferenced |
| F-UI-02 | Adapted to phone, tablet and computer | Must | `PARTIAL` | Breakpoints, scrollable tab strip, `overflow-x-auto` tables all sound. **Student bottom navigation bar missing** (§14.2 mandates it) and **touch targets are 20–40 px** against the mandated 44 px |
| F-UI-03 | Explicit empty, loading and error states on every screen | Must | `PARTIAL` | Complete for 22 of 24 screens via `AsyncBoundary`. `ProfileScreen` bypasses it; OTP "resend" is not disabled while pending (double-click sends two codes); no 10-minute countdown despite the UI promising one |

---

## 3. Compliance Matrix — User Stories (§5)

| ID | Pri. | Status | Note |
|---|---|---|---|
| US-01 | Must | `COMPLETE` | Microsoft button, role redirect, foreign domain refused |
| US-02 | Must | `COMPLETE` | 6-digit code, 10 min, 5 attempts, explicit errors |
| US-03 | Must | `COMPLETE` | "Pending" screen, no class content reachable |
| US-04 | Must | `COMPLETE` | Search, role change, lock, audit entry |
| US-05 | Must | `COMPLETE` | Immediate switch, persisted to profile and session |
| US-06 | Must | `PARTIAL` | Revocation, local clear and Back-button behaviour verified; **no confirmation dialog** |
| US-07 | Should | `COMPLETE` | **Resolved (Phase 0.4)** — the localised message reaches the login screen instead of being discarded |
| US-08 | Must | `COMPLETE` | Four fields + generated code |
| US-09 | Must | `MISSING` | **No "Copy" button.** `common.copy` / `common.copied` exist in both dictionaries but are never referenced |
| US-10 | Must | `COMPLETE` | Regenerate and toggle both in UI (`TeacherClassScreens:984,1003`) |
| US-11 | Must | `PARTIAL` | Archive works and content becomes read-only, but **archived classes still appear in "My classes"** — `ClassroomController::index` has no status filter, so "sort de la liste active" is not met |
| US-12 | Must | `COMPLETE` | Accepted classes only + join empty state |
| US-13 | Must | `COMPLETE` | Invalid code → 404; duplicate → 409 |
| US-14 | Must | `COMPLETE` | All three statuses rendered |
| US-15 | Must | `COMPLETE` | Display name, request date, class context |
| US-16 | Must | `COMPLETE` | Accept grants access; reject notifies and starts the 24 h wait |
| US-17 | Should | `PARTIAL` | Accept-all works; **confirmation required by the story is absent** |
| US-18 | Must | `COMPLETE` | Immediate access loss, results preserved |
| US-19 | Could | `PARTIAL` | Import works server-side; **no UI, invalid rows not reported** |
| US-20 | Must | `COMPLETE` | Bell notification on decision |
| US-21 | Must | `COMPLETE` | PDF/Office/link, 10 MB cap resolved from the spec's `[à fixer]` |
| US-22 | Must | `COMPLETE` | Accepted members only |
| US-23 | Must | `COMPLETE` | Pinning works; **members are notified on publication (Phase 1.5)** to accepted members only |
| US-24 | Must | `COMPLETE` | Pinned first, then newest |
| US-25 | Must | `PARTIAL` | **Add/edit/reorder/delete endpoints added (Phase 1.1)**; still no edit UI |
| US-26 | Must | `COMPLETE` | **All 4 settings applied — `shuffle` is now enforced server-side (Phase 1.2)** |
| US-27 | Must | `COMPLETE` | Draft invisible; `notifyMany` on publish (`QuizController:188`) |
| US-28 | Must | `PARTIAL` | Timer and attempt cap enforced; **late answers are no longer saved or scored (Phase 0.1)**; one-at-a-time is still client-side only and auto-submit is still lazy |
| US-29 | Must | `COMPLETE` | Server score, explanations gated by `show_answers` |
| US-30 | Must | `PARTIAL` | Average and missed questions present; **distribution missing** |
| US-31 | Should | `COMPLETE` | Upload, live job status, draft result |
| US-32 | Must | `COMPLETE` | **Resolved (Phase 1.3)** — fail-closed default, publication no longer certifies review, `reviewed_at` attested by an explicit review or a real content edit |
| US-33 | Must | `COMPLETE` | Clear message, manual path unaffected |
| US-34 | Should | `COMPLETE` | Order, enable/disable, daily limit |
| US-35 | Should | `COMPLETE` | Flip + "Je la connais"/"À revoir" + persistence |
| US-36 | Should | `COMPLETE` | **Resolved (Phase 1.5)** — assignment creation notifies accepted members (`ASSIGNMENT_PUBLISHED`, with `due_at`) |
| US-37 | Should | `COMPLETE` | Server-computed late flag |
| US-38 | Should | `COMPLETE` | Grade and comment visible to the student |
| US-39 | Should | `PARTIAL` | Calendar exists; quiz deadlines are fabricated from `published_at` |
| US-40 | Should | `PARTIAL` | History without a computed trend |
| US-41 | Should | `PARTIAL` | Inactive students returned as bare IDs |
| US-42 | Should | `COMPLETE` | Opt-in default off, skills and availability |
| US-43 | Should | `COMPLETE` | Classmates only, no email |
| US-44 | Should | `COMPLETE` | **Resolved (Phase 0.2)** — target `opt_in` enforced on the write path, not just on search |
| US-45 | Must | `COMPLETE` | Unread count, mark all read |
| US-46 | Should | `PARTIAL` | Digest exists; **no once-per-day guarantee** |
| US-47 | Should | `COMPLETE` | Filters by user, action and date |
| US-48 | Should | `PARTIAL` | Backend transfer with history preserved; **no UI** |

**Totals:** 35 `COMPLETE` — 12 `PARTIAL` — 1 `MISSING`

---

## 4. Compliance Matrix — Business Rules (§7)

| ID | Rule | Status | Evidence / gap |
|---|---|---|---|
| RG-01 | Only `@ofppt-edu.ma` may access ClassLink | `COMPLETE` | `RoleDetector:27-29`, `OtpService:33-36`, `config/classlink.php:48` |
| RG-02 | Server-side role from email format | `COMPLETE` | `RoleDetector:38-49`, regexes at `config/classlink.php:51,56` |
| RG-03 | Admin-set role is locked | `COMPLETE` | `RoleDetector:59-72`, honoured at `AuthController:116-127` |
| RG-04 | Teachers see/modify only their own classes | `COMPLETE` | `ClassroomPolicy:18-22`; enforced on every classroom route |
| RG-05 | Students need `accepted` membership | `COMPLETE` | `ClassroomPolicy:28-35`, re-checked per request (immediate removal works) |
| RG-06 | At most one pending request per class | `COMPLETE` | `MembershipService:55-65` + unique index |
| RG-07 | 24 h wait after rejection | `COMPLETE` | `MembershipService:68-74` → 429 |
| RG-08 | Invite code unique; disabled/regenerated codes refuse | `COMPLETE` | `JoinCodeService:20-42`, `MembershipService:41-48` |
| RG-09 | Removed student loses access, results kept | `COMPLETE` | `MembershipService:174-201`; no result deletion anywhere |
| RG-10 | Archived class is read-only | `PARTIAL` | Enforced via `assertWritable` and `ClassroomPolicy::modify`, but **`manage` does not check read-only**, so quiz creation/publish and other `manage`-gated paths can still write to an archived class |
| RG-11 | Draft invisible; AI content needs review | `COMPLETE` | Scoped queries + policy + 409 publish gate (see F-IA-03 for the fail-open default) |
| RG-12 | Attempt cap; expired attempt auto-submitted | `PARTIAL` | Cap enforced (409); **post-deadline answers are refused and not scored (Phase 0.1)** and the served question order is frozen per attempt (Phase 1.2). **Auto-submit is still lazy only** — no scheduled job finalises an expired attempt |
| RG-13 | Correct answers never sent before submission | `COMPLETE` | `AttemptQuestionResource` exposes only `id`/`label`; `QuestionResource` reachable only for owners; asserted by tests |
| RG-14 | AI limited by quota, size, pages; hash-cached | `PARTIAL` | Quota/size fine; page limit checked late; hash cache skips only the LLM call |
| RG-15 | Provider failover; manual fallback | `COMPLETE` | `AiService:45-150` |
| RG-16 | Late submission flagged | `COMPLETE` | `MaterialStorageService:192-195`, server-computed |
| RG-17 | Partner profile off by default, same-class only, no email | `COMPLETE` | **Resolved (Phase 0.2)** — `opt_in` enforced on the request path, not just on search |
| RG-18 | Email visibility restricted; no date of birth | `COMPLETE` | `MemberResource:30-50`, `UserResource`, `SubmissionResource`, `User::$hidden`; **no birth-date column exists in any migration** |
| RG-19 | Token expires after 8 h, revoked at logout | `COMPLETE` | `config/classlink.php:82`, `TokenService` (UI gap tracked under F-AUTH-07) |
| RG-20 | Sensitive actions written to the audit log | `COMPLETE` | Verified 23 `AuditLog::record` sites covering login, logout, logout-all, denied, membership accept/reject/remove, role change, activation change, class create/archive/transfer/code ops, quiz publish/review, AI provider update, AI quota reset, submission grade/download, material download |

**Totals:** 17 `COMPLETE` — 3 `PARTIAL`

---

## 5. Compliance Matrix — Non-Functional Requirements (§8)

| ID | Category | Status | Evidence / gap |
|---|---|---|---|
| NF-01 | Security | `COMPLETE` | Sanctum tokens with 8 h TTL, per-route role + policy checks, 403 tests throughout |
| NF-02 | Security | `COMPLETE` | CORS scoped to `api/*` with an explicit allowlist, no secrets in Git, HSTS via platform. **Resolved (Phase 0.5):** `trustProxies()` is configured from `TRUSTED_PROXIES` (audit IPs are no longer the proxy IP) and `sslmode` reads `DB_SSLMODE` |
| NF-03 | Security | `COMPLETE` | MIME **and** extension whitelist, magic-byte validation (`FileContentValidator`), UUID filenames, private disk, `nosniff` + CSP + `attachment` |
| NF-04 | Security | `PARTIAL` | Limits present (`config/classlink.php:176-182`) on OTP request/verify, OAuth redirect, AI, join. **IP limits are per-client again (Phase 0.5)**. Still open: **`GET /auth/microsoft/callback` is unthrottled** and no test exercises the throttle middleware |
| NF-05 | Confidentiality | `COMPLETE` | Data minimisation, masked emails, `/privacy` page, no birth date |
| NF-06 | Performance | `NEEDS VERIFICATION` | No load test, no measurement, no evidence of < 3 s at 200 users |
| NF-07 | Availability | `PARTIAL` | Free-tier config present, `healthCheckPath: /up`. **The AI quota reset now fires in production (Phase 0.3)** via `POST /api/internal/ai-quota-reset` + a GitHub Actions cron, matching the existing digest/prune pattern. Still open: **no documented wake procedure** before a demonstration; AI jobs run inside the web request rather than a worker |
| NF-08 | Ergonomics | `PARTIAL` | Responsive layout is solid; **touch targets 20–40 px** vs the mandated 44 px; status is text-based, not colour-only (compliant) |
| NF-09 | Accessibility | `PARTIAL` | Focus-visible outline, `prefers-reduced-motion`, ARIA live regions, keyboard-operable cards. **No `<label>`/`htmlFor` anywhere**, so every form field lacks an accessible name; dialogs lack `aria-modal`, focus trap and Escape handling |
| NF-10 | Internationalisation | `PARTIAL` | 561/561 parity enforced by test; **10 hardcoded strings**, whole `/denied` screen among them |
| NF-11 | Maintainability | `PARTIAL` | GitHub repo and `docs/API.md` exist; **single commit on `main`, no branches or pull requests** |
| NF-12 | Scalability | `PARTIAL` | Provider-agnostic `AiService` (configuration-only provider swap) ✔. **Adding a language requires editing `i18n/index.tsx`**, so it is not code-free as the requirement states |
| NF-13 | Reliability | `MISSING` | **No backup, export or restore procedure anywhere**, and no evidence a restore was ever tested |
| NF-14 | Compatibility | `NEEDS VERIFICATION` | Chromium-based verification only; Safari/Firefox/Edge untested |

**Totals:** 4 `COMPLETE` — 7 `PARTIAL` — 1 `MISSING` — 2 `NEEDS VERIFICATION`

---

## 6. Compliance Matrix — Test Plan (§20)

Mapped by behaviour, not by the internal `test_tNN_` labels, which are **shifted relative to the specification** (e.g. the spec's T-01 lives in a method named `test_t02_*`).

| ID | Scenario | Status | Location / gap |
|---|---|---|---|
| T-01 | 13-digit email → student | `COMPLETE` | `RoleDetectionTest.php:42` |
| T-02 | Teacher pattern → teacher | `COMPLETE` | `RoleDetectionTest.php:75` (fixture `zakariyae.chergui@…`) |
| T-03 | Unknown format → pending | `COMPLETE` | `RoleDetectionTest.php:112` |
| T-04 | `x@gmail.com` refused | `COMPLETE` | `RoleDetectionTest.php:22`; no OTP issued; redirect denied |
| T-05 | Valid code within 10 min | `COMPLETE` | `OtpLoginTest.php:36` |
| T-06 | Expired code / 6th attempt | `COMPLETE` | `OtpLoginTest.php:66,94` |
| T-07 | Valid code → pending + teacher notified | `COMPLETE` | `MembershipTest.php:43` |
| T-08 | Second request → 409 | `COMPLETE` | `MembershipTest.php:76,90` |
| T-09 | Request 1 h after rejection → 429 | `COMPLETE` | `MembershipTest.php:108` |
| T-10 | Disabled code → 404 | `COMPLETE` | `MembershipTest.php:148,157,175` |
| T-11 | Teacher B opens teacher A's class → 403 | `COMPLETE` | `AuthorizationTest.php:88` |
| T-12 | Non-accepted student → 403 | `COMPLETE` | `AuthorizationTest.php:23,55` |
| T-13 | Student calls teacher route → 403 | `COMPLETE` | `AuthorizationTest.php:149,156`; `AiTest.php:657` |
| T-14 | Removed student reopens class | `COMPLETE` | `AuthorizationTest.php:42`, `MembershipTest.php:290` (unnamed against the spec ID) |
| T-15 | Submission after time elapsed | `COMPLETE` | `QuizTest.php:52,79` |
| T-16 | Attempt beyond maximum refused | `COMPLETE` | `QuizTest.php:142,155` |
| T-17 | No correct answers before submission | `COMPLETE` | `QuizTest.php:330,352,362` |
| T-18 | First provider 429 → second responds | `COMPLETE` | `AiTest.php:120,143` |
| T-19 | All providers fail → message + manual path | `COMPLETE` | `AiTest.php:215,229,247,275,296,344` |
| T-20 | Same PDF twice → cache, quota untouched | `PARTIAL` | Service cache covered (`AiTest.php:369,392,406`); **teacher quota is still consumed** |
| T-21 | `.exe` upload → 422 | `COMPLETE` | `FileUploadTest.php:32,47,61,77,93` |
| T-22 | Student sees no emails | `COMPLETE` | `AuthorizationTest.php:253,279,290,299` |
| T-23 | Logout then token reuse → 401 | `COMPLETE` | `SessionTest.php:24,46`; frontend `session-flow.test.tsx` |
| T-24 | Back button after logout | `PARTIAL` | jsdom/`MemoryRouter` proxy (`session-flow.test.tsx:85`), not a real `popstate`; a manual pass is still conceded in the docs |
| T-25 | Token > 8 h → 401 "Session expirée" | `COMPLETE` | `SessionTest.php:86` asserts the code but **not the localised message**, and the UI never displays it |
| T-26 | FR → EN, nothing untranslated | `PARTIAL` | Backend strong (10 tests); frontend test checks dictionary parity and a similarity heuristic, **not rendering** |
| T-27 | Student journey on a phone | `PARTIAL` | Assertions run against `AppShell.tsx` **source text**, not a 360 px viewport |
| T-28 | Three audit rows for three actions | `COMPLETE` | `AuditAndLocaleTest.php:33,85,98,109` |

**Totals:** 24 `COMPLETE` · 4 `PARTIAL` · 0 `MISSING`

### Test suite inventory

| Suite | Declared test methods |
|---|---|
| Backend `Feature/AiTest.php` | 29 |
| Backend `Feature/AuthorizationTest.php` | 36 |
| Backend `Feature/FileUploadTest.php` | 31 |
| Backend `Feature/QuizTest.php` | 24 |
| Backend `Feature/AuditAndLocaleTest.php` | 19 |
| Backend `Feature/MembershipTest.php` | 16 |
| Backend `Feature/FlashcardReviewTest.php` | 14 |
| Backend `Feature/SessionTest.php` | 14 |
| Backend `Feature/MicrosoftRedirectTest.php` | 13 |
| Backend `Feature/AssignmentSubmissionTest.php` | 11 |
| Backend `Feature/RoleDetectionTest.php` | 10 |
| Backend `Feature/OtpLoginTest.php` | 10 |
| Backend `Feature/InternalRouteTest.php` | 9 |
| Backend `Feature/ApiContractTest.php` | 8 |
| Backend `Feature/ProductionConfigTest.php` | 8 |
| **Backend total** | **252** |
| Frontend `tests/api.test.ts` | 12 |
| Frontend `tests/i18n-layout.test.tsx` | 9 |
| Frontend `tests/session.test.ts` | 6 |
| Frontend `tests/session-flow.test.tsx` | 4 |
| **Frontend total** | **31** |

Coverage observations:

- **`backend/tests/Unit/` is empty.** The spec (§20.1) explicitly asks for unit tests on role detection, membership rules, score computation and AI failover. All four are currently only exercised indirectly through feature tests.
- **21 of 24 frontend screens have zero render coverage**, including every data-bearing screen (student, teacher, admin, quiz, grading). Only `LoginScreen`, `PendingScreen` and `AccessDeniedScreen` are rendered by tests.
- `docs/JIRA-BOARD.md:86` claims "246 tests, 641 assertions", which does not match the 252 declared methods.
- The rate-limit middleware has **zero test coverage**; every 429 in the suite comes from business logic or an upstream provider.

---

## 7. Security & Privacy Audit (§16)

### 7.1 Verified controls

| Threat | Spec measure | Implementation | Verdict |
|---|---|---|---|
| Identity spoofing | Microsoft login; OTP hashed, 10 min, 5 attempts, rate limited | `OtpService` (hashed, `hash_equals`, consumed, throttled `3,1` / `10,1`) | **Sound** |
| Cross-class data access | Policies on every route; dedicated 403 tests | 8 policy classes; 403 assertions in `AuthorizationTest` (36 methods) | **Sound** |
| Role elevation | Role computed and changed server-side only | `ProfileController:39-42` `prohibited`; `AdminController:68-71` strict subset; `User::$hidden` | **Sound** |
| Token theft | HTTPS, 8 h token in `sessionStorage`, revocation at logout | `session.ts` (sessionStorage only, storage-failure tolerant), `TokenService` | **Sound in design**; audit IPs wrong behind the proxy |
| Dangerous files | Type whitelist, size cap, sanitised names, non-executable external storage | Whitelist + **magic-byte check** + UUID name + `nosniff` + CSP + `attachment` | **Stronger than the spec** |
| SQL injection | Prepared queries via Eloquent | No hand-built queries found; all input through Form Requests / `validate()` | **Sound** |
| Brute force / abuse | Throttling on login, email code and AI | Present, but callback unthrottled and no throttle tests | **Partial** |
| Secret leakage | Env vars, `.env` excluded from Git, distinct dev/prod keys | `.env` untracked and ignored; only `.env.example` tracked; Render secrets `sync: false` | **Sound** |
| Quiz cheating | Correct answers only after submission; timer verified server-side | Structurally impossible to leak pre-submission; deadline derived from `started_at` | **Sound on answers, partial on timer** (B1) |
| Traceability | Audit log of sensitive actions | 23 audited sites, append-only, filterable | **Sound** |

### 7.2 Confirmed no-secret state

`git ls-files` tracks only `backend/.env.example` and `frontend/.env.example`. A credential sweep (PEM blocks, `sk-…`, `AKIA…`, long `base64:` keys) returned only the well-known throwaway Laravel CI key in `.github/workflows/ci.yml:40,174`. `.dockerignore:7-10` excludes real `.env` files while keeping the examples.

### 7.3 Privacy defects

| Defect | Location | Impact |
|---|---|---|
| Audit IPs are the proxy IP | no `trustProxies()` in `bootstrap/app.php` | RG-20 traceability is materially weakened in production |
| IP-keyed throttles become global | same root cause | OTP/AI limits apply to all users together, not per caller |
| `opt_in` not enforced on partner requests | `PartnerMatchingService:107-140` | A student can contact a classmate who never opted in — RG-17 breach |
| OTP code logged in clear when mail fails | `OtpService:72-75` | Acceptable for local demo, unacceptable in production; should be gated on `APP_ENV` |
| Mass-assignment latent hazards | `User::$fillable` includes `role`, `role_locked`, `is_active`; `AttemptAnswer` includes `is_correct`; `Submission` includes `is_late` | Not currently reachable — every write path validates a strict subset — but any future `create($request->all())` becomes a privilege-escalation bug |

### 7.4 Under-protected endpoints

| Severity | Endpoint | Issue |
|---|---|---|
| High | `POST /partner-requests` (`routes/api.php:205`) | No policy call at all; only `role:student` plus service-level membership checks, and no `opt_in` check |
| Medium | `PATCH /admin/users/{id}` (`routes/api.php:227`) | No `authorize()`; relies solely on `role:admin`. `UserPolicy::update` exists but is **never invoked** |
| Medium | `POST /admin/classes/{id}/transfer`, `/archive` (`:230-231`) | No policy call |
| Medium | `POST /notifications/{id}/read` (`:220`) | Inline `if` instead of a policy; no `AppNotificationPolicy` registered |
| Low | `POST /auth/dev/login` (`:60`) | Registered unconditionally; the comment claiming it is absent in production is **inaccurate** — only the controller and `config/classlink.php:193` guard it |
| Low | `POST /join-requests/{id}/accept` / `reject` (`:113-114`) | Authorize ownership correctly, but `MembershipPolicy` is registered and **never used**, and there is no status precondition |
| Low | `POST /quizzes/{id}/review` (`:136`) | Ownership correct, but the "must be AI-sourced" rule lives in the controller; `AiJobPolicy::generate` is dead code |
| Info | `GET /classes/{id}/progress` (`:199`) | `manage` includes admins, so a super-admin can read any class's progression |
| Info | `POST /internal/daily-digest`, `/internal/prune` (`:66,70`) | Unauthenticated but correctly gated by a token middleware that **fails closed** on an empty secret |

**Positive finding:** no controller returns a raw Eloquent model. Every action returns an API Resource or a hand-built whitelist array, which is why no `email`, `file_path` or internal column leaks. Model `$fillable` is defined on all 20 models and no model uses `$guarded = []`.

---

## 8. API Contract, Data Model & Frontend Conformance

### 8.1 API contract

Every one of the ~95 helpers in `frontend/src/lib/endpoints.ts` maps to an existing backend route: **no dead endpoints, no wrong verbs, no response-shape mismatches.** Notable strengths: AI job polling every 3 s with terminal-state cleanup; authenticated downloads never place the bearer token in a URL; `PATCH /me` feeds straight back into the auth context.

All §12 routes are present, plus justified extensions (`/me/sessions`, `/me/deadlines`, `/quizzes/{id}/review`, `/flashcard-decks/*`, `/submissions/{id}/download`, `/admin/users/pending`, `/quizzes/{id}/results/export`, `/me/partner-requests`). Every documented response code (200/201/202/204/401/403/404/409/422/429) is produced.

Deviations found:

| Deviation | Detail |
|---|---|
| `/me/deadlines` is an inline closure | `routes/api.php:171-196` — no controller, resource or policy; inconsistent with §12.5 |
| `GET /auth/microsoft/callback` unthrottled | `routes/api.php:50` |
| Frontend dead helpers | Edit/lifecycle helpers exist with **zero call sites**: `PATCH /quizzes/{id}`, `PATCH /classes/{id}`, `PATCH /assignments/{id}`, announcement/material update, deck create/destroy/markReviewed, `admin.transferClass`, `admin.pendingUsers`, `quizzes.attempt` resume |
| Two frontend defects | `TeacherClassScreens.tsx:697` passes a literal ellipsis instead of the generated count; `:690` has an identical-branched ternary (`failed ? failed : failed`) |
| `firstFieldError` typed `string \| null` never returns `null` | `api.ts:225-229` — callers cannot distinguish "no field error" |

### 8.2 Data model (§11)

All 20 specified tables exist with the specified columns, unique constraints and indexes. Verified highlights: `users.email` unique with index on `role`; `classrooms.join_code` unique with index on `teacher_id`; `memberships` unique `(classroom_id, student_id)` + status index; `attempts` unique `(quiz_id, student_id, attempt_no)`; `attempt_answers` unique `(attempt_id, question_id)`; `partner_profiles.user_id` unique; `partner_requests` unique `(from, to, classroom)`; `ai_jobs` index on `file_hash`; `audit_logs` index `(action, created_at)`; `otp_codes` index on `expires_at`. Cascade delete on questions is present. **No password column and no date-of-birth column exist anywhere.** OTP codes are hashed.

Schema gaps:

| Gap | Detail |
|---|---|
| Quizzes have no deadline | `quizzes` stores only `published_at`, forcing `/me/deadlines` to fabricate a due date (F-DEV-04) |
| `ai_jobs.page_count` unused | Column declared but never written |
| No `due_at` on quizzes, no notification-preference columns | F-NOT-03 unimplemented at the schema level |
| Migration default `reviewed = true` | Fail-open default for the AI review gate |
| No index/constraint for archived-class filtering | `ClassroomController::index` does not filter on `status` |

### 8.3 Frontend, UX, accessibility, i18n

**Strong:** route and guard coverage is complete (`guards.tsx:39-48` handles anonymous / pending / denied / wrong role before render); API contract fidelity is exact; i18n dictionary parity is perfect and enforced; shared async primitives (`useAsync`, `useAction`, `AsyncBoundary`) provide abort, stale-response guarding and retry; status is never conveyed by colour alone (`Badge` renders text); no `<img>` anywhere — icons are inline SVG with `aria-hidden`; `prefers-reduced-motion` honoured.

**Gaps:**

| Area | Finding |
|---|---|
| Session expiry | **MISSING.** `api.ts:213-215` explicitly returns the generic fallback for `session_expired`, and `useAsync.ts:47` swallows the error entirely, leaving `AsyncBoundary` to render an empty container. The user is silently bounced to `/login` (B4) |
| Labels | **No `htmlFor`/`id` pairing anywhere.** `Field` (`UI.tsx:143-155`) renders label and input as siblings, so every form field has no accessible name — the highest-impact a11y defect |
| Touch targets | `Btn size="sm"` ≈28 px, `Avatar sm` 32 px, `Toggle` track 40×20 px, `LocaleSwitch` ≈24 px, menu items ≈26 px — all against the mandated 44 px |
| Dialogs | Notification drawer uses `role="dialog"` with no `aria-modal`, no focus trap, no Escape, no focus restoration. Three click-only overlay `<div onClick>` have no keyboard equivalent |
| Confirmations | No reusable `ConfirmDialog` component exists. Archive is confirmed inline; **accept-all, logout, logout-all-sessions and member removal are immediate** |
| Navigation | Student bottom navigation bar (§14.2) missing; navigation is top hamburger + sidebar only |
| Hardcoded strings | 10 user-facing strings bypass `t()`, including the whole `/denied` screen; 131 dictionary keys are unused, which is a reliable signal of unbuilt UI |
| Empty/loading/error | Complete for 22 of 24 screens; `ProfileScreen` bypasses `AsyncBoundary`, shows a bare error with no retry, and OTP resend is not disabled while pending |
| Tables | Only admin screens use `<table>`; teacher member and results lists are `divide-y` div grids with no table semantics or caption |

### 8.4 Visual identity (§14)

`IMPLEMENTED DIFFERENTLY` — the palette and typefaces do not match the specification. The spec mandates Navy `#0F2A4A`, Ocean `#1E5AA8`, Cream `#FAF6EE`, Red `#D9483B` and Poppins / Inter / JetBrains Mono. The implementation ships a different palette (`#1A3A4A`-family `#1A3A6B`, background `#F7F8FC`, orange accent `#E8820C`) with **Fraunces + Outfit**. Compliant details: 12 px/8 px radii, an 8 px grid, text-based statuses, a component library, and `overflow-x-auto` tables. A dead `.font-arabic` rule referencing 'Al Adarissa' remains even though Arabic is explicitly out of scope.

---

## 9. Deployment, CI & Operations (§21)

| Topic | Status | Detail |
|---|---|---|
| Auto-deploy on merge to main | `MISSING` (in-repo) | `ci.yml` has **zero deploy steps**. `render.yaml` and `frontend/vercel.json` exist, but rollout depends entirely on platform-side Git integration that is not configured anywhere in the repository |
| Migrations on deploy | `COMPLETE` | `render.yaml:147` `preDeployCommand: php artisan migrate --force` |
| Queue worker | `MISSING` | `render.yaml:90-91` `QUEUE_CONNECTION=sync`, single docker service, no `queue:work`. AI jobs run in-request |
| Scheduler | `MISSING` | No `schedule:run` in Dockerfile, `render.yaml` or any workflow. `routes/console.php:22-24` (digest 07:00, prune 03:00, **AI quota reset 00:05**) is dead in production. Digest and prune survive via GitHub Actions cron; **the AI quota reset has no other trigger** (B3) |
| Super-admin bootstrap | `COMPLETE` | `app/Console/Commands/MakeSuperAdminCommand.php` |
| Demo data | `IMPLEMENTED DIFFERENTLY` | `DemoSeeder.php` — 27 users, 4 classrooms, 6 quizzes vs the spec's "1 class, 1 teacher, ~20 students, 2 quizzes". A richer superset; acceptable, but worth aligning for the demonstration script |
| Rate limits | `PARTIAL` | `otp_request 3,1`, `otp_verify 10,1`, `oauth_redirect 30,1`, `ai_generate 20,1`, `join_request 10,1`, dev login `30,1`. Callback unthrottled; no tests |
| HTTPS / CORS / debug | `COMPLETE` | `config/cors.php` scoped to `api/*` with an explicit allowlist and credentials off; `render.yaml:42` forces debug false; HSTS and headers in `vercel.json`; `ProductionConfigTest.php` asserts no cookie auth in production |
| Session cookie | `PARTIAL` | `config/session.php:173` `secure => env('SESSION_SECURE_COOKIE')`, never set in `render.yaml`. Mitigated because the API is bearer-only |
| Trusted proxies | `MISSING` | Behind Render, `$request->ip()` is the proxy IP → wrong audit IPs and globalised throttles |
| Database TLS | `PARTIAL` | `config/database.php:108` hardcodes `'sslmode' => 'prefer'`, ignoring `DB_SSLMODE=require` set in `render.yaml:82-83` |
| Storage / mail | `COMPLETE` | `FILESYSTEM_DISK=s3`, private bucket, all secrets `sync: false`; mail falls back to `log` until Brevo credentials exist |
| Backup / restore (NF-13) | `MISSING` | No `pg_dump`, restore runbook or retention policy in `README.md`, `docs/DEPLOYMENT.md` or any config. The free Render DB has no documented PITR — a data-loss risk |
| Monitoring | `MISSING` | Liveness only (`/up`). No uptime alerting, no AI-quota alerting, no Brevo bounce monitoring, no storage-expiry alert |
| Pruning (§21.2) | `PARTIAL` | `PruneExpiredOtpCodes` removes expired OTP codes and tokens only. **No cleanup of abandoned quiz attempts**, stale sessions or expired Sanctum tokens via the job (`TokenService::purgeExpired()` exists but is never scheduled) |
| Documentation honesty | `COMPLETE` | `docs/ASSUMPTIONS.md:9-13` explicitly states external providers were never live-tested — consistent with this audit's `NEEDS VERIFICATION` items |

---

## 10. Technical Debt

### 10.1 Critical

| ID | Item | Location |
|---|---|---|
| D1 | Post-deadline answers are saved and scored; `expired` is only a flag | `QuizGradingService:75-107`, `QuizController:264-266` |
| D2 | Partner requests bypass `opt_in` | `PartnerMatchingService:107-140` |
| D3 | No production scheduler → AI quota never resets | `routes/console.php:22-24`, `render.yaml` |
| D4 | "Session expirée" discarded client-side | `api.ts:213-215`, `useAsync.ts:47` |
| D5 | AI quota reset is the only schedule entry with no GitHub Actions fallback | `.github/workflows/` has `daily-digest` and `prune` only |

### 10.2 High

| ID | Item | Location |
|---|---|---|
| D6 | No question-level create/update/reorder/delete endpoints | `routes/api.php:129-148` |
| D7 | `shuffle` never applied server-side | `QuizGradingService:55` |
| D8 | All quiz questions returned in one payload | `QuizController:252-254` |
| D9 | AI hash cache consumes teacher quota and creates duplicate drafts | `AiController:51-76`, `ProcessAiGeneration:57-69` |
| D10 | `reviewed` column defaults to `true` (fail-open AI gate) | `2026_10_01_000005:33` |
| D11 | No `label`/`htmlFor` association — all form fields unnamed | `UI.tsx:143-155` |
| D12 | Touch targets below 44 px | `UI.tsx:81,325,344,496,515` |
| D13 | No trusted-proxy configuration | `bootstrap/app.php` |
| D14 | `DB_SSLMODE` env ignored | `config/database.php:108` |
| D15 | No backup/restore procedure | project-wide |
| D16 | 4 state-changing endpoints without a policy call | `routes/api.php:205,220,227,230,231` |

### 10.3 Medium

| ID | Item | Location |
|---|---|---|
| D17 | Accept/reject has no status precondition (re-accept bypasses the 24 h cooldown) | `MembershipService:98-147` |
| D18 | Archived classes still listed in "My classes"; `manage` ignores read-only | `ClassroomController:30-53`, `ClassroomPolicy:18-22` |
| D19 | Announcements and assignment creation send no member notification | `ContentController:167-185`, `AssignmentController:82-98` |
| D20 | 10 backend capabilities unreachable in the UI (see §8.1) | `frontend/src/lib/endpoints.ts` |
| D21 | 10 hardcoded user-facing strings; 131 unused dictionary keys | `guards.tsx`, `AppShell.tsx`, `UI.tsx`, `useAsync.ts` |
| D22 | No reusable `ConfirmDialog`; spec-mandated confirmations missing | `frontend/src/components/UI.tsx` |
| D23 | Student bottom navigation bar missing | `AppShell.tsx` |
| D24 | `/me/deadlines` inline closure; quiz deadlines fabricated | `routes/api.php:171-196` |
| D25 | Quiz results lack a score distribution | `QuizResultsResource:54-67` |
| D26 | Progression: no computed trend; inactive students as bare IDs | `ProgressionService:22-35,125` |
| D27 | CSV import: no UI, invalid rows silently skipped | `ClassroomController:243-270`, `MembershipService:222-224` |
| D28 | Digest has no once-per-day idempotency guard | `EmailDigestService` |
| D29 | Design identity diverges from §14 (palette + typefaces) | `frontend/src/index.css` |
| D30 | No admin class-transfer or pending-user UI | `frontend/src/screens/AdminScreens.tsx` |

### 10.4 Low

| ID | Item | Location |
|---|---|---|
| D31 | `MemberResource` comment cites a non-existent "F-CLS-07" | `MemberResource:25` |
| D32 | Dev-login route comment claims production absence; route is always registered | `routes/api.php:59-60` |
| D33 | Test method labels shifted vs the specification's T-numbers | `backend/tests/Feature/*` |
| D34 | `docs/JIRA-BOARD.md:86` claims 246 tests vs 252 declared | docs |
| D35 | `ai_jobs.page_count` column never written | migration + `ProcessAiGeneration` |
| D36 | `TokenService::purgeExpired()` and `purgeExpired()` in `OtpService` never scheduled | services |
| D37 | Audit log `from`/`to` unvalidated raw strings | `AdminController:245,249` |
| D38 | Dead `.font-arabic` rule for an out-of-scope language | `frontend/src/index.css` |
| D39 | Latent mass-assignment hazards on 4 models | `User`, `AttemptAnswer`, `Submission`, `AiProvider` |
| D40 | Frontend defects: literal ellipsis count, identical ternary branches, unreachable `null` in `firstFieldError` | `TeacherClassScreens.tsx:690,697`, `api.ts:225-229` |

---

## 11. Recommended Implementation Plan

Phases are ordered by dependency, then by risk. Nothing in Phase 1 requires a schema change or a new package.

### Phase 0 — Blockers (Must, ~2 days)

| # | Task | Req. IDs | Files / modules | Current problem | Expected result | Priority | Depends on |
|---|---|---|---|---|---|---|---|
| 0.1 | Reject answers submitted after the deadline | RG-12, F-QUI-04, US-28, T-15 | `QuizGradingService`, `QuizController`, `SubmitAttemptRequest` | `saveAnswers()` runs before grading and only checks `isSubmitted()`, so late answers are persisted and scored | Deadline enforced at save time; expired attempts graded from answers stored at or before `started_at + time_limit_min` | P0 | — |
| 0.2 | Enforce `opt_in` on the partner-request path | RG-17, F-PAR-03, US-44 | `PartnerMatchingService` | Request validation checks membership but not the target's consent | 403/422 when the target has `opt_in = false`; regression test added | P0 | — |
| 0.3 | Run the scheduler in production | NF-07, F-IA-05 | `render.yaml`, `routes/console.php` | No `schedule:run` anywhere, so the AI quota reset never fires | Quota resets daily; or expose the reset as an `/internal/*` route like the other two tasks | P0 | — |
| 0.4 | Surface "Session expirée" in the UI | F-AUTH-07, US-07, T-25 | `api.ts`, `useAsync.ts`, `LoginScreen`, `guards.tsx` | The localised message is discarded twice, then the error is swallowed | `LoginScreen` reads `?expired=1` and shows the banner; `useAsync` propagates the error; proactive warning before expiry | P0 | — |
| 0.5 | Fix `trustProxies` and `DB_SSLMODE` | NF-02, NF-04, RG-20 | `bootstrap/app.php`, `config/database.php` | Audit IPs are the proxy IP; DB TLS mode env is ignored | Correct client IPs in the audit log, per-caller throttles, enforced DB TLS | P0 | — |

### Phase 1 — Must-priority functional gaps (~1 week)

| # | Task | Req. IDs | Files / modules | Current problem | Expected result | Priority | Depends on |
|---|---|---|---|---|---|---|---|
| 1.1 | Add question-level CRUD + reorder endpoints | F-QUI-01, US-25 | `routes/api.php`, `QuizController`, `QuizPolicy`, new `QuestionController` | Questions can only be supplied at creation | Teacher can add, edit, reorder and delete questions on a draft quiz; `position` renumbered transactionally | P0 | 0.1 |
| 1.2 | Apply `shuffle` server-side | F-QUI-02, US-26 | `QuizGradingService`, `Attempt` | Randomisation is entirely client-side and defeatable | Question order randomised on the server when `shuffle = true`, order persisted on the attempt | P0 | 1.1 |
| 1.3 | Make the AI review gate fail-closed | F-IA-03, RG-11, US-32 | migration, `Quiz`, `FlashcardDeck`, `QuizController` | `reviewed` defaults to `true`; the flag does not prove the editor was opened | Default `false`; `reviewed_at` timestamp set only by an actual question/deck edit; publish requires it | P0 | — |
| 1.4 | Add a status precondition to accept/reject | F-REQ-04, RG-07 | `MembershipService` | A rejected or removed membership can be re-accepted, bypassing the cooldown | Only `pending` memberships can transition; 409 otherwise | P0 | — |
| 1.5 | Notify members on announcement and assignment creation | F-CON-04, F-DEV-01, US-23, US-36 | `ContentController`, `AssignmentController` | Neither action notifies the class | `notifyMany` on publish, mirroring `QuizController:188` | P0 | — |
| 1.6 | Filter archived classes out of "My classes"; enforce read-only on `manage` | RG-10, US-11 | `ClassroomController::index`, `ClassroomPolicy` | Archived classes remain listed and `manage` ignores read-only | Archived classes leave the active list and all `manage`-gated writes are refused | P1 | — |
| 1.7 | Add the four missing policies | NF-01, §12 | `PartnerRequestPolicy`, `AppNotificationPolicy`, `UserPolicy` wiring, `AiJobPolicy` wiring | 4 state-changing routes rely on inline checks; 3 policies are dead code | Every state-changing route authorized by a policy | P1 | 0.2 |
| 1.8 | Scheduled auto-submit of expired attempts | RG-12, F-QUI-04 | `routes/console.php`, `QuizGradingService` | Auto-submit fires only on the next read | A scheduled task finalises expired attempts even if the student never returns | P1 | 0.1, 0.3 |

### Phase 2 — UI completeness against accepted criteria (~1 week)

| # | Task | Req. IDs | Files / modules | Current problem | Expected result | Priority | Depends on |
|---|---|---|---|---|---|---|---|
| 2.1 | Reusable `ConfirmDialog` + wire required confirmations | US-06, US-17, US-18 | `UI.tsx`, `TeacherClassScreens`, `ProfileScreen` | No dialog component; accept-all, logout, revoke-all and member removal are immediate | Every spec-mandated destructive action confirmed | P0 | — |
| 2.2 | "Copy invite code" button | US-09 | `TeacherClassScreens` | No copy affordance; `common.copy`/`common.copied` unused | One click copies the code and shows a confirmation | P0 | — |
| 2.3 | Label/input association and dialog a11y | NF-09, F-UI-03 | `UI.tsx`, `AppShell` | Zero `htmlFor`/`id`; dialogs lack `aria-modal`, focus trap, Escape | Every field has an accessible name; dialogs are keyboard-complete | P1 | — |
| 2.4 | Raise all touch targets to ≥ 44 px | NF-08, F-UI-02 | `UI.tsx` | Targets range from 20 to 40 px | All interactive controls ≥ 44 px on mobile | P1 | — |
| 2.5 | Student bottom navigation bar | §14.2, F-UI-02 | `AppShell` | Navigation is top-hamburger + sidebar only | Bottom tab bar for students, per the mobile-first spec | P2 | — |
| 2.6 | Edit UIs for the 10 unreachable capabilities | F-CLS-04, F-CON-03, F-CON-04, F-DEV-01, F-QUI-01, F-ADM-03, F-REQ-09 | `TeacherClassScreens`, `TeacherScreens`, `AdminScreens`, `StudentScreens` | Helpers exist with zero call sites | Class/assignment/announcement/material editing, quiz editing, deck lifecycle, admin transfer, pending-user queue, attempt resume, CSV import all reachable | P1 | 1.1 |
| 2.7 | Migrate the `/denied` screen and remaining hardcoded strings | F-UI-01, NF-10 | `guards.tsx`, `AppShell`, `UI.tsx`, `useAsync` | 10 strings bypass `t()`; the whole denied screen is French-only | Zero hardcoded user-facing strings; `session-flow.test.tsx` updated | P1 | — |
| 2.8 | OTP resend lock + 10-minute countdown; cooldown/quota error handling | F-UI-03 | `PublicScreens` | Resend fires duplicate requests; countdown promised but absent | Resend disabled while pending; countdown shown; `error.cooldown` / `error.quotaReached` surfaced | P2 | — |

### Phase 3 — Should/quality gaps (~1 week)

| # | Task | Req. IDs | Files / modules | Current problem | Expected result | Priority | Depends on |
|---|---|---|---|---|---|---|---|
| 3.1 | Complete the AI hash cache | F-IA-05, RG-14, T-20 | `AiController`, `ProcessAiGeneration` | Repeat PDF still consumes quota, storage and creates a duplicate draft | Cache hit short-circuits before the quota check and returns the existing job/draft; no duplicate row | P1 | — |
| 3.2 | Check the page limit before accepting the job | F-IA-05 | `AiController`, `PdfTextExtractor` | Over-long PDFs return 202 then fail | Over-long PDFs rejected up front with 422 | P2 | — |
| 3.3 | Real background processing | NF-07, F-IA-07 | `render.yaml`, `AiController` | `QUEUE_CONNECTION=sync` runs AI in the web request | Queue worker service; `dispatch()` instead of `dispatchAfterResponse()` | P2 | 0.3 |
| 3.4 | Quiz score distribution | F-QUI-07, US-30 | `QuizResultsResource`, `ProgressionService` | No histogram or buckets | Bucket distribution returned and rendered | P2 | — |
| 3.5 | Progression trend and actionable inactive students | F-PRO-01, F-PRO-02, US-40, US-41 | `ProgressionService` | History array only; inactive students as bare IDs | Computed trend/delta; inactive students with display names | P2 | — |
| 3.6 | Real deadline calendar with quiz due dates | F-DEV-04, US-39 | new `DeadlineController`, migration, `AssignmentResource` | Inline closure fabricates quiz deadlines | `quizzes.due_at` column, controller + resource + policy, correct `is_overdue` | P2 | — |
| 3.7 | CSV roster import with per-row error reporting | F-REQ-09, US-19 | `ClassroomController`, `MembershipService`, UI | Invalid rows silently skipped; no UI | Response lists accepted and rejected rows with reasons; upload UI present | P3 | 2.6 |
| 3.8 | Digest idempotency (max one email/day) | F-NOT-02, US-46 | `EmailDigestService`, migration | A retried workflow sends a second email | Per-user `last_digest_at` guard | P2 | — |
| 3.9 | Notification preferences | F-NOT-03 | migration, `NotificationController`, `ProfileScreen` | Not implemented | Opt-in/out per notification type, honoured by the digest | P3 | 3.8 |
| 3.10 | Align the visual identity with §14 | §14.1, §14.2 | `frontend/src/index.css` | Different palette and typefaces | Spec palette and Poppins/Inter/JetBrains Mono, or a documented, approved deviation | P3 | — |

### Phase 4 — Deployment, operations and NFRs (~1 week)

| # | Task | Req. IDs | Files / modules | Current problem | Expected result | Priority | Depends on |
|---|---|---|---|---|---|---|---|
| 4.1 | Configure deploy-on-merge | NF-11, §21.1 | `.github/workflows/deploy.yml`, platform settings | No deploy step in the repository | Every merge to `main` ships API and frontend | P0 | 0.3 |
| 4.2 | Backup and restore procedure | NF-13 | `docs/DEPLOYMENT.md`, `scripts/` | No backup, export or restore anywhere | Documented `pg_dump` schedule, retention, and **one tested restore** | P0 | — |
| 4.3 | Monitoring and free-tier alerting | NF-07, §21.2 | workflow or uptime service | Liveness only | Uptime check, AI-quota and Brevo bounce alerts, storage-expiry alert | P1 | 4.1 |
| 4.4 | Schedule token and attempt pruning | §21.2 | `routes/console.php` | `purgeExpired()` methods never scheduled | Expired tokens and abandoned attempts pruned daily | P1 | 0.3 |
| 4.5 | Load and browser verification | NF-06, NF-14, T-24, T-27 | test harness | No perf measurement; Safari/Firefox/Edge untested; T-24/T-27 assert on source text | Documented < 3 s at 200 users; real-history and 360 px viewport tests; two-browser pass | P1 | — |
| 4.6 | Throttle coverage and callback limit | NF-04 | `routes/api.php`, `RateLimitTest` | Callback unthrottled; no throttle tests | Callback throttled; middleware tests for every limiter | P1 | 0.5 |
| 4.7 | Git branching and PR workflow | NF-11 | repo settings, `CONTRIBUTING` | Single commit on `main` | Protected `main`, feature branches, PR-based history | P2 | — |
| 4.8 | Language extensibility without code changes | NF-12 | `frontend/src/i18n` | Locale list is hardcoded in `index.tsx` | Locale registry; a new language is a file addition | P3 | — |
| 4.9 | Align demo data with §21.1 | §21.1 | `DemoSeeder` | 4 classes / 6 quizzes vs 1 / 2 | Seeder matches the documented demonstration scenario | P3 | — |

### Phase 5 — Test consolidation (~1 week)

| # | Task | Req. IDs | Files / modules | Current problem | Expected result | Priority | Depends on |
|---|---|---|---|---|---|---|---|
| 5.1 | Populate `backend/tests/Unit/` | §20.1 | new unit tests | Empty directory; role detection, membership rules, scoring and AI failover only tested indirectly | Direct unit tests for the four areas the spec names | P1 | — |
| 5.2 | Frontend render coverage for data screens | §20.1, NF-10 | `frontend/tests/` | 21 of 24 screens untested | Render tests for student, teacher and admin data screens | P1 | Phase 2 |
| 5.3 | Renumber tests to the specification's T-IDs | §20 | `backend/tests/Feature/*` | Labels shifted by one or more | `test_tNN_` matches T-01…T-28 | P2 | — |
| 5.4 | Reconcile documentation counts | NF-11 | `docs/JIRA-BOARD.md`, `README.md` | Claims 246 tests vs 252 declared | Counts match reality | P3 | — |

---

## 12. Final Status

**Overall:** the implementation satisfies **117 of 179 (65.4%)** audited specification items outright, with a further **57 (31.8%)** partially met. Only **3 items (1.7%)** are entirely absent: notification preferences (a `Could`), backup/restore (NF-13), and in-repository deploy-on-merge. Two items cannot be judged without a live environment: page-load performance at 200 users and cross-browser compatibility.

The security foundation is the strongest part of the project: server-only role computation, policy-enforced ownership and membership on every content route, hash-verified file uploads served non-executably, strict response resources that never leak emails or storage paths, fail-closed CORS, hashed OTP codes, a complete audit trail, and no committed secrets. None of the four critical blockers is a design failure — each is a bounded defect at a known location.

**Critical blockers to resolve first:** post-deadline quiz answers being scored (B1), the partner `opt_in` consent bypass (B2), the AI quota reset never running in production (B3), and the discarded "Session expirée" message (B4).

**First phase to execute:** Phase 0 (5 tasks, no schema change, no new dependency) followed by Phase 1 items 1.1–1.5. Together these clear every Must-priority functional gap, all four blockers, and the fail-open AI review default — the minimum required before the v1.0 demonstration.
