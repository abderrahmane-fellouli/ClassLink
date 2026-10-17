<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Material;
use App\Models\Submission;
use App\Services\OfficialGradeService;
use App\Services\SchoolCommunicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\SchoolFixture;
use Tests\TestCase;

/** Populated grade revisions/private threads complement the actual local copy's empty domains. */
class WorkspaceUpgradePreservationTest extends TestCase
{
    use RefreshDatabase, SchoolFixture;

    public function test_additive_upgrade_preserves_populated_institutional_history_and_authorization(): void
    {
        $migration = require database_path('migrations/2026_10_17_000001_create_workspace_foundation.php');
        $scopeMigration = require database_path('migrations/2026_10_17_000002_add_compatibility_workspace_scope.php');
        $scopeMigration->down();
        $migration->down(); // Empty foundation only, inside this isolated test database.
        [$admin, $group, $teachers, $offerings, $students] = $this->schoolFixture();
        $grades = app(OfficialGradeService::class);
        $assessment = $grades->create($teachers[0], $offerings[0], ['title' => 'Synthetic preserved assessment', 'type' => 'exam', 'assessed_on' => now()->toDateString(), 'maximum_score' => 20, 'coefficient' => 1]);
        $rows = array_map(fn ($student) => ['student_id' => $student->id, 'score' => 16, 'status' => 'graded', 'feedback' => 'Synthetic private feedback'], $students->all());
        $grades->save($teachers[0], $assessment, 1, $rows);
        $grades->publish($teachers[0], $assessment, 2, 'Synthetic publication');
        $grades->correction($teachers[0], $assessment, 3, 'Synthetic correction draft');
        $thread = app(SchoolCommunicationService::class)->create($students[0], ['kind' => 'private_question', 'classroom_id' => $group->id, 'offering_id' => $offerings[0], 'assessment_id' => $assessment, 'subject' => 'Synthetic preserved conversation', 'body' => 'Synthetic private message', 'participant_ids' => [$teachers[0]->id]]);
        [$historical] = $this->classWithMember($teachers[0], $students[0]);
        $assignment = Assignment::create(['classroom_id' => $historical->id, 'created_by' => $teachers[0]->id, 'title' => 'Synthetic preserved homework']);
        Submission::create(['assignment_id' => $assignment->id, 'student_id' => $students[0]->id, 'file_path' => 'synthetic/retained.pdf', 'file_name' => 'retained.pdf', 'grade' => 15, 'feedback' => 'Synthetic retained feedback']);
        Material::create(['classroom_id' => $historical->id, 'uploaded_by' => $teachers[0]->id, 'title' => 'Synthetic retained resource', 'type' => 'link', 'path_or_url' => 'https://example.invalid/retained']);
        $studentToken = $this->tokenFor($students[0]);
        $otherToken = $this->tokenFor($students[1]);
        $teacherToken = $this->tokenFor($teachers[0]);
        $adminToken = $this->tokenFor($admin);
        $this->asToken($studentToken)->getJson('/api/school/my-grades')->assertOk()->assertJsonPath('data.0.score', fn ($score) => (float) $score === 16.0);
        $this->asToken($otherToken)->getJson('/api/school/threads/'.$thread)->assertNotFound();
        $this->asToken($adminToken)->getJson('/api/school/assessments/'.$assessment)->assertForbidden();

        $tables = array_values(array_filter(Schema::getTableListing(), fn ($name) => ! str_starts_with($name, 'sqlite_')));
        $snapshot = function () use ($tables): array {
            $rows = [];
            foreach ($tables as $table) {
                $records = array_map(fn ($row) => json_encode($row, JSON_THROW_ON_ERROR), DB::table($table)->get()->all());
                sort($records);
                $rows[$table] = $records;
            }

            return $rows;
        };
        $before = $snapshot();
        $migration->up();
        $this->assertSame($before, $snapshot(), 'B1 must not change any preexisting row, role or ID.');
        $this->assertDatabaseCount('workspaces', 0);
        $this->assertDatabaseCount('workspace_memberships', 0);
        $this->assertDatabaseCount('grade_revisions', 2);
        $this->assertDatabaseCount('school_messages', 1);
        $this->assertFalse(Schema::hasColumn('classrooms', 'workspace_id'));
        $this->asToken($studentToken)->getJson('/api/school/my-grades')->assertOk()->assertJsonPath('data.0.score', fn ($score) => (float) $score === 16.0);
        $this->asToken($studentToken)->getJson('/api/school/threads/'.$thread)->assertOk();
        $this->asToken($teacherToken)->getJson('/api/school/assessments/'.$assessment)->assertOk();
        $this->asToken($otherToken)->getJson('/api/school/threads/'.$thread)->assertNotFound();
        $this->asToken($adminToken)->getJson('/api/school/assessments/'.$assessment)->assertForbidden();
        $scopeMigration->up();
    }
}
