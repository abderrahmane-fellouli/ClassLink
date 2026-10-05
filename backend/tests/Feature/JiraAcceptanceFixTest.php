<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\AuditLog;
use App\Models\Classroom;
use App\Models\Membership;
use App\Models\Quiz;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class JiraAcceptanceFixTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_announcements_are_pinned_then_newest_and_only_from_accepted_active_classes(): void
    {
        [$class, $teacher, $student] = $this->classWithMember();
        foreach ([['Old', false, -3], ['Pinned', true, -4], ['New', false, -1]] as [$title, $pinned, $days]) {
            Announcement::forceCreate(['classroom_id' => $class->id, 'author_id' => $teacher->id,
                'title' => $title, 'body' => 'Body', 'pinned' => $pinned, 'created_at' => now()->addDays($days)]);
        }
        $foreign = Classroom::factory()->create();
        Membership::create(['classroom_id' => $foreign->id, 'student_id' => $student->id, 'status' => 'pending', 'requested_at' => now()]);
        Announcement::create(['classroom_id' => $foreign->id, 'author_id' => $foreign->teacher_id, 'title' => 'Hidden', 'body' => 'Body']);
        $response = $this->actingAs($student)->getJson('/api/me/announcements')->assertOk()->assertJsonCount(3, 'data');
        $this->assertSame(['Pinned', 'New', 'Old'], array_column($response->json('data'), 'title'));
        $response->assertJsonPath('data.0.classroom.name', $class->name);
        $class->update(['status' => 'archived']);
        $this->getJson('/api/me/announcements')->assertJsonCount(0, 'data');
        $this->actingAs($teacher)->getJson('/api/me/announcements')->assertForbidden();
    }

    public function test_ai_review_and_publication_require_editor_content_served_to_the_current_reviewer(): void
    {
        [$class, $teacher, $student] = $this->classWithMember();
        $quiz = $this->publishedQuiz($class, $teacher);
        $quiz->update(['source' => 'ai', 'status' => 'draft', 'reviewed' => false, 'reviewed_at' => null]);
        $this->actingAs($teacher)->postJson("/api/quizzes/{$quiz->id}/review")->assertConflict()->assertJsonPath('context.requires_editor', true);
        $this->postJson("/api/quizzes/{$quiz->id}/publish")->assertConflict();
        // Ordinary show and client-supplied flags do not certify editor opening.
        $this->getJson("/api/quizzes/{$quiz->id}")->assertOk();
        $this->patchJson("/api/quizzes/{$quiz->id}", ['reviewed' => true, 'editor_opened_by' => $teacher->id])->assertOk();
        $this->assertNull($quiz->fresh()->editor_opened_at);
        $this->actingAs($student)->getJson("/api/quizzes/{$quiz->id}/editor")->assertForbidden();
        $this->actingAs($teacher)->getJson("/api/quizzes/{$quiz->id}/editor")->assertOk()->assertJsonCount(2, 'questions');
        $this->assertSame($teacher->id, $quiz->fresh()->editor_opened_by);
        $this->postJson("/api/quizzes/{$quiz->id}/review")->assertOk();
        $newOwner = $this->teacher();
        $class->update(['teacher_id' => $newOwner->id]);
        $this->actingAs($newOwner)->postJson("/api/quizzes/{$quiz->id}/publish")->assertConflict();
        $this->getJson("/api/quizzes/{$quiz->id}/editor")->assertOk();
        $this->assertFalse($quiz->fresh()->reviewed);
        $this->postJson("/api/quizzes/{$quiz->id}/review")->assertOk();
        $this->postJson("/api/quizzes/{$quiz->id}/publish")->assertOk();
    }

    public function test_upload_limit_is_exactly_ten_mib_with_a_boundary_rejection(): void
    {
        $this->fakeStorage();
        [$class, $teacher] = $this->classWithMember();
        $limit = 10 * 1024 * 1024;
        $this->assertSame(10240, config('classlink.files.max_kb'));
        $content = '%PDF-1.7'.str_repeat(' ', $limit - 8);
        $this->actingAs($teacher)->post("/api/classes/{$class->id}/materials", [
            'title' => 'Boundary', 'type' => 'file', 'file' => UploadedFile::fake()->createWithContent('course.pdf', $content),
        ])->assertCreated();
        $this->post("/api/classes/{$class->id}/materials", [
            'title' => 'Too large', 'type' => 'file', 'file' => UploadedFile::fake()->createWithContent('course.pdf', $content.' '),
        ])->assertStatus(422);
    }

    public function test_audit_category_user_and_inclusive_end_date_filters_match_real_events(): void
    {
        $admin = $this->admin();
        foreach ([['auth.login', '2026-10-01 00:00:00'], ['auth.logout', '2026-10-01 23:59:59'],
            ['auth.login', '2026-10-02 00:00:00'], ['quiz.publish', '2026-10-01 12:00:00']] as [$action, $date]) {
            $this->travelTo(Carbon::parse($date));
            AuditLog::record($admin, $action);
        }
        $response = $this->actingAs($admin)->getJson('/api/admin/audit-logs?action=auth&from=2026-10-01&to=2026-10-01&user_id='.$admin->id)->assertOk();
        $this->assertSame(['auth.logout', 'auth.login'], array_column($response->json('data'), 'action'));
        $this->getJson('/api/admin/audit-logs?action=auth.login&from=2026-10-01&to=2026-10-01')->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/audit-logs?action=auth%25')->assertUnprocessable();
        $this->actingAs($this->student())->getJson('/api/admin/audit-logs')->assertForbidden();
    }
}
