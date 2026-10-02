# API Reference — ClassLink 1.0

Base path: `/api`. All endpoints (except public auth + internal digest/prune) require `Authorization: Bearer <token>` header. API is stateless (no cookies). Locale applied via `Accept-Language`/profile or request context; validation/errors localized (FR/EN). Role/policy checks enforced server-side (§12, §16).

## Conventions
- JSON only. Resources use `JsonResource::withoutWrapping()` in general; collections returned as `{ data: [...] }` where specified.
- Dates ISO 8601. Floats serialized without trailing .0 in practice.
- Errors: `422` validation, `401` session_expired (T-25), `403` forbidden, `404` not found, `429` too_many_requests, `409` business rule conflict, `503` ai_unavailable (manual fallback).
- Every `/api/*` response is JSON whatever the client sends in `Accept`. A client that omits the header (`curl`, mobile app, monitoring probe) gets the same documented status codes instead of an HTML error page — Laravel 11's default `redirectGuestsTo(route('login'))` is overridden in `bootstrap/app.php` because this API has no `login` route.
- Auth: Sanctum personal access tokens, 8h TTL (RG-19). Revocation on logout and session destroy (T-23).
- Privacy: student emails never exposed in member lists, partner candidates, unauthorized exports (T-22). Audit logs contain no PII.

## Public (no auth)
- `GET /api/auth/microsoft/redirect` — OAuth redirect (throttled). Returns redirect to Microsoft.
- `GET /api/auth/microsoft/callback` — OAuth callback. Redirects to frontend with `#token=<bearer>` (fragment). Routes: `/auth/microsoft/callback`, `/denied`, `/pending` (§17.6).
- `POST /api/auth/otp/request` — Request OTP (throttled 3/min). Domain restricted to `@ofppt-edu.ma` (RG-01). Response generic for known/unknown.
- `POST /api/auth/otp/verify` — Verify OTP (throttled 10/min). Issues token if valid within TTL (T-05/T-06).
- `POST /api/auth/dev/login` — Dev-only login (disabled if `APP_ENV=production` and `DEV_AUTH_ENABLED` false). Throttled.

## Internal (digest token)
- `POST /api/internal/daily-digest` — Header `X-Digest-Token`. Calls `EmailDigestService::sendDailyDigest()`. Returns `{ sent: int }`.
- `POST /api/internal/prune` — Header `X-Digest-Token`. Prunes OTP/expired tokens. Returns `{ deleted: int }`.

## Authenticated
Prefix middleware: `auth:sanctum`, `active`, `locale`.

### Profile
- `GET /api/me` — Current user (UserResource). No password/birth date.
- `PATCH /api/me` — Update profile (locale, display_name, avatar). Validation enforces FR/EN only (T-26).
- `DELETE /api/me/sessions` — Revoke **every** personal access token, **including the one used for this call**; the caller is therefore logged out. Returns 204. Audited as `auth.logout_all` with the number of tokens destroyed.
- `POST /api/auth/logout` — Revoke current token. Returns 204 (T-23).

### Classes & Memberships
- `GET /api/classes` — List classes (scope by role/membership).
- `POST /api/classes` — Create class (teacher/admin). Teacher ownership.
- `GET /api/classes/{classroom}` — Show class (policy: accepted member or owner/admin).
- `PATCH /api/classes/{classroom}` — Update (owner/admin).
- `POST /api/classes/{classroom}/archive` — Archive (owner/admin). Archived → read-only.
- `POST /api/classes/{classroom}/code/regenerate` — New join code (owner/admin).
- `POST /api/classes/{classroom}/code/toggle` — Enable/disable code (owner/admin) (T-10 behavior).
- `GET /api/classes/{classroom}/members` — Members. Student view: no emails (T-22). Teacher/admin: emails visible per policy.
- `DELETE /api/classes/{classroom}/members/{studentId}` — Remove member (owner/admin/teacher). Immediate access loss (T-14).
- `GET /api/classes/{classroom}/join-requests` — Pending requests (owner/teacher).
- `POST /api/classes/{classroom}/join-requests/accept-all` — Accept all pending (owner/teacher).
- `POST /api/classes/{classroom}/members/import` — CSV import (owner/teacher) — Could-have.

Join requests (student):
- `POST /api/join-requests` — Request via join code (role:student). Creates `pending`. Cooldown 24h after reject (RG-07, T-09). Max 1 pending per (student,class) (RG-06, T-08). Returns 409 if duplicate, 429 if cooldown.
- `GET /api/join-requests/mine` — Student’s requests.

Process requests:
- `POST /api/join-requests/{membership}/accept` — Accept (owner/teacher). Notifies student.
- `POST /api/join-requests/{membership}/reject` — Reject (owner/teacher). Sets cooldown.

### Materials & Announcements
- `GET /api/classes/{classroom}/materials` — List (accepted members). Returns metadata only (`has_file`, `file_name`, `mime_type`, `file_size`); no download URL is pre-signed in the listing.
- `POST /api/classes/{classroom}/materials` — Upload (teacher/owner/admin). MIME whitelist, size limits, .exe refused (T-21), private storage.
- `GET /api/materials/{material}/download` — Authenticated download. The file is streamed from private storage by this authenticated route; **no presigned URL and no Laravel temporary-signed URL is ever handed to the client** (see the advisory note in `docs/ASSUMPTIONS.md`). Policy: accepted member or owner/admin/teacher as appropriate. Audited.
- `PATCH /api/materials/{material}` — Update metadata.
- `DELETE /api/materials/{material}` — Delete.
- Announcements: CRUD under class, pinned ordering.

### Quizzes
- `GET /api/classes/{classroom}/quizzes` — List (scope by role). Drafts hidden from students (RG-13).
- `POST /api/classes/{classroom}/quizzes` — Create (teacher). Manual or AI source.
- `GET /api/quizzes/{quiz}` — Show. Student sees quiz without correct answers before submission (T-17, RG-13). Draft invisible to students.
- `PATCH /api/quizzes/{quiz}` — Update.
- `DELETE /api/quizzes/{quiz}` — Delete.
- `POST /api/quizzes/{quiz}/publish` — Publish. AI drafts require reviewed=true (cannot publish before review). Manual publishes immediately (if valid).
- `POST /api/quizzes/{quiz}/review` — Mark AI draft reviewed (teacher/owner). Cannot mark manual reviewed.
- Attempts (student): `POST /api/quizzes/{quiz}/attempts` (start), `POST /api/attempts/{attempt}/submit` (submit), `GET /api/attempts/{attempt}` (read own). Deadline handling (T-15), max attempts (T-16), resubmission blocked.
- Results/export (teacher/owner): `GET /api/quizzes/{quiz}/results`, `GET /api/quizzes/{quiz}/results/export` (CSV). No emails in export (T-22).

### AI
- `POST /api/classes/{classroom}/ai/generate` — Generate quiz from PDF (teacher). Throttled. Accepts PDF only (size/pages limits). Provider fallback on 429/5xx (T-18), clear error if all fail + manual fallback (T-19). Cache by document (T-20). Creates unreviewed draft. Daily quota per teacher enforced.
- `GET /api/ai/jobs/{aiJob}` — Job status (owner teacher).

### Assignments & Submissions
- `GET /api/classes/{classroom}/assignments` — List. Includes `my_submission` for requesting student (if any).
- `POST /api/classes/{classroom}/assignments` — Create (teacher).
- `GET /api/assignments/{assignment}` — Show (accepted member). Resource may include `my_submission` for current student only.
- `PATCH/DELETE /api/assignments/{assignment}` — Manage (teacher/owner).
- `POST /api/assignments/{assignment}/submissions` — Submit file (student). One submission per student (a second upload is refused). `is_late` and `is_overdue` are computed **server-side** against `due_at`; the client value is ignored. MIME whitelist, private storage.
- `GET /api/assignments/{assignment}/submissions` — List submissions (teacher/owner).
- `PATCH /api/submissions/{submission}` — Grade (teacher/owner): sets `grade`, `feedback`, `graded_at`, `graded_by`.
- `GET /api/submissions/{submission}/download` — Authenticated download (teacher/owner or student owner policy). Audited.

### Deadlines, Progress, Partners, Flashcards, Notifications
- `GET /api/me/deadlines` — Aggregated upcoming deadlines (assignments + quizzes) for student’s accepted classes.
- `GET /api/me/progress` (student), `GET /api/classes/{classroom}/progress` (teacher/owner).
- Partners: profile get/put (student), candidates, my requests, request, respond.
- Flashcards: `GET/POST /api/classes/{classroom}/flashcards`, `GET /api/flashcard-decks/{deck}`, `POST /api/flashcard-decks/{deck}/publish`, `POST /api/flashcard-decks/{deck}/reviewed`, `DELETE /api/flashcard-decks/{deck}`. Drafts hidden from students; policy enforces class access.
- Notifications: list, mark read, mark all read.

### Admin
Prefix `/api/admin`, middleware `role:admin`.
- Users: list, pending, update (promote/deactivate). Role changes audited, role_locked respected (RG-03).
- Classes: list, transfer, archive.
- AI providers: list, update, reset quota.
- Audit logs: list (immutable, no PII). T-28 coverage.
- Stats: dashboard counts.

## Resources (shapes)
Key resources (trimmed): `UserResource`, `ClassroomResource`, `MembershipResource`, `MaterialResource`, `AnnouncementResource`, `QuizResource`, `QuestionResource`, `OptionResource`, `AttemptResource`, `AssignmentResource` (includes `my_submission` when applicable), `SubmissionResource`, `FlashcardDeckResource`, `FlashcardResource`, `NotificationResource`, `AuditLogResource`, `AiJobResource`.

See `backend/app/Http/Resources/*` for exact fields.

## Errors (examples)
- 401 session_expired: `{ "message": "Session expirée.", "code": "session_expired" }` (localized)
- 422 validation: `{ "message": "Données invalides.", "errors": {...} }`
- 429 too_many_requests: `{ "message": "...", "retry_after": ... }` (headers may include Retry-After)
- 503 ai_unavailable: `{ "message": "...", "manual_fallback": true, "attempts": ... }`

## Throttles (configurable — `config/classlink.php`)

`otp_request: 3,1`, `otp_verify: 10,1`, `oauth_redirect: 30,1`, `ai_generate: 20,1`, `join_request: 10,1`.

Format `<tentatives>,<minutes>`. These use the plain `throttle` middleware, so the key is the **caller's IP address**, not the submitted e-mail — anti-abuse still holds when the address is unknown or forged. The per-teacher daily AI quota is enforced separately in the database (`ai_providers.used_today`, reset by `resetDailyQuotas()`).

---

*API surface matches delivered implementation. No undocumented endpoints introduced.*