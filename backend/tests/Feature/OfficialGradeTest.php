<?php

namespace Tests\Feature;

use App\Jobs\DeliverSchoolNotifications;
use App\Services\GradeSpreadsheet;
use App\Services\SchoolSetupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\SchoolFixture;
use Tests\TestCase;

class OfficialGradeTest extends TestCase
{
    use RefreshDatabase, SchoolFixture;

    private function assessment($teacher, int $offering): int
    {
        return $this->asToken($this->tokenFor($teacher))->postJson("/api/school/offerings/$offering/assessments", [
            'title' => 'Synthetic exam', 'type' => 'exam', 'assessed_on' => now()->toDateString(), 'maximum_score' => 20, 'coefficient' => 1,
        ])->assertCreated()->json('id');
    }

    public function test_grade_drafts_publication_corrections_and_notification_retries_preserve_privacy(): void
    {
        [$admin, $group, $teachers, $offerings, $students] = $this->schoolFixture();
        $id = $this->assessment($teachers[0], $offerings[0]);
        $rows = [['student_id' => $students[0]->id, 'score' => '16', 'status' => 'graded', 'feedback' => 'Private feedback'], ['student_id' => $students[1]->id, 'score' => null, 'status' => 'absent']];
        $this->putJson("/api/school/assessments/$id/draft", ['version' => 1, 'rows' => $rows])->assertOk()->assertJsonPath('version', 2);
        foreach ($students as $student) {
            $this->asToken($this->tokenFor($student))->getJson('/api/school/my-grades')->assertOk()->assertJsonCount(0, 'data');
            $this->getJson("/api/school/assessments/$id")->assertForbidden();
        }
        $this->asToken($this->tokenFor($teachers[0]))->postJson("/api/school/assessments/$id/publish", ['version' => 2, 'summary' => 'Synthetic publication'])->assertOk()->assertJsonPath('version', 3);
        $this->postJson("/api/school/assessments/$id/publish", ['version' => 2, 'summary' => 'Synthetic publication'])->assertConflict();
        $this->asToken($this->tokenFor($students[0]))->getJson('/api/school/my-grades')->assertJsonCount(1, 'data')->assertJsonPath('data.0.feedback', 'Private feedback');
        $this->asToken($this->tokenFor($students[1]))->getJson('/api/school/my-grades')->assertJsonPath('data.0.status', 'absent')->assertJsonPath('data.0.score', null)->assertDontSee('Private feedback');
        $this->asToken($this->tokenFor($teachers[0]))->postJson("/api/school/assessments/$id/correction", ['version' => 3, 'reason' => 'Synthetic correction reason'])->assertOk();
        $rows[0]['score'] = '17';
        $this->putJson("/api/school/assessments/$id/draft", ['version' => 4, 'rows' => $rows])->assertOk();
        $response = $this->asToken($this->tokenFor($students[0]))->getJson('/api/school/my-grades')->assertOk();
        $this->assertEquals(16, $response->json('data.0.score'));
        $this->asToken($this->tokenFor($teachers[0]))->postJson("/api/school/assessments/$id/publish", ['version' => 5, 'summary' => 'Republished correction'])->assertOk();
        $response = $this->asToken($this->tokenFor($students[0]))->getJson('/api/school/my-grades')->assertJsonPath('data.0.sequence', 2);
        $this->assertEquals(17, $response->json('data.0.score'));
        (new DeliverSchoolNotifications)->handle();
        (new DeliverSchoolNotifications)->handle();
        $this->assertSame(4, DB::table('notifications')->where('type', 'official_grade_published')->count());
        $this->assertStringNotContainsString('Private feedback', DB::table('notifications')->pluck('payload')->implode(''));
        $this->assertEquals(16, DB::table('grade_entries')->orderBy('id')->value('score'));
        $this->asToken($this->tokenFor($admin))->getJson("/api/school/assessments/$id/template?format=csv")->assertForbidden();
        $this->asToken($this->tokenFor($teachers[1]))->getJson("/api/school/assessments/$id")->assertForbidden();
    }

    public function test_csv_import_matches_ids_not_duplicate_names_or_row_order_and_is_idempotent(): void
    {
        [, , $teachers, $offerings, $students] = $this->schoolFixture();
        $id = $this->assessment($teachers[0], $offerings[0]);
        $matrix = app(GradeSpreadsheet::class)->matrix(DB::table('official_assessments')->find($id));
        $matrix[1][8] = '0';
        $matrix[1][9] = 'graded';
        $matrix[2][8] = '15,25';
        $matrix[2][9] = 'graded';
        [$matrix[1], $matrix[2]] = [$matrix[2], $matrix[1]];
        $csv = app(GradeSpreadsheet::class)->export($matrix, 'csv', 'Synthetic');
        $preview = $this->postJson("/api/school/assessments/$id/imports/preview", ['file' => UploadedFile::fake()->createWithContent('notes.csv', $csv)])->assertOk()->assertJsonCount(0, 'errors');
        $batch = $preview->json('batch_id');
        $this->postJson("/api/school/assessments/$id/imports/commit", ['batch_id' => $batch])->assertOk()->assertJsonPath('version', 2);
        $this->postJson("/api/school/assessments/$id/imports/commit", ['batch_id' => $batch])->assertOk()->assertJsonPath('version', 2);
        $this->assertEquals(0, DB::table('grade_entries')->where('student_id', $students[0]->id)->value('score'));
        $this->assertEquals(15.25, DB::table('grade_entries')->where('student_id', $students[1]->id)->value('score'));
        $this->assertDatabaseCount('grade_entries', 2);
    }

    public function test_wrong_context_duplicate_ids_and_out_of_range_scores_never_partially_commit(): void
    {
        [, , $teachers, $offerings] = $this->schoolFixture();
        $id = $this->assessment($teachers[0], $offerings[0]);
        $matrix = app(GradeSpreadsheet::class)->matrix(DB::table('official_assessments')->find($id));
        $matrix[1][1] = 999999;
        $matrix[1][8] = '21';
        $matrix[1][9] = 'graded';
        $matrix[2][6] = $matrix[1][6];
        $csv = app(GradeSpreadsheet::class)->export($matrix, 'csv', 'Synthetic');
        $preview = $this->postJson("/api/school/assessments/$id/imports/preview", ['file' => UploadedFile::fake()->createWithContent('notes.csv', $csv)])->assertOk();
        $this->assertNotEmpty($preview->json('errors'));
        $this->postJson("/api/school/assessments/$id/imports/commit", ['batch_id' => $preview->json('batch_id')])->assertUnprocessable();
        $this->assertSame(0, DB::table('grade_entries')->whereNotNull('score')->count());
    }

    public function test_xlsx_template_round_trips_without_formulas_and_exports_escape_text(): void
    {
        [, , $teachers, $offerings] = $this->schoolFixture();
        $id = $this->assessment($teachers[0], $offerings[0]);
        $a = DB::table('official_assessments')->find($id);
        $matrix = app(GradeSpreadsheet::class)->matrix($a);
        $matrix[1][7] = '=HYPERLINK("https://example.test")';
        $xlsx = app(GradeSpreadsheet::class)->export($matrix, 'xlsx', 'Synthetic instructions');
        $response = $this->postJson("/api/school/assessments/$id/imports/preview", ['file' => UploadedFile::fake()->createWithContent('notes.xlsx', $xlsx)]);
        $this->assertSame(200, $response->status(), $response->getContent());
        $response->assertJsonCount(0, 'errors');
        $csv = app(GradeSpreadsheet::class)->export($matrix, 'csv', 'Synthetic');
        $this->assertStringContainsString("'=HYPERLINK", $csv);
    }

    public function test_concurrent_edits_and_assignment_revocation_fail_closed(): void
    {
        [$admin, $group, $teachers, $offerings, $students] = $this->schoolFixture();
        $id = $this->assessment($teachers[0], $offerings[0]);
        $rows = [['student_id' => $students[0]->id, 'score' => '0', 'status' => 'graded']];
        $this->putJson("/api/school/assessments/$id/draft", ['version' => 1, 'rows' => $rows])->assertOk();
        $this->putJson("/api/school/assessments/$id/draft", ['version' => 1, 'rows' => $rows])->assertConflict();
        $this->postJson("/api/school/assessments/$id/publish", ['version' => 2, 'summary' => 'Synthetic summary'])->assertUnprocessable();
        $assignment = DB::table('teaching_assignments')->where('offering_id', $offerings[0])->first();
        app(SchoolSetupService::class)->revokeAssignment($admin, $assignment->id);
        $this->putJson("/api/school/assessments/$id/draft", ['version' => 2, 'rows' => $rows])->assertForbidden();
        $this->getJson("/api/school/assessments/$id/template")->assertForbidden();
    }
}
