<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** A read-only report; never guesses or applies a name-based class merge. */
class SchoolMappingReport extends Command
{
    protected $signature = 'classlink:school-mapping-report {--map= : Reviewed JSON file with legacy_classroom_id and offering_id pairs}';

    protected $description = 'Dry-run legacy group/module mapping and collision report; makes no writes.';

    public function handle(): int
    {
        $mapping = [];
        if ($path = $this->option('map')) {
            if (! is_file($path) || filesize($path) > 1048576) {
                $this->error('Invalid mapping file.');

                return self::FAILURE;
            }
            try {
                $mapping = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                $this->error('Invalid mapping JSON.');

                return self::FAILURE;
            }
            if (! is_array($mapping)) {
                $this->error('Mapping must be a JSON list.');

                return self::FAILURE;
            }
        }
        $pairs = collect($mapping)->keyBy('legacy_classroom_id');
        if ($pairs->count() !== count($mapping) || collect($mapping)->contains(fn ($row) => ! is_array($row) || ! isset($row['legacy_classroom_id'], $row['offering_id']) || ! is_numeric($row['legacy_classroom_id']) || ! is_numeric($row['offering_id']))) {
            $this->error('Invalid or duplicate source mapping; explicit review is required.');

            return self::FAILURE;
        }
        // Informational only: a repeated legacy name+year is a review hint, never an auto-merge key.
        $ambiguous = DB::table('classrooms')->where('is_official', false)
            ->selectRaw('name, school_year, count(*) as c')->groupBy('name', 'school_year')->havingRaw('count(*) > 1')
            ->get()->mapWithKeys(fn ($r) => [$r->name.'|'.$r->school_year => true])->all();
        $rows = [];
        foreach (DB::table('classrooms')->where('is_official', false)->orderBy('id')->get() as $legacy) {
            $pair = $pairs->get($legacy->id);
            $offering = $pair ? DB::table('module_offerings')->find($pair['offering_id'] ?? 0) : null;
            $members = DB::table('memberships')->where('classroom_id', $legacy->id)->where('status', 'accepted')->pluck('student_id');
            $conflicts = [];
            $context = null;
            if ($offering) {
                $group = DB::table('classrooms')->find($offering->classroom_id);
                $conflicts = DB::table('school_enrollments')->where('academic_year_id', $group->academic_year_id)->whereIn('active_student_id', $members)->where('classroom_id', '!=', $group->id)->pluck('student_id')->all();
                $module = DB::table('school_modules')->find($offering->module_id);
                $activeTeacher = DB::table('teaching_assignments')->where('offering_id', $offering->id)->whereNotNull('active_teacher_id')->whereNull('ends_at')->orderByDesc('id')->first();
                $context = ['group_id' => $group->id, 'group_name' => $group->name, 'group_status' => $group->status,
                    'module_id' => $module?->id, 'module_code' => $module?->code, 'module_name' => $module?->name,
                    'active_teacher_id' => $activeTeacher?->active_teacher_id,
                    // Legacy teacher kept on the row so a mismatch is visible, never auto-resolved.
                    'legacy_teacher_id' => $legacy->teacher_id,
                    'teacher_mismatch' => $activeTeacher ? $activeTeacher->active_teacher_id !== $legacy->teacher_id : null];
            }
            $quizIds = DB::table('quizzes')->where('classroom_id', $legacy->id)->select('id');
            $assignmentIds = DB::table('assignments')->where('classroom_id', $legacy->id)->select('id');
            $deckIds = DB::table('flashcard_decks')->where('classroom_id', $legacy->id)->select('id');
            $rows[] = ['legacy_classroom_id' => $legacy->id, 'teacher_id' => $legacy->teacher_id,
                'name' => $legacy->name, 'subject' => $legacy->subject, 'year' => $legacy->school_year,
                'accepted_members' => $members->count(), 'proposed_offering_id' => $offering?->id,
                'collision_student_ids' => $conflicts, 'review_required' => true,
                'ambiguous_name_and_year' => (bool) ($ambiguous[$legacy->name.'|'.$legacy->school_year] ?? false),
                'proposed_context' => $context,
                // What a merge would carry over: counted, never written by this command.
                'inventory' => [
                    'memberships' => DB::table('memberships')->where('classroom_id', $legacy->id)->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status')->all(),
                    'materials' => DB::table('materials')->where('classroom_id', $legacy->id)->count(),
                    'announcements' => DB::table('announcements')->where('classroom_id', $legacy->id)->count(),
                    'assignments' => DB::table('assignments')->where('classroom_id', $legacy->id)->count(),
                    'assignment_submissions' => DB::table('submissions')->whereIn('assignment_id', $assignmentIds)->count(),
                    'quizzes' => DB::table('quizzes')->where('classroom_id', $legacy->id)->count(),
                    'quiz_questions' => DB::table('questions')->whereIn('quiz_id', $quizIds)->count(),
                    'quiz_attempts' => DB::table('attempts')->whereIn('quiz_id', $quizIds)->count(),
                    'flashcard_decks' => DB::table('flashcard_decks')->where('classroom_id', $legacy->id)->count(),
                    'flashcards' => DB::table('flashcards')->whereIn('deck_id', $deckIds)->count(),
                ],
                'status' => $pair && ! $offering ? 'invalid_mapping' : ($offering ? 'explicit_reviewed_pair_needs_content_provenance_review' : 'unmapped_no_guess')];
        }
        $this->line(json_encode(['dry_run' => true, 'writes' => 0, 'rows' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
