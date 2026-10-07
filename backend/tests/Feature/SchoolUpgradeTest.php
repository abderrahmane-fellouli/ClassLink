<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Attempt;
use App\Models\Material;
use App\Models\Quiz;
use App\Models\Submission;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Runs only in the configured test database, on both CI database engines. */
class SchoolUpgradeTest extends TestCase
{
    use DatabaseMigrations;

    public function test_institutional_upgrade_preserves_populated_legacy_records_and_ids(): void
    {
        [$classroom, $teacher, $student] = $this->classWithMember();
        $this->tokenFor($student);
        $quiz = Quiz::create(['classroom_id' => $classroom->id, 'created_by' => $teacher->id, 'title' => 'Synthetic legacy quiz', 'status' => 'published', 'source' => 'manual']);
        Attempt::create(['quiz_id' => $quiz->id, 'student_id' => $student->id, 'attempt_no' => 1, 'score' => 16, 'max_score' => 20, 'started_at' => now()->subHour(), 'submitted_at' => now()]);
        $assignment = Assignment::create(['classroom_id' => $classroom->id, 'created_by' => $teacher->id, 'title' => 'Synthetic legacy assignment', 'due_at' => now()->addDay()]);
        Submission::create(['assignment_id' => $assignment->id, 'student_id' => $student->id, 'file_path' => 'synthetic/legacy.pdf', 'file_name' => 'synthetic.pdf', 'grade' => 16, 'feedback' => 'Synthetic private legacy feedback']);
        Material::create(['classroom_id' => $classroom->id, 'uploaded_by' => $teacher->id, 'title' => 'Synthetic legacy resource', 'type' => 'link', 'path_or_url' => 'https://example.test/resource']);
        $tables = ['users', 'classrooms', 'memberships', 'quizzes', 'attempts', 'assignments', 'submissions', 'materials', 'personal_access_tokens'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toArray();
        }
        $names = [
            '2026_10_07_000010_add_institutional_school_model',
            '2026_10_07_000011_create_official_assessments',
            '2026_10_07_000012_create_school_communication',
            '2026_10_07_000013_scope_existing_teaching_content',
            '2026_10_07_000014_add_school_roster_imports',
            '2026_10_07_000015_add_exceptional_module_access',
            '2026_10_07_000016_add_setup_requests_and_delegate_constraints',
            '2026_10_07_000017_create_private_school_reports',
            '2026_10_07_000018_add_resource_categories',
            '2026_10_07_000019_finalize_institutional_integrity',
        ];
        // Reconstruct the pre-upgrade schema in this isolated test DB only.
        foreach (array_reverse($names) as $name) {
            (require database_path('migrations/'.$name.'.php'))->down();
        }
        $this->assertFalse(Schema::hasTable('academic_years'));
        $this->assertFalse(Schema::hasColumn('classrooms', 'is_official'));
        foreach ($names as $name) {
            (require database_path('migrations/'.$name.'.php'))->up();
        }
        foreach ($tables as $table) {
            $this->assertEquals($before[$table], DB::table($table)->orderBy('id')->get()->toArray(), $table);
        }
        $this->assertSame(0, DB::table('classrooms')->where('is_official', true)->count());
        $this->assertSame(0, DB::table('school_modules')->count());
        $this->assertTrue(Schema::hasTable('sessions'));
    }
}
