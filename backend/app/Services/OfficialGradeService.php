<?php

namespace App\Services;

use App\Jobs\DeliverSchoolNotifications;
use App\Models\AuditLog;
use App\Models\Classroom;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class OfficialGradeService
{
    public const STATUSES = ['graded', 'ungraded', 'absent', 'exempt', 'makeup'];

    public function __construct(private SchoolAccess $access) {}

    public function assessment(User $actor, int $id, bool $write = false): object
    {
        $a = DB::table('official_assessments')->find($id);
        abort_unless($a, 404);
        $this->access->offering($actor, $a->offering_id, $write);
        abort_unless($this->access->teaches($actor, $a->offering_id), 403);

        return $a;
    }

    public function create(User $actor, int $offering, array $data): int
    {
        $o = $this->access->offering($actor, $offering, true);

        return DB::transaction(function () use ($actor, $o, $data) {
            $group = Classroom::whereKey($o->classroom_id)->lockForUpdate()->firstOrFail();
            $students = User::where('is_active', true)->where('role', 'student')->where(function ($q) use ($group, $o) {
                $q->whereIn('id', DB::table('school_enrollments')->where('classroom_id', $group->id)->whereNotNull('active_student_id')->select('student_id'))
                    ->orWhereIn('id', DB::table('module_access_grants')->where('offering_id', $o->id)->whereNull('revoked_at')->where('expires_at', '>', now())->select('student_id'));
            })->select('id', 'display_name')->get();
            abort_if($students->isEmpty(), 409, __('api.school.empty_roster'));
            abort_if($students->count() > 5000, 422);
            $id = DB::table('official_assessments')->insertGetId($data + ['offering_id' => $o->id, 'created_by' => $actor->id,
                'roster_version' => $group->roster_version, 'state' => 'draft', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
            $revision = DB::table('grade_revisions')->insertGetId(['assessment_id' => $id, 'created_by' => $actor->id, 'sequence' => 1, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('official_assessments')->where('id', $id)->update(['draft_revision_id' => $revision]);
            $candidateRows = $entryRows = [];
            foreach ($students as $student) {
                $candidateRows[] = ['assessment_id' => $id, 'student_id' => $student->id, 'display_name' => $student->display_name];
                $entryRows[] = ['revision_id' => $revision, 'assessment_id' => $id, 'student_id' => $student->id, 'status' => 'ungraded', 'created_at' => now(), 'updated_at' => now()];
            }
            foreach (array_chunk($candidateRows, 500) as $chunk) {
                DB::table('assessment_candidates')->insert($chunk);
            }
            foreach (array_chunk($entryRows, 500) as $chunk) {
                DB::table('grade_entries')->insert($chunk);
            }
            AuditLog::record($actor, 'school.assessment.create', ['assessment_id' => $id, 'offering_id' => $o->id]);

            return $id;
        });
    }

    public function normalize(object $assessment, array $rows): array
    {
        $allowed = DB::table('assessment_candidates')->where('assessment_id', $assessment->id)->pluck('student_id')->map(fn ($id) => (string) $id)->all();
        $seen = [];
        $normalized = [];
        $errors = [];
        foreach ($rows as $index => $row) {
            $number = $row['_row'] ?? $index + 1;
            $id = (string) ($row['student_id'] ?? '');
            $status = (string) ($row['status'] ?? 'ungraded');
            $score = str_replace(',', '.', trim((string) ($row['score'] ?? '')));
            $reason = null;
            if (! in_array($id, $allowed, true)) {
                $reason = 'unknown_or_ineligible_student';
            } elseif (isset($seen[$id])) {
                $reason = 'duplicate_student';
            } elseif (! in_array($status, self::STATUSES, true)) {
                $reason = 'invalid_status';
            } elseif ($status === 'graded' && (! preg_match('/^\d{1,5}(?:\.\d{1,2})?$/D', $score) || (float) $score > (float) $assessment->maximum_score)) {
                $reason = 'invalid_score';
            } elseif ($status !== 'graded' && $score !== '') {
                $reason = 'score_requires_graded_status';
            } elseif (mb_strlen((string) ($row['feedback'] ?? '')) > 2000) {
                $reason = 'feedback_too_long';
            }
            $seen[$id] = true;
            if ($reason) {
                $errors[] = ['row' => $number, 'reason' => $reason];
            } else {
                $normalized[] = ['student_id' => (int) $id, 'status' => $status,
                    'score' => $status === 'graded' ? number_format((float) $score, 2, '.', '') : null,
                    'feedback' => (string) ($row['feedback'] ?? '')];
            }
        }

        return [$normalized, $errors];
    }

    private function locked(User $actor, int $id, int $version): object
    {
        $a = DB::table('official_assessments')->find($id);
        abort_unless($a, 404);
        $o = $this->access->offering($actor, $a->offering_id, true);
        $a = DB::table('official_assessments')->where('id', $id)->lockForUpdate()->first();
        $group = Classroom::whereKey($o->classroom_id)->lockForUpdate()->firstOrFail();
        abort_unless($a->version === $version, 409, __('api.school.version_conflict'));
        abort_unless($a->roster_version === $group->roster_version, 409, __('api.school.roster_changed'));
        abort_unless($a->draft_revision_id, 409, __('api.school.correction_required'));
        $revision = DB::table('grade_revisions')->where('id', $a->draft_revision_id)->where('assessment_id', $a->id)->first();
        abort_unless($revision && ! $revision->published_at, 409, __('api.school.correction_required'));

        return $a;
    }

    public function save(User $actor, int $id, int $version, array $rows): int
    {
        return DB::transaction(function () use ($actor, $id, $version, $rows) {
            $a = $this->locked($actor, $id, $version);
            [$normalized, $errors] = $this->normalize($a, $rows);
            abort_if($errors !== [], 422, __('api.school.invalid_import'));
            // upsert sur l'unique (revision_id, student_id) : 1 statement
            // par tranche de 500 au lieu d'une requête UPDATE par ligne.
            $rows = array_map(fn (array $row) => $row + ['revision_id' => $a->draft_revision_id, 'assessment_id' => $id, 'created_at' => now(), 'updated_at' => now()], $normalized);
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('grade_entries')->upsert($chunk, ['revision_id', 'student_id'], ['status', 'score', 'feedback', 'updated_at']);
            }
            DB::table('official_assessments')->where('id', $id)->update(['version' => $version + 1, 'updated_at' => now()]);
            AuditLog::record($actor, 'school.grades.draft_saved', ['assessment_id' => $id, 'version' => $version + 1]);

            return $version + 1;
        });
    }

    public function preview(User $actor, int $id, array $rows, array $contextErrors = []): array
    {
        $a = $this->assessment($actor, $id, true);
        abort_unless($a->draft_revision_id, 409);
        [$normalized, $errors] = $this->normalize($a, $rows);
        $errors = array_merge($contextErrors, $errors);
        $existing = DB::table('grade_entries')->where('revision_id', $a->draft_revision_id)->get()->keyBy('student_id');
        $changed = $unchanged = 0;
        foreach ($normalized as $row) {
            $old = $existing->get($row['student_id']);
            if ($old && $old->status === $row['status'] && ($old->score === null ? null : number_format((float) $old->score, 2, '.', '')) === $row['score'] && (string) $old->feedback === $row['feedback']) {
                $unchanged++;
            } else {
                $changed++;
            }
        }
        $batch = (string) Str::uuid();
        DB::table('grade_import_batches')->insert(['id' => $batch, 'assessment_id' => $id, 'actor_id' => $actor->id,
            'version' => $a->version, 'roster_version' => $a->roster_version, 'rows' => json_encode($normalized), 'errors' => json_encode($errors),
            'expires_at' => now()->addMinutes(15), 'created_at' => now(), 'updated_at' => now()]);

        return ['batch_id' => $batch, 'version' => $a->version, 'rows' => $normalized, 'errors' => $errors,
            'summary' => ['changed' => $changed, 'unchanged' => $unchanged, 'missing' => max(0, $existing->count() - count($normalized)), 'omitted_rows_are_preserved' => true]];
    }

    public function commitImport(User $actor, int $id, string $batchId): int
    {
        return DB::transaction(function () use ($actor, $id, $batchId) {
            $this->assessment($actor, $id, true);
            $batch = DB::table('grade_import_batches')->where('id', $batchId)->lockForUpdate()->first();
            abort_unless($batch && $batch->actor_id === $actor->id && $batch->assessment_id === $id, 404);
            if ($batch->committed_at) {
                return $batch->committed_version;
            }
            abort_if(now()->parse($batch->expires_at)->isPast(), 410);
            abort_if(json_decode($batch->errors, true) !== [], 422);
            $version = $this->save($actor, $id, $batch->version, json_decode($batch->rows, true));
            DB::table('grade_import_batches')->where('id', $batchId)->update(['committed_at' => now(), 'committed_version' => $version, 'updated_at' => now()]);

            return $version;
        });
    }

    public function publish(User $actor, int $id, int $version, string $summary): int
    {
        return DB::transaction(function () use ($actor, $id, $version, $summary) {
            $a = $this->locked($actor, $id, $version);
            $entries = DB::table('grade_entries')->where('revision_id', $a->draft_revision_id)->get();
            $candidateIds = DB::table('assessment_candidates')->where('assessment_id', $id)->pluck('student_id')->sort()->values()->all();
            abort_if($entries->isEmpty() || $entries->pluck('student_id')->sort()->values()->all() !== $candidateIds
                || $entries->contains(fn ($e) => $e->status === 'ungraded'), 422, __('api.school.incomplete_grades'));
            [$validated, $errors] = $this->normalize($a, $entries->map(fn ($e) => (array) $e)->all());
            abort_if($errors !== [] || count($validated) !== count($candidateIds), 422, __('api.school.incomplete_grades'));
            DB::table('grade_revisions')->where('id', $a->draft_revision_id)->update(['published_at' => now(), 'publication_summary' => $summary, 'updated_at' => now()]);
            DB::table('official_assessments')->where('id', $id)->update(['published_revision_id' => $a->draft_revision_id, 'draft_revision_id' => null, 'state' => 'published', 'version' => $version + 1, 'updated_at' => now()]);
            AuditLog::record($actor, 'school.grades.publish', ['assessment_id' => $id, 'revision_id' => $a->draft_revision_id]);
            $outbox = [];
            foreach ($entries as $entry) {
                $outbox[] = ['event_key' => 'grade:'.$a->draft_revision_id, 'user_id' => $entry->student_id, 'type' => 'official_grade_published',
                    'payload' => json_encode(['assessment_id' => $id, 'revision_id' => $a->draft_revision_id, 'url' => '/app/school/grades']), 'created_at' => now(), 'updated_at' => now()];
            }
            foreach (array_chunk($outbox, 500) as $chunk) {
                DB::table('school_notification_outbox')->insertOrIgnore($chunk);
            }
            $this->queueNotifications();

            return $version + 1;
        });
    }

    public function correction(User $actor, int $id, int $version, string $reason): int
    {
        return DB::transaction(function () use ($actor, $id, $version, $reason) {
            $this->assessment($actor, $id, true);
            $a = DB::table('official_assessments')->where('id', $id)->lockForUpdate()->first();
            abort_unless($a->version === $version && $a->published_revision_id && ! $a->draft_revision_id, 409);
            $sequence = DB::table('grade_revisions')->where('assessment_id', $id)->max('sequence') + 1;
            $revision = DB::table('grade_revisions')->insertGetId(['assessment_id' => $id, 'created_by' => $actor->id, 'sequence' => $sequence, 'reason' => $reason, 'created_at' => now(), 'updated_at' => now()]);
            $copied = [];
            foreach (DB::table('grade_entries')->where('revision_id', $a->published_revision_id)->get() as $e) {
                $copied[] = ['revision_id' => $revision, 'assessment_id' => $id, 'student_id' => $e->student_id, 'score' => $e->score, 'status' => $e->status, 'feedback' => $e->feedback, 'created_at' => now(), 'updated_at' => now()];
            }
            foreach (array_chunk($copied, 500) as $chunk) {
                DB::table('grade_entries')->insert($chunk);
            }
            DB::table('official_assessments')->where('id', $id)->update(['draft_revision_id' => $revision, 'state' => 'correction', 'version' => $version + 1, 'updated_at' => now()]);
            AuditLog::record($actor, 'school.grades.correction', ['assessment_id' => $id, 'revision_id' => $revision]);

            return $version + 1;
        });
    }

    public function queueNotifications(): void
    {
        DB::afterCommit(function () {
            try {
                DeliverSchoolNotifications::dispatch();
            } catch (\Throwable $e) {
                Log::warning('School notification dispatch deferred', ['exception_class' => $e::class]);
            }
        });
    }

    public function reconcileRoster(User $actor, int $id, int $version, array $addIds): int
    {
        return DB::transaction(function () use ($actor, $id, $version, $addIds) {
            $a = DB::table('official_assessments')->find($id);
            abort_unless($a, 404);
            $o = $this->access->offering($actor, $a->offering_id, true);
            $a = DB::table('official_assessments')->where('id', $id)->lockForUpdate()->first();
            abort_unless($a->version === $version && $a->draft_revision_id, 409);
            $group = Classroom::whereKey($o->classroom_id)->lockForUpdate()->firstOrFail();
            // Prefetch groupé : 4 requêtes pour tout le lot au lieu de 4
            // requêtes par stagiaire (jusqu'à 5000 stagiaires).
            $students = $addIds === [] ? collect() : User::whereIn('id', $addIds)->get()->keyBy('id');
            $enrolledIds = $addIds === [] ? collect() : DB::table('school_enrollments')->where('classroom_id', $group->id)
                ->whereNotNull('active_student_id')->whereNull('ends_at')->whereIn('student_id', $addIds)->pluck('student_id')->flip();
            $grantIds = $addIds === [] ? collect() : DB::table('module_access_grants')->where('offering_id', $a->offering_id)
                ->whereNull('revoked_at')->where('expires_at', '>', now())->whereIn('student_id', $addIds)->pluck('student_id')->flip();
            $candidateIds = $addIds === [] ? collect() : DB::table('assessment_candidates')->where('assessment_id', $id)->whereIn('student_id', $addIds)->pluck('student_id')->flip();
            $candidateRows = $entryRows = [];
            foreach ($addIds as $studentId) {
                $student = $students->get($studentId);
                abort_unless($student, 404);
                $eligible = $student->is_active && $student->isStudent()
                    && ($enrolledIds->has($studentId) || $grantIds->has($studentId));
                abort_unless($eligible, 422);
                if (! $candidateIds->has($studentId)) {
                    $candidateIds->put($studentId, true);
                    $candidateRows[] = ['assessment_id' => $id, 'student_id' => $studentId, 'display_name' => $student->display_name];
                    $entryRows[] = ['revision_id' => $a->draft_revision_id, 'assessment_id' => $id, 'student_id' => $studentId, 'status' => 'ungraded', 'created_at' => now(), 'updated_at' => now()];
                }
            }
            foreach (array_chunk($candidateRows, 500) as $chunk) {
                DB::table('assessment_candidates')->insert($chunk);
            }
            foreach (array_chunk($entryRows, 500) as $chunk) {
                DB::table('grade_entries')->insert($chunk);
            }
            // Historical candidates/results are never deleted or transferred.
            DB::table('official_assessments')->where('id', $id)->update(['roster_version' => $group->roster_version, 'version' => $version + 1, 'updated_at' => now()]);
            AuditLog::record($actor, 'school.assessment.roster_reconciled', ['assessment_id' => $id, 'added_student_ids' => $addIds]);

            return $version + 1;
        });
    }

    public function results(User $student)
    {
        $this->access->assertActive($student);
        abort_unless($student->isStudent(), 403);

        return DB::table('official_assessments as a')->join('grade_entries as g', 'g.revision_id', '=', 'a.published_revision_id')
            ->join('grade_revisions as r', 'r.id', '=', 'a.published_revision_id')->join('module_offerings as o', 'o.id', '=', 'a.offering_id')
            ->join('school_modules as m', 'm.id', '=', 'o.module_id')->join('classrooms as c', 'c.id', '=', 'o.classroom_id')->where('g.student_id', $student->id)
            ->select('a.id', 'a.offering_id', 'a.title', 'a.assessed_on', 'a.maximum_score', 'a.coefficient', 'g.score', 'g.status', 'g.feedback', 'r.sequence', 'r.published_at', 'm.id as module_id', 'm.name as module_name', 'c.id as classroom_id', 'c.name as classroom_name', 'c.school_year')
            ->orderByDesc('a.assessed_on')->orderByDesc('a.id');
    }
}
