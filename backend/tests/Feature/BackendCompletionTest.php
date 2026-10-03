<?php

namespace Tests\Feature;

use App\Jobs\ProcessAiGeneration;
use App\Models\AiJob;
use App\Models\Announcement;
use App\Models\Assignment;
use App\Models\Attempt;
use App\Models\FlashcardDeck;
use App\Models\Membership;
use App\Services\EmailDigestService;
use App\Services\NotificationService;
use App\Services\QuizGradingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BackendCompletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_archive_leaves_active_list_but_remains_readable(): void
    {
        [$class, $teacher, $student] = $this->classWithMember();
        $this->actingAs($teacher)->postJson("/api/classes/{$class->id}/archive")->assertOk();
        foreach ([$teacher, $student] as $viewer) {
            $this->actingAs($viewer)->getJson('/api/classes')->assertJsonCount(0, 'data');
            $this->getJson('/api/classes?status=archived')->assertJsonCount(1, 'data');
            $this->getJson("/api/classes/{$class->id}")->assertOk();
        }
        $this->getJson('/api/classes?status=invalid')->assertUnprocessable();
    }

    public function test_archive_blocks_quiz_deck_ai_and_assignment_writes(): void
    {
        [$class, $teacher, $student] = $this->classWithMember();
        $quiz = $this->publishedQuiz($class, $teacher);
        $class->update(['status' => 'archived']);
        $this->actingAs($teacher)->postJson("/api/classes/{$class->id}/flashcards", [])->assertForbidden();
        $this->postJson("/api/classes/{$class->id}/quizzes", [])->assertForbidden();
        $this->postJson("/api/classes/{$class->id}/assignments", [])->assertForbidden();
        $this->postJson("/api/classes/{$class->id}/ai/generate", [])->assertForbidden();
        $this->postJson("/api/quizzes/{$quiz->id}/publish")->assertForbidden();
        $this->actingAs($student)->postJson("/api/quizzes/{$quiz->id}/attempts")->assertForbidden();
    }

    public function test_archive_blocks_existing_attempt_and_submission_writes(): void
    {
        [$class, $teacher, $student] = $this->classWithMember();
        $quiz = $this->publishedQuiz($class, $teacher);
        $attempt = $this->actingAs($student)->postJson("/api/quizzes/{$quiz->id}/attempts")->json('attempt_id');
        $assignment = Assignment::create(['classroom_id' => $class->id, 'created_by' => $teacher->id, 'title' => 'Task']);
        $class->update(['status' => 'archived']);
        $this->postJson("/api/attempts/{$attempt}/submit", ['answers' => []])->assertForbidden();
        $this->postJson("/api/assignments/{$assignment->id}/submissions", [])->assertForbidden();
    }

    public function test_archive_blocks_membership_decisions_and_import(): void
    {
        [$class, $teacher, $student] = $this->classWithMember();
        $membership = Membership::where('classroom_id', $class->id)->first();
        $membership->update(['status' => 'pending']);
        $class->update(['status' => 'archived']);
        $this->actingAs($teacher)->postJson("/api/join-requests/{$membership->id}/accept")->assertConflict();
        $this->postJson("/api/classes/{$class->id}/join-requests/accept-all")->assertForbidden();
        $this->postJson("/api/classes/{$class->id}/members/import", [])->assertForbidden();
    }

    public function test_notification_owner_policy_returns_403_not_type_error(): void
    {
        $owner = $this->student();
        $notification = app(NotificationService::class)->notify($owner, 'graded');
        $this->actingAs($this->student())->postJson("/api/notifications/{$notification->id}/read")->assertForbidden();
        $this->actingAs($owner)->postJson("/api/notifications/{$notification->id}/read")->assertNoContent();
    }

    public function test_unsubmitted_attempt_never_reveals_answers_or_explanations(): void
    {
        [$class, $teacher, $student] = $this->classWithMember();
        $quiz = $this->publishedQuiz($class, $teacher);
        $id = $this->actingAs($student)->postJson("/api/quizzes/{$quiz->id}/attempts")->json('attempt_id');
        $this->getJson("/api/attempts/{$id}")->assertOk()
            ->assertJsonPath('answers.0.options.0.is_correct', null)
            ->assertJsonPath('answers.0.explanation', null)->assertJsonPath('answers.0.is_correct', null);
    }

    public function test_removed_member_cannot_read_or_submit_old_attempt(): void
    {
        [$class, $teacher, $student] = $this->classWithMember();
        $quiz = $this->publishedQuiz($class, $teacher);
        $id = $this->actingAs($student)->postJson("/api/quizzes/{$quiz->id}/attempts")->json('attempt_id');
        Membership::where('classroom_id', $class->id)->update(['status' => 'removed']);
        $this->getJson("/api/attempts/{$id}")->assertForbidden();
        $this->postJson("/api/attempts/{$id}/submit", ['answers' => []])->assertForbidden();
        $this->assertDatabaseHas('attempts', ['id' => $id]);
    }

    public function test_scheduled_finalization_works_without_student_auth_and_is_idempotent(): void
    {
        [$class, $teacher, $student] = $this->classWithMember();
        $quiz = $this->publishedQuiz($class, $teacher);
        $attempt = Attempt::create(['quiz_id' => $quiz->id, 'student_id' => $student->id,
            'attempt_no' => 1, 'started_at' => now()->subMinutes(11), 'max_score' => 2]);
        $question = $quiz->questions->first();
        $attempt->answers()->create(['question_id' => $question->id,
            'selected_option_ids' => [$question->options->first()->id]]);
        $this->assertSame(1, app(QuizGradingService::class)->finalizeExpired());
        $this->assertSame(0, app(QuizGradingService::class)->finalizeExpired());
        $this->assertTrue($attempt->fresh()->expired);
        $this->assertSame(1.0, $attempt->fresh()->score);
        $this->artisan('classlink:finalize-attempts')->assertSuccessful();
    }

    public function test_internal_finalization_is_fail_closed(): void
    {
        config(['classlink.digest_token' => '']);
        $this->postJson('/api/internal/finalize-attempts')->assertNotFound();
        config(['classlink.digest_token' => 'test-secret']);
        $this->postJson('/api/internal/finalize-attempts')->assertUnauthorized();
        $this->withHeader('X-Digest-Token', 'test-secret')->postJson('/api/internal/finalize-attempts')
            ->assertOk()->assertJsonPath('finalized', 0);
    }

    public function test_ai_flag_without_timestamp_is_not_review_proof(): void
    {
        [$class, $teacher] = $this->classWithMember();
        $quiz = $this->publishedQuiz($class, $teacher);
        $quiz->update(['status' => 'draft', 'source' => 'ai', 'reviewed' => true, 'reviewed_at' => null]);
        $this->actingAs($teacher)->postJson("/api/quizzes/{$quiz->id}/publish")->assertConflict();
        $deck = FlashcardDeck::create(['classroom_id' => $class->id, 'title' => 'AI', 'source' => 'ai', 'reviewed' => true]);
        $this->postJson("/api/flashcard-decks/{$deck->id}/publish")->assertConflict();
    }

    public function test_ai_repeat_short_circuits_extraction_storage_queue_and_teacher_quota(): void
    {
        Queue::fake();
        $this->fakeStorage();
        $this->fakePdfText('Course', 3);
        [$class, $teacher] = $this->classWithMember();
        config(['classlink.ai.daily_quota_per_teacher' => 1]);
        $first = $this->actingAs($teacher)->post("/api/classes/{$class->id}/ai/generate", ['file' => $this->fakeUpload('course.pdf')])
            ->assertStatus(202)->assertJsonPath('page_count', 3)->json('id');
        $this->post("/api/classes/{$class->id}/ai/generate", ['file' => $this->fakeUpload('course.pdf')])
            ->assertStatus(202)->assertJsonPath('cached', true)->assertJsonPath('id', $first);
        $this->assertSame(1, AiJob::count());
        Queue::assertPushed(ProcessAiGeneration::class, 1);
        Queue::assertPushed(ProcessAiGeneration::class, fn ($job) => $job->connection === 'database' && $job->extractedText === 'Course');
    }

    public function test_ai_completed_repeat_returns_existing_draft(): void
    {
        Queue::fake();
        $this->fakeStorage();
        $this->fakePdfText();
        [$class, $teacher] = $this->classWithMember();
        $quiz = $this->publishedQuiz($class, $teacher);
        $file = $this->fakeUpload('course.pdf');
        $job = AiJob::create(['teacher_id' => $teacher->id, 'classroom_id' => $class->id,
            'file_hash' => hash_file('sha256', $file->getRealPath()), 'target' => 'quiz',
            'status' => 'done', 'quiz_id' => $quiz->id, 'original_name' => 'course.pdf', 'file_path' => 'x']);
        config(['classlink.ai.daily_quota_per_teacher' => 0]);
        $this->actingAs($teacher)->post("/api/classes/{$class->id}/ai/generate", ['file' => $file])
            ->assertOk()->assertJsonPath('id', $job->id)->assertJsonPath('cached', true);
        Queue::assertNothingPushed();
    }

    public function test_ai_overlong_pdf_is_rejected_before_job_and_storage(): void
    {
        Queue::fake();
        $this->fakeStorage();
        $this->fakePdfText('Course', 31);
        [$class, $teacher] = $this->classWithMember();
        $this->actingAs($teacher)->post("/api/classes/{$class->id}/ai/generate", ['file' => $this->fakeUpload('course.pdf')])
            ->assertUnprocessable();
        $this->assertSame(0, AiJob::count());
        Queue::assertNothingPushed();
    }

    public function test_terminal_ai_job_is_not_reprocessed(): void
    {
        [$class, $teacher] = $this->classWithMember();
        $job = AiJob::create(['teacher_id' => $teacher->id, 'classroom_id' => $class->id,
            'target' => 'quiz', 'file_hash' => str_repeat('a', 64), 'status' => 'done', 'original_name' => 'x', 'file_path' => 'x']);
        Http::fake();
        $this->runJob(new ProcessAiGeneration($job->id));
        $this->assertSame('done', $job->fresh()->status->value);
        Http::assertNothingSent();
    }

    public function test_real_deadlines_are_sorted_and_drafts_and_unrelated_classes_excluded(): void
    {
        [$class, $teacher, $student] = $this->classWithMember();
        $quiz = $this->publishedQuiz($class, $teacher);
        $quiz->update(['due_at' => now()->addDay()]);
        Assignment::create(['classroom_id' => $class->id, 'created_by' => $teacher->id,
            'title' => 'Overdue', 'due_at' => now()->subDay()]);
        $this->actingAs($student)->getJson('/api/me/deadlines')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.kind', 'assignment')->assertJsonPath('data.0.is_overdue', true)
            ->assertJsonPath('data.1.kind', 'quiz')->assertJsonPath('data.1.is_overdue', false);
        $quiz->update(['status' => 'draft']);
        $this->getJson('/api/me/deadlines')->assertJsonCount(1, 'data');
        Membership::where('classroom_id', $class->id)->update(['status' => 'removed']);
        $this->getJson('/api/me/deadlines')->assertJsonCount(0, 'data');
    }

    public function test_quiz_deadline_can_be_updated_and_blocks_new_attempts(): void
    {
        [$class, $teacher, $student] = $this->classWithMember();
        $quiz = $this->publishedQuiz($class, $teacher);
        $this->actingAs($teacher)->patchJson("/api/quizzes/{$quiz->id}", ['due_at' => now()->subHour()->toIso8601String()])
            ->assertOk();
        $this->actingAs($student)->postJson("/api/quizzes/{$quiz->id}/attempts")->assertConflict();
    }

    public function test_results_distribution_and_unanswered_questions(): void
    {
        [$class, $teacher, $student] = $this->classWithMember();
        $quiz = $this->publishedQuiz($class, $teacher);
        Attempt::create(['quiz_id' => $quiz->id, 'student_id' => $student->id, 'attempt_no' => 1,
            'started_at' => now(), 'submitted_at' => now(), 'score' => 2, 'max_score' => 2]);
        $this->actingAs($teacher)->getJson("/api/quizzes/{$quiz->id}/results")->assertOk()
            ->assertJsonCount(5, 'distribution')->assertJsonPath('distribution.4.count', 1)
            ->assertJsonPath('distribution.0.count', 0)->assertJsonPath('most_missed.0.misses', 1);
    }

    public function test_progress_has_trend_and_named_inactive_members_without_email(): void
    {
        [$class, $teacher, $student] = $this->classWithMember();
        $quiz = $this->publishedQuiz($class, $teacher);
        $this->actingAs($student)->getJson('/api/me/progress')->assertJsonPath('trend.direction', 'insufficient_data');
        foreach ([0, 2] as $i => $score) {
            Attempt::create(['quiz_id' => $quiz->id, 'student_id' => $student->id, 'attempt_no' => $i + 1,
                'started_at' => now(), 'submitted_at' => now()->addMinutes($i), 'score' => $score, 'max_score' => 2]);
        }
        $inactive = $this->student(['display_name' => 'Inactive Student']);
        Membership::create(['classroom_id' => $class->id, 'student_id' => $inactive->id, 'status' => 'accepted', 'requested_at' => now()]);
        $this->getJson('/api/me/progress')->assertJsonPath('trend.direction', 'up')
            ->assertJsonPath('trend.delta_percentage_points', 100)->assertJsonCount(2, 'trend.series');
        $response = $this->actingAs($teacher)->getJson("/api/classes/{$class->id}/progress")->assertOk()
            ->assertJsonPath('inactive_students.0.display_name', 'Inactive Student');
        $this->assertArrayNotHasKey('email', $response->json('inactive_students.0'));
    }

    public function test_csv_reports_bad_rows_and_imports_valid_student(): void
    {
        [$class, $teacher, $student] = $this->classWithMember();
        $csv = "email,name\n{$student->email},Student\nwrong,Invalid\n{$student->email},Duplicate\nextra,column,value\n";
        $this->actingAs($teacher)->post("/api/classes/{$class->id}/members/import", [
            'file' => UploadedFile::fake()->createWithContent('roster.csv', $csv),
        ])->assertCreated()->assertJsonPath('imported', 1)->assertJsonCount(3, 'errors')
            ->assertJsonPath('accepted.0.row', 2);
    }

    public function test_preferences_are_validated_and_honored(): void
    {
        $student = $this->student();
        $this->actingAs($student)->getJson('/api/me/notification-preferences')->assertJsonPath('email_digest', true);
        $this->putJson('/api/me/notification-preferences', ['email_digest' => false, 'types' => ['graded' => false]])
            ->assertOk();
        $this->assertNull(app(NotificationService::class)->notify($student->fresh(), 'graded'));
        $this->putJson('/api/me/notification-preferences', ['email_digest' => true, 'types' => ['unknown' => true]])
            ->assertUnprocessable();
        $this->assertFalse($student->fresh()->notification_preferences['email_digest']);
    }

    public function test_digest_sends_at_most_once_a_day(): void
    {
        Mail::fake();
        [$class, $teacher, $student] = $this->classWithMember();
        Announcement::create(['classroom_id' => $class->id, 'author_id' => $teacher->id, 'title' => 'News', 'body' => 'Body']);
        $service = app(EmailDigestService::class);
        $this->assertSame(1, $service->sendDailyDigest());
        $this->assertSame(0, $service->sendDailyDigest());
        $this->assertTrue($student->fresh()->last_digest_at->isToday());
    }

    public function test_digest_respects_preferences(): void
    {
        Mail::fake();
        [$class, $teacher, $student] = $this->classWithMember();
        Announcement::create(['classroom_id' => $class->id, 'author_id' => $teacher->id, 'title' => 'News', 'body' => 'Body']);
        $student->forceFill(['notification_preferences' => ['email_digest' => false]])->save();
        $this->assertSame(0, app(EmailDigestService::class)->sendDailyDigest());
        $this->assertNull($student->fresh()->last_digest_at);
    }

    public function test_saved_answers_are_finalized_after_browser_disappears(): void
    {
        [$class, $teacher, $student] = $this->classWithMember();
        $quiz = $this->publishedQuiz($class, $teacher);
        $id = $this->actingAs($student)->postJson("/api/quizzes/{$quiz->id}/attempts")->json('attempt_id');
        $question = $quiz->questions->first();
        $this->patchJson("/api/attempts/{$id}/answers", ['answers' => [[
            'question_id' => $question->id, 'option_ids' => [$question->options->first()->id],
        ]]])->assertOk()->assertJsonPath('saved', 1);
        $this->travel(11)->minutes();
        $this->artisan('classlink:finalize-attempts')->assertSuccessful();
        $this->assertSame(1.0, Attempt::findOrFail($id)->score);
        $this->patchJson("/api/attempts/{$id}/answers", ['answers' => []])->assertConflict();
    }

    public function test_answer_save_rolls_back_all_rows_if_any_question_is_invalid(): void
    {
        [$class, $teacher, $student] = $this->classWithMember();
        $quiz = $this->publishedQuiz($class, $teacher);
        $id = $this->actingAs($student)->postJson("/api/quizzes/{$quiz->id}/attempts")->json('attempt_id');
        $this->patchJson("/api/attempts/{$id}/answers", ['answers' => [
            ['question_id' => $quiz->questions->first()->id, 'option_ids' => []],
            ['question_id' => 999999, 'option_ids' => []],
        ]])->assertUnprocessable();
        $this->assertDatabaseCount('attempt_answers', 0);
    }

    public function test_expired_save_does_not_accept_new_answers(): void
    {
        [$class, $teacher, $student] = $this->classWithMember();
        $quiz = $this->publishedQuiz($class, $teacher);
        $id = $this->actingAs($student)->postJson("/api/quizzes/{$quiz->id}/attempts")->json('attempt_id');
        $this->travel(11)->minutes();
        $this->patchJson("/api/attempts/{$id}/answers", ['answers' => [[
            'question_id' => $quiz->questions->first()->id, 'option_ids' => [],
        ]]])->assertConflict();
        $this->assertDatabaseCount('attempt_answers', 0);
    }

    public function test_archived_resources_remain_readable_by_teacher(): void
    {
        [$class, $teacher] = $this->classWithMember();
        $deck = FlashcardDeck::create(['classroom_id' => $class->id, 'title' => 'Draft', 'source' => 'manual', 'status' => 'draft']);
        $class->update(['status' => 'archived']);
        $this->actingAs($teacher)->getJson("/api/flashcard-decks/{$deck->id}")->assertOk();
        $this->postJson("/api/flashcard-decks/{$deck->id}/publish")->assertForbidden();
    }

    public function test_ai_cache_does_not_cross_class_or_target_boundaries(): void
    {
        Queue::fake();
        $this->fakeStorage();
        $this->fakePdfText();
        [$class, $teacher] = $this->classWithMember();
        [$second] = $this->classWithMember($teacher);
        foreach ([[$class, 'quiz'], [$class, 'flashcard'], [$second, 'quiz']] as [$room, $target]) {
            $this->actingAs($teacher)->post("/api/classes/{$room->id}/ai/generate", [
                'file' => $this->fakeUpload('course.pdf'), 'target' => $target,
            ])->assertStatus(202)->assertJsonPath('cached', false);
        }
        $this->assertSame(3, AiJob::count());
    }

    public function test_ai_extractor_unavailable_returns_422_with_manual_fallback(): void
    {
        [$class, $teacher] = $this->classWithMember();
        $this->app->instance(\App\Contracts\PdfTextExtractor::class, new class implements \App\Contracts\PdfTextExtractor {
            public function extract(string $absolutePath): array
            {
                throw new \App\Exceptions\PdfExtractionException('Unavailable');
            }
        });
        $this->actingAs($teacher)->post("/api/classes/{$class->id}/ai/generate", ['file' => $this->fakeUpload('course.pdf')])
            ->assertUnprocessable()->assertJsonPath('context.manual_fallback', true);
        $this->assertSame(0, AiJob::count());
    }

    public function test_ai_worker_timeout_sets_terminal_failure(): void
    {
        [$class, $teacher] = $this->classWithMember();
        $job = AiJob::create(['teacher_id' => $teacher->id, 'classroom_id' => $class->id,
            'target' => 'quiz', 'file_hash' => str_repeat('b', 64), 'status' => 'processing', 'original_name' => 'x', 'file_path' => 'x']);
        (new ProcessAiGeneration($job->id))->failed(new \RuntimeException('timeout'));
        $this->assertSame('failed', $job->fresh()->status->value);
        $this->assertNotNull($job->fresh()->finished_at);
    }

    public function test_digest_covers_teacher_notifications_and_next_day(): void
    {
        Mail::fake();
        $teacher = $this->teacher();
        app(NotificationService::class)->notify($teacher, NotificationService::JOIN_REQUESTED, ['classroom_name' => 'Class']);
        $service = app(EmailDigestService::class);
        $this->assertSame(1, $service->sendDailyDigest());
        $this->assertSame(0, $service->sendDailyDigest());
        $this->travel(1)->days();
        app(NotificationService::class)->notify($teacher->fresh(), NotificationService::JOIN_REQUESTED);
        $this->assertSame(1, $service->sendDailyDigest());
    }

    public function test_digest_mail_failure_is_not_counted_or_retried_today(): void
    {
        $teacher = $this->teacher();
        app(NotificationService::class)->notify($teacher, NotificationService::JOIN_REQUESTED);
        Mail::shouldReceive('raw')->once()->andThrow(new \RuntimeException('ambiguous transport failure'));
        $service = app(EmailDigestService::class);
        $this->assertSame(0, $service->sendDailyDigest());
        $this->assertSame(0, $service->sendDailyDigest());
    }

    public function test_csv_missing_header_and_inactive_students_are_reported(): void
    {
        [$class, $teacher] = $this->classWithMember();
        $inactive = $this->student(['is_active' => false]);
        $this->actingAs($teacher)->post("/api/classes/{$class->id}/members/import", [
            'file' => UploadedFile::fake()->createWithContent('roster.csv', "name\nName\n"),
        ])->assertUnprocessable();
        $this->post("/api/classes/{$class->id}/members/import", [
            'file' => UploadedFile::fake()->createWithContent('roster.csv', "email\n{$inactive->email}\n"),
        ])->assertCreated()->assertJsonPath('imported', 0)->assertJsonPath('errors.0.reason', 'active_student_not_found');
    }

    public function test_csv_export_neutralizes_formula_names(): void
    {
        [$class, $teacher, $student] = $this->classWithMember();
        $student->update(['display_name' => '=HYPERLINK("https://example.test")']);
        $quiz = $this->publishedQuiz($class, $teacher);
        Attempt::create(['quiz_id' => $quiz->id, 'student_id' => $student->id, 'attempt_no' => 1,
            'started_at' => now(), 'submitted_at' => now(), 'score' => 0, 'max_score' => 2]);
        $csv = $this->actingAs($teacher)->get("/api/quizzes/{$quiz->id}/results/export")->assertOk()->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
    }

    public function test_audit_date_filters_reject_invalid_dates(): void
    {
        $this->actingAs($this->admin())->getJson('/api/admin/audit-logs?from=not-a-date')->assertUnprocessable();
    }

    public function test_time_limit_and_result_history_are_preserved_once_attempts_exist(): void
    {
        [$class, $teacher, $student] = $this->classWithMember();
        $quiz = $this->publishedQuiz($class, $teacher);
        $this->actingAs($student)->postJson("/api/quizzes/{$quiz->id}/attempts")->assertCreated();
        $this->actingAs($teacher)->patchJson("/api/quizzes/{$quiz->id}", ['time_limit_min' => 60])->assertConflict();
        $this->deleteJson("/api/quizzes/{$quiz->id}")->assertForbidden();
        $this->assertSame(10, $quiz->fresh()->time_limit_min);
        $this->assertDatabaseCount('attempts', 1);
    }
}
