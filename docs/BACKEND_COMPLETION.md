# Backend Completion Evidence

Date: 2026-10-03. Scope: backend application, routes, migrations, tests, and this new evidence file only. Shared dirty changes were preserved. No commits, external deployment, infrastructure/environment edits, or live external-service calls were made by this task.

## Sources

- Read the official 45-page `cahier_des_charges/ClassLink_Cahier_des_Charges_Professionnel.pdf` with the Read tool before edits, including sections 4-8, 11-12, 15-16 and 20-21.
- Read `docs/IMPLEMENTATION_AUDIT.md` through line 662, including phases 1.6-1.8, 3.1-3.9, 4.6 and 5.1.
- Read existing controllers, policies, services, models, resources and tests before their relevant edits. Existing audit/progress files are left to the parent.

## Implemented Behavior

| Scope | Implementation / evidence |
|---|---|
| 1.6 Archive | Active class listing defaults to active; explicit archived/all filters. Content creation, attempts, submissions, grading, flashcard review, partner requests, import and membership decisions refuse archived writes. Historical reads remain available to authorized owners/members. `manage` remains the ownership/read ability; `modify` and resource-specific policies gate writes. |
| 1.7 Policies | Notification ownership policy replaces a return-type-breaking inline JSON error. User updates, class transfer/archive, partner creation and AI generation invoke policies. Membership decisions invoke MembershipPolicy and retain service status checks. |
| 1.8 Expiration | `classlink:finalize-attempts`, every-minute schedule, and secret-gated internal endpoint grade saved answers without a logged-in student. Final grading locks the attempt and is idempotent. Added answer-save API for browser interruption recovery. |
| 3.1 Cache | Existing queued/processing/done jobs are returned before quota, PDF extraction/storage or dispatch. Reuse is scoped to teacher, classroom, target and file hash to avoid cross-class access. Failed jobs may be retried; completed jobs whose output was deleted are not reused. |
| 3.2 PDF | Parse uploaded temporary PDF before creating a job; invalid/unavailable extraction and excessive pages return 422. Store `page_count`; pass extracted course text to queued job, avoiding re-extraction for new uploads. |
| 3.3 Queue | Dispatch after transaction commit to explicit database connection, never afterResponse/sync. Claim queued status atomically; terminal jobs do not create duplicate drafts. Worker timeout failure marks the job failed. Legacy job extraction streams private storage to a temporary local file, including S3-compatible disks. |
| 3.4 Results | Five percentage buckets, including 100 in the final bucket. Unanswered questions count as misses. Current-member participation cannot exceed 100 because of removed members' historical results. |
| 3.5 Progress | First-to-last percentage-point trend, ordered series and insufficient-data state. Named inactive members contain only id/display_name; retain existing inactive IDs for the shipped client. |
| 3.6 Calendar | Nullable indexed `quizzes.due_at`, create/update validation, teacher/student quiz resources, DeadlineController/DeadlineResource and student role gate. No fabricated publication-date deadlines. Accepted active classes only; dates sorted and overdue calculated. New attempts after due_at return 409. |
| 3.7 CSV | Open a real CSV stream, validate unique email header, report column mismatch/invalid email/duplicate/inactive-or-missing student rows, import valid rows transactionally and audit import count. |
| 3.8 Digest | Atomic per-user daily reservation before transport prevents duplicate retries/concurrent sends. Failed/ambiguous sends are not counted and are deliberately not retried that calendar day. Active student/teacher/admin notifications are supported, plus accepted students' recent announcements. |
| 3.9 Preferences | Authenticated self GET/PUT preferences; strict known-type boolean validation. Per-type preference honored by in-app notifications and digest; global email_digest switch supported. |
| 4.6 Throttles | Microsoft callback uses OAuth limiter. Real middleware tests cover OTP request/verify, redirect/callback, development login, join requests and AI. |
| 5.1 Unit | Direct role, membership-state/cooldown and exact-set grading tests without database writes. Direct AI failover/cache test uses an isolated SQLite database for persisted provider configuration and fake HTTP. |

## Security And Integrity

- Closed unfinished-attempt answer leakage in `AttemptResultResource`: options/explanations are revealed only after submission AND when corrections are enabled. Fixed the old test that incorrectly expected disclosure before submitting.
- Removed members cannot read/submit old attempts or download their old submissions; records remain preserved.
- AI publication requires BOTH `reviewed` and `reviewed_at`, including flashcard decks. A bare true flag cannot certify review. Existing Phase 1.3 explicit review/content-edit behavior remains; a server attestation cannot prove human comprehension or complete editor reading.
- Serialized attempt allocation on the student row and locked answer saves/final grading. Save/submit transactions roll back partial invalid answer batches. Repeated question IDs and oversized batches are rejected.
- Time limit cannot be changed once attempts exist; quizzes with attempts cannot be deleted through the API.
- OTP delivery failure no longer logs plaintext codes. New OTP student accounts use a generic display name instead of the sensitive numeric email local part; OAuth missing-name fallback no longer exposes email.
- CSV exports neutralize formula-prefixed display names. Audit date filters validate input.

## Exact API Contracts

All paths below are prefixed with `/api`. Single objects are unwrapped; lists explicitly use `data`. Existing fields not listed remain unchanged.

| Method / endpoint | Request | Response / semantics |
|---|---|---|
| GET `/classes` | Optional `status=active\|archived\|all`; default active | `{data: Classroom[]}`; invalid status 422 |
| POST `/classes/{id}/quizzes`, PATCH `/quizzes/{id}` | Existing fields plus optional `due_at: ISO-date-string \| null` | Existing quiz object plus `due_at: ISO-date-string \| null`; GET teacher/student quiz resources also include it |
| GET `/me/deadlines` | Student bearer token | `{data:[{kind:"assignment"\|"quiz",id:number,title:string,classroom:string\|null,due_at:string,is_overdue:boolean}]}` sorted ascending; deadline-less quizzes excluded |
| GET `/quizzes/{id}/results` | Owner bearer token | Existing result plus `distribution:[{min:0,max:20,count:number},{min:20,max:40,count:number},{min:40,max:60,count:number},{min:60,max:80,count:number},{min:80,max:100,count:number}]`; buckets upper-exclusive except final upper-inclusive |
| GET `/me/progress` | Student bearer token | Existing totals/history plus `trend:{direction:"up"\|"down"\|"stable"\|"insufficient_data",delta_percentage_points:number\|null,series:[{submitted_at:string,percentage:number}]}` |
| GET `/classes/{id}/progress` | Owner bearer token | Existing fields plus `inactive_students:[{id:number,display_name:string}]`; inactivity means no submitted quiz attempt in that class, not a time-window heuristic |
| POST `/classes/{id}/members/import` | Multipart `file`, comma-separated CSV with unique `email` header, <=2MB | 201 `{imported:number,accepted:[{row:number,student_id:number,display_name:string}],errors:[{row:number,reason:"column_count"\|"invalid_email"\|"duplicate_email"\|"active_student_not_found"}]}`; row numbers include header as row 1; structural header errors 422 |
| POST `/classes/{id}/ai/generate` | Existing multipart `file`, optional `target:"quiz"\|"flashcard"` | Existing AiJob fields plus `cached:boolean`. New/pending job 202; completed reused job 200; page/extraction errors 422; uncached quota exhaustion 429 |
| PATCH `/attempts/{id}/answers` | `{answers:[{question_id:number,option_ids:number[]}]}` | 200 `{saved:number}`; no corrections. 403 wrong owner/removed/archived; 409 expired/submitted; 422 invalid batch. Empty answers is a valid no-op. |
| GET `/me/notification-preferences` | Authenticated bearer token | `{email_digest:boolean,types:{[known_type]:boolean}}`; unset defaults `{email_digest:true,types:{}}` |
| PUT `/me/notification-preferences` | `{email_digest:boolean,types:{[known_type]:boolean}}` (both fields required; full replacement) | Same object, 200; unknown keys or nonboolean values 422; unspecified known types remain enabled |
| POST `/internal/finalize-attempts` | `X-Digest-Token` matching existing configured digest secret; no student token | `{finalized:number}`; empty configuration 404, absent/wrong token 401 |

Known notification types: `join_requested`, `membership_accepted`, `membership_rejected`, `membership_removed`, `quiz_published`, `graded`, `partner_request_received`, `partner_request_answered`, `ai_job_finished`, `announcement_published`, `assignment_published`.

AI job response fields: `id`, `classroom_id`, `target`, `status`, `provider`, `original_name`, `page_count`, `error`, `quiz_id`, `deck_id`, `started_at`, `finished_at`, `created_at`, plus `cached` on generation responses only. GET `/ai/jobs/{id}` retains its existing shape.

## Files

New application files: `backend/app/Policies/AppNotificationPolicy.php`, `backend/app/Http/Controllers/DeadlineController.php`, `backend/app/Http/Resources/DeadlineResource.php`, `backend/app/Console/Commands/FinalizeExpiredAttemptsCommand.php`.

New migration: `backend/database/migrations/2026_10_03_000003_add_deadlines_and_notification_preferences.php` adds quiz due_at and user notification_preferences/last_digest_at. Existing question-order/review migrations were preserved.

New tests: `backend/tests/Feature/BackendCompletionTest.php`, `RateLimitTest.php`, `AiQueueDispatchTest.php`, `backend/tests/Unit/DomainRulesTest.php`, `AiFailoverTest.php`.

Edited controllers: AdminController, AiController, AuthController, ClassroomController, FlashcardController, NotificationController, PartnerController, QuizController, QuizResultController. Edited requests: StoreQuizRequest, SubmitAttemptRequest. Edited resources: AttemptResultResource, QuizResource, StudentQuizResource, QuizResultsResource. Edited models: Attempt, Quiz, User. Edited policies: Announcement, Assignment, Attempt, Classroom, FlashcardDeck, Material, Membership, PartnerRequest, Quiz, Submission. Edited services: EmailDigestService, MembershipService, NotificationService, OtpService, ProgressionService, QuizGradingService. Also ProcessAiGeneration, AppServiceProvider, routes/api.php, routes/console.php, existing AiTest/QuizTest. These files include preserved pre-existing shared changes, not all diff lines are owned by this task.

## Operational Handoff

- Apply migrations before serving new endpoints. The database jobs table is an existing migration.
- Run a database worker (`php artisan queue:work database --tries=1`) and Laravel scheduler (`php artisan schedule:work` or minute `schedule:run`). Alternatively call the secret-gated finalization endpoint regularly. A declared schedule is not evidence of a running production scheduler.
- Worker retry_after must exceed the job's 600-second timeout. Parent-owned current database queue config uses 960; this task did not edit it.
- PDF parser, AI provider keys, mail transport and private storage remain optional configurations. Without PDF extraction the AI endpoint returns 422/manual fallback; manual quiz/deck authoring remains independent.
- No live Microsoft/Brevo/S3/AI or production worker/scheduler validation was performed. SQLite tests do not prove PostgreSQL/MySQL concurrent-process locking behavior or production performance.

## Verification

Final full command from `backend`: `php vendor/phpunit/phpunit/phpunit --no-progress`.

Actual final result: **433 tests, 1,403 assertions, zero failures/errors, 20.515 seconds, 72 MB**. PHPUnit reports two deprecations from Laravel vendor database config using `PDO::MYSQL_ATTR_SSL_CA` under PHP 8.5.1. No deprecations were suppressed and vendor/config files were not edited by this task.

The real database-queue test proves the request creates a queued jobs-table record without inline generation, then a real `queue:work database --once` command produces the unreviewed draft. Its migration teardown also exposed and fixed the SQLite rollback requirement to drop the due_at index before its column. Existing Phase 1.3 review tests all pass, plus new bare-flag/no-timestamp refusal tests.

`git diff --check` passed; platform line-ending warnings are unrelated to correctness. These are backend test results only, not production certification or frontend completion evidence.
