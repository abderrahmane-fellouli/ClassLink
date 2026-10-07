<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function checks(): array
    {
        return [
            'academic_years' => ['year_dates' => 'ends_on > starts_on', 'year_state' => "status IN ('draft','active','archived')", 'year_version' => 'version >= 1'],
            'classrooms' => ['official_context' => 'is_official = FALSE OR (academic_year_id IS NOT NULL AND official_code IS NOT NULL AND teacher_id IS NULL)', 'coordinator_permission' => 'coordinator_can_manage_roster = FALSE OR coordinator_id IS NOT NULL'],
            'school_modules' => ['module_state' => "status IN ('active','archived')"],
            'module_offerings' => ['offering_state' => "status IN ('active','archived')"],
            'teaching_assignments' => ['active_teacher_key' => 'active_teacher_id IS NULL OR (active_teacher_id = teacher_id AND ends_at IS NULL)', 'assignment_period' => 'ends_at IS NULL OR ends_at >= starts_at'],
            'school_enrollments' => ['active_student_key' => 'active_student_id IS NULL OR (active_student_id = student_id AND ends_at IS NULL)', 'enrollment_period' => 'ends_at IS NULL OR ends_at >= starts_at'],
            'class_delegates' => ['delegate_period' => 'ends_at > starts_at'],
            'official_assessments' => ['assessment_scale' => 'maximum_score > 0 AND coefficient > 0', 'assessment_version' => 'version >= 1', 'assessment_state' => "state IN ('draft','published','correction')"],
            'grade_entries' => ['grade_status' => "status IN ('graded','ungraded','absent','exempt','makeup')", 'grade_score' => "(status = 'graded' AND score IS NOT NULL AND score >= 0) OR (status <> 'graded' AND score IS NULL)"],
            'grade_import_batches' => ['batch_commit' => 'committed_at IS NULL OR committed_version IS NOT NULL'],
            'school_threads' => ['thread_state' => "status IN ('open','resolved')"],
            'school_reports' => ['report_state' => "status IN ('open','resolved')"],
        ];
    }

    private function sqliteExpression(string $table, string $expression): string
    {
        foreach (Schema::getColumnListing($table) as $column) {
            $expression = preg_replace('/\b'.preg_quote($column, '/').'\b/', 'NEW.'.$column, $expression);
        }
        return $expression;
    }

    public function up(): void
    {
        Schema::table('classrooms', fn (Blueprint $t) => $t->unique(['id', 'academic_year_id'], 'classroom_year_identity'));
        Schema::table('school_enrollments', fn (Blueprint $t) => $t->foreign(['classroom_id', 'academic_year_id'], 'enrollment_group_year_fk')->references(['id', 'academic_year_id'])->on('classrooms')->restrictOnDelete());
        Schema::table('grade_revisions', fn (Blueprint $t) => $t->unique(['id', 'assessment_id'], 'revision_assessment_identity'));
        Schema::table('official_assessments', function (Blueprint $t) {
            $t->foreign(['draft_revision_id', 'id'], 'assessment_draft_revision_fk')->references(['id', 'assessment_id'])->on('grade_revisions')->restrictOnDelete();
            $t->foreign(['published_revision_id', 'id'], 'assessment_published_revision_fk')->references(['id', 'assessment_id'])->on('grade_revisions')->restrictOnDelete();
        });
        Schema::table('grade_entries', function (Blueprint $t) {
            $t->foreign(['revision_id', 'assessment_id'], 'entry_revision_assessment_fk')->references(['id', 'assessment_id'])->on('grade_revisions')->restrictOnDelete();
            $t->foreign(['assessment_id', 'student_id'], 'entry_candidate_fk')->references(['assessment_id', 'student_id'])->on('assessment_candidates')->restrictOnDelete();
        });
        Schema::table('school_notification_outbox', fn (Blueprint $t) => $t->index(['delivered_at', 'id'], 'school_outbox_pending_index'));
        foreach ($this->checks() as $table => $checks) {
            foreach ($checks as $name => $expression) {
                if (DB::getDriverName() === 'pgsql') {
                    DB::statement("ALTER TABLE $table ADD CONSTRAINT school_$name CHECK ($expression)");
                } elseif (DB::getDriverName() === 'sqlite') {
                    $expression = $this->sqliteExpression($table, $expression);
                    foreach (['INSERT', 'UPDATE'] as $operation) {
                        $trigger = 'school_'.$name.'_'.strtolower($operation);
                        DB::statement("CREATE TRIGGER $trigger BEFORE $operation ON $table WHEN NOT ($expression) BEGIN SELECT RAISE(ABORT, '$name invariant'); END");
                    }
                }
            }
        }
        // A score cannot exceed its assessment scale, even through a raw insert.
        // `migrate:fresh` drops the tables but NOT the function on PostgreSQL, so
        // re-running the migration must be idempotent (OR REPLACE + drop trigger).
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE OR REPLACE FUNCTION school_grade_bound() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF NEW.score IS NOT NULL AND NEW.score > (SELECT maximum_score FROM official_assessments WHERE id = NEW.assessment_id) THEN RAISE EXCEPTION 'grade bound invariant' USING ERRCODE = '23514'; END IF; RETURN NEW; END \$\$; DROP TRIGGER IF EXISTS school_grade_bound_trigger ON grade_entries; CREATE TRIGGER school_grade_bound_trigger BEFORE INSERT OR UPDATE ON grade_entries FOR EACH ROW EXECUTE FUNCTION school_grade_bound();");
        } elseif (DB::getDriverName() === 'sqlite') {
            foreach (['INSERT', 'UPDATE'] as $operation) {
                DB::statement('CREATE TRIGGER school_grade_bound_'.strtolower($operation)." BEFORE $operation ON grade_entries WHEN NEW.score > (SELECT maximum_score FROM official_assessments WHERE id = NEW.assessment_id) BEGIN SELECT RAISE(ABORT, 'grade bound invariant'); END");
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS school_grade_bound_trigger ON grade_entries');
            DB::statement('DROP FUNCTION IF EXISTS school_grade_bound()');
        } else {
            DB::statement('DROP TRIGGER IF EXISTS school_grade_bound_insert');
            DB::statement('DROP TRIGGER IF EXISTS school_grade_bound_update');
        }
        foreach ($this->checks() as $table => $checks) {
            foreach (array_keys($checks) as $name) {
                if (DB::getDriverName() === 'pgsql') { DB::statement("ALTER TABLE $table DROP CONSTRAINT IF EXISTS school_$name"); }
                else { DB::statement("DROP TRIGGER IF EXISTS school_{$name}_insert"); DB::statement("DROP TRIGGER IF EXISTS school_{$name}_update"); }
            }
        }
        $sqlite = DB::getDriverName() === 'sqlite';
        // SQLite cannot drop a foreign key by name: it rebuilds the table from the
        // blueprint state when dropForeign() is given column names instead.
        if ($sqlite) {
            Schema::table('grade_entries', function (Blueprint $t) {
                $t->dropForeign(['revision_id', 'assessment_id']);
                $t->dropForeign(['assessment_id', 'student_id']);
            });
            Schema::table('official_assessments', function (Blueprint $t) {
                $t->dropForeign(['draft_revision_id', 'id']);
                $t->dropForeign(['published_revision_id', 'id']);
            });
            Schema::table('school_enrollments', fn (Blueprint $t) => $t->dropForeign(['classroom_id', 'academic_year_id']));
        } else {
            Schema::table('grade_entries', function (Blueprint $t) { $t->dropForeign('entry_candidate_fk'); $t->dropForeign('entry_revision_assessment_fk'); });
            Schema::table('official_assessments', function (Blueprint $t) { $t->dropForeign('assessment_draft_revision_fk'); $t->dropForeign('assessment_published_revision_fk'); });
            Schema::table('school_enrollments', fn (Blueprint $t) => $t->dropForeign('enrollment_group_year_fk'));
        }
        Schema::table('grade_revisions', fn (Blueprint $t) => $t->dropUnique('revision_assessment_identity'));
        Schema::table('classrooms', fn (Blueprint $t) => $t->dropUnique('classroom_year_identity'));
        Schema::table('school_notification_outbox', fn (Blueprint $t) => $t->dropIndex('school_outbox_pending_index'));
    }
};
