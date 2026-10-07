# API Reference — ClassLink 1.0

## Institutional extension and superseded class ownership

The new authenticated routes are defined in `backend/routes/school.php` and use
the same bearer/active-account/locale boundary. `POST /api/classes` is now
admin-only official group creation (year/code/name/filière/level); teachers use
assignment/setup requests and cannot self-authorize by creating a class copy.
Existing record IDs and legacy workflows remain during reviewed reconciliation.
See `docs/SCHOOL_MODEL_HANDOFF.md` for permissions, privacy and migration limits.

Key routes:

| Workflow | Routes |
|---|---|
| Workspace / setup | `GET /school`, `POST /school/years`, `/school/groups`, `/school/modules`, `/school/groups/{group}/offerings` |
| Teacher assignments | `POST /school/offerings/{offering}/teachers`, `DELETE /school/teaching-assignments/{assignment}` |
| Missing assignments | `/school/assignment-requests`, `POST /school/setup-requests`, `/school/assignment-requests/{assignment}/resolve` |
| Coordinator / admissions | `PUT /school/groups/{group}/coordinator`, `/roster`, `/requests`, `/enrollments`, `/school/memberships/{membership}/decision` |
| Roster preview / commit | `/school/groups/{group}/roster-imports/preview`, `/commit` (stable identifier/email conflicts fail closed) |
| Transfer / delegates | `/school/groups/{group}/enrollments/{student}/transfer`, `/school/groups/{group}/delegates`, `DELETE /school/delegates/{delegate}` |
| Existing teaching tools | `/school/offerings/{offering}/tools/{kind}` and bound-record actions; kinds materials/announcements/assignments/quizzes/flashcards |
| Official assessments | `/school/offerings/{offering}/assessments`, `/school/assessments/{assessment}` |
| Grades | `/draft`, `/template?format=xlsx\|csv`, `/imports/preview`, `/imports/commit`, `/publish`, `/correction`, `/roster-reconcile` under the assessment |
| Personal results | `/school/my-grades`, `/export`, `/{assessment}` and `/{assessment}/contacts`; always current user's published results |
| Private communication | `/school/threads`, `/{thread}`, `/{thread}/messages`, `/{thread}/resolve` |
| Audience announcements | `/school/notices/preview` then `/publish`; audience changes require another preview |
| Private reports | `/school/support-contacts`, `/school/threads/{thread}/report`, `/school/reports`; assigned admin sees only reporter summary, not hidden message history |

All routes above are under `/api`. Write versions are optimistic integers;
imports use actor-bound UUID batches with expiry. 409 means reload/reconcile,
422 blocks the entire commit, 410 requires new preview. Private grades/messages
use `Cache-Control: private, no-store`. No endpoint automatically computes or
certifies institutional averages, grants admin grading rights, or sends real
messages during local verification.

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
  Authenticated Graph identity must have an exact OFPPT UPN and immutable object
  ID under the configured school tenant GUID. Non-numeric addresses are pending
  candidates, not automatic teachers. The durable identity is tenant + object ID.
  The handshake requires the one-time `state` issued by the redirect route and
  matching HttpOnly OAuth nonce cookie (10 minutes). API authentication remains
  bearer-only. Missing/replayed state or provider failure redirects to the
  callback with a non-sensitive `#error=...`, never an access token.
- `POST /api/auth/microsoft/pending-verification` — anonymous, throttled, body
  `{verification:"<single-use receipt>"}`. Returns `{verification_source:"microsoft",
  role_candidate:"teacher"|"student",status:"pending"|"approved"|"denied"}` only
  for a valid 10-minute receipt issued after OAuth. No app token/provider IDs.
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
- The maximum file size is **10 MiB = 10,485,760 bytes = 10,240 KiB**. This is the
  resolved SCRUM-39 limit; files larger by even one byte are rejected. PDF and
  allowed Office documents plus links remain supported.
- `GET /api/me/announcements` — student-only pinned/newest feed from accepted,
  active classes, with safe class/author context; pending/removed/other classes excluded.
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
- `GET /api/quizzes/{quiz}/attempts/active`: `{attempt:null}` or server-frozen questions,
  remaining seconds and saved `answers` indexed by question ID. No corrections.
- `PATCH /api/attempts/{attempt}/answers`: `{answers:[{question_id,option_ids:[]}]}`;
  persists without submitting, rejects late/unauthorized writes.
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
- Deck/card editing: `PATCH /api/flashcard-decks/{deck}` (`title`),
  `PATCH /api/flashcard-decks/{deck}/cards/{card}` (`front`, `back`),
  `DELETE /api/flashcard-decks/{deck}/cards/{card}`. A real card edit certifies
  review; a no-op does not. Card PATCH returns `{data:{id,front,back,position,deck_reviewed}}`.
- Notifications: list, mark read, mark all read.

### Admin
Prefix `/api/admin`, middleware `role:admin`.
- Users: list, pending, update (promote/deactivate). Role changes audited, role_locked respected (RG-03).
  Pending/users lists include safe `role_candidate` and `verification_source`;
  teacher approval via `{role:"teacher"}` locks the authorized role. Rejection
  via `{is_active:false}` is audited. Ordinary self-profile updates cannot set
  roles, candidate metadata or Microsoft verification fields.
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
- 507 storage_capacity_reached: the total stored volume reached
  `CLASSLINK_STORAGE_LIMIT_BYTES` (9 GiB by default). Returned **before** any write
  and before any record is created, so no file and no row is left behind:

```json
{
  "message": "Espace de stockage atteint (9 Gio). Les envois de fichiers sont temporairement suspendus ; espacez un envoi existant ou réessayez plus tard.",
  "context": {
    "reason": "storage_capacity_reached",
    "kind": "material",
    "limit_bytes": 9663676416,
    "used_bytes": 9663510520,
    "requested_bytes": 4096,
    "remaining_bytes": 0
  }
}
```

  `message` follows the caller's locale (FR/EN, from the user profile). Applies to
  `POST /api/classes/{classroom}/materials` (file type only),
  `POST /api/assignments/{assignment}/submissions` and
  `POST /api/classes/{classroom}/ai/generate`. Link materials write nothing to
  storage and are unaffected. See `docs/DEPLOYMENT.md` § "Storage Ceiling" for the
  accounting and consistency guarantees.

## Throttles (configurable — `config/classlink.php`)

`otp_request: 3,1`, `otp_verify: 10,1`, `oauth_redirect: 30,1`, `ai_generate: 20,1`, `join_request: 10,1`.

Format `<tentatives>,<minutes>`. These use the plain `throttle` middleware, so the key is the **caller's IP address**, not the submitted e-mail — anti-abuse still holds when the address is unknown or forged. The per-teacher daily AI quota is enforced separately in the database (`ai_providers.used_today`, reset by `resetDailyQuotas()`).

---

*API surface matches delivered implementation. No undocumented endpoints introduced.*
