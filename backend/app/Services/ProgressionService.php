<?php

namespace App\Services;

use App\Models\Attempt;
use App\Models\Classroom;
use App\Enums\MembershipStatus;
use App\Models\Question;
use App\Models\User;

/**
 * F-PRO-01, F-PRO-02, F-PRO-03.
 */
class ProgressionService
{
    /** F-PRO-01 — tableau de bord étudiant : quiz faits, scores, évolution. */
    public function forStudent(User $student): array
    {
        $attempts = $student->attempts()
            ->whereNotNull('submitted_at')
            ->with('quiz:id,title,classroom_id')
            ->orderBy('submitted_at')
            ->get();

        $rows = $attempts->map(fn ($attempt) => [
            'attempt_id' => $attempt->id,
            'quiz_id' => $attempt->quiz_id,
            'quiz_title' => $attempt->quiz?->title,
            'classroom_id' => $attempt->quiz?->classroom_id,
            'attempt_no' => $attempt->attempt_no,
            'score' => (float) $attempt->score,
            'max_score' => (float) $attempt->max_score,
            'percentage' => $attempt->percentage(),
            'submitted_at' => $attempt->submitted_at?->toIso8601String(),
        ])->values()->all();

        $average = $attempts->isEmpty()
            ? 0.0
            : round($attempts->avg(fn ($a) => $a->percentage()), 1);

        return [
            'totals' => [
                'quizzes_taken' => $attempts->pluck('quiz_id')->unique()->count(),
                'attempts' => $attempts->count(),
                'average_percentage' => $average,
                'best_percentage' => $attempts->isEmpty() ? 0.0 : round($attempts->max(fn ($a) => $a->percentage()), 1),
            ],
            'history' => $rows,
            'trend' => [
                'direction' => count($rows) < 2 ? 'insufficient_data'
                    : ($rows[count($rows) - 1]['percentage'] > $rows[0]['percentage'] ? 'up'
                        : ($rows[count($rows) - 1]['percentage'] < $rows[0]['percentage'] ? 'down' : 'stable')),
                'delta_percentage_points' => count($rows) < 2 ? null
                    : round($rows[count($rows) - 1]['percentage'] - $rows[0]['percentage'], 1),
                'series' => array_map(fn ($row) => ['submitted_at' => $row['submitted_at'], 'percentage' => $row['percentage']], $rows),
            ],
        ];
    }

    /**
     * F-PRO-02 / F-PRO-03 — tableau de bord enseignant : moyenne de la
     * classe, participation, étudiants inactifs, questions les plus ratées.
     */
    public function forClassroom(Classroom $classroom): array
    {
        $members = $classroom->memberships()
            ->where('status', MembershipStatus::Accepted->value)
            ->pluck('student_id');

        $attempts = Attempt::whereIn('quiz_id', $classroom->quizzes()->select('id'))
            ->whereNotNull('submitted_at')
            ->with(['answers', 'quiz.questions'])
            ->get();

        $scores = $attempts->map(fn ($a) => $a->percentage());

        $participated = $attempts->pluck('student_id')->unique()->intersect($members);
        $inactive = $members->diff($participated)->values();

        // F-PRO-03 : questions les plus ratées.
        $missed = [];

        foreach ($attempts as $attempt) {
            $answers = $attempt->answers->keyBy('question_id');
            foreach ($attempt->quiz?->questions ?? [] as $q) {
                $qid = $q->id;
                $missed[$qid] ??= ['question_id' => $qid, 'misses' => 0, 'attempts' => 0];
                $missed[$qid]['attempts']++;
                if (! $answers->get($qid)?->is_correct) {
                    $missed[$qid]['misses']++;
                }
            }
        }

        $mostMissed = collect($missed)
            ->map(function (array $row) {
                $total = max(1, $row['attempts']);
                $row['miss_rate'] = round(($row['misses'] / $total) * 100, 1);

                return $row;
            })
            ->sortByDesc('misses')
            ->take(5)
            ->values()
            ->all();

        $questions = Question::whereIn('id', array_keys($missed))
            ->with('quiz:id,title')
            ->get()
            ->keyBy('id');

        foreach ($mostMissed as &$row) {
            $row['statement'] = $questions[$row['question_id']]?->statement;
            $row['quiz_title'] = $questions[$row['question_id']]?->quiz?->title;
        }

        return [
            'totals' => [
                'students' => $members->count(),
                'quizzes' => $classroom->quizzes()->count(),
                'attempts' => $attempts->count(),
                'participation_rate' => $members->isEmpty()
                    ? 0.0
                    : round(($participated->count() / $members->count()) * 100, 1),
                'class_average' => $scores->isEmpty() ? 0.0 : round($scores->avg(), 1),
            ],
            'inactive_student_ids' => $inactive->all(),
            'inactive_students' => User::whereIn('id', $inactive)->orderBy('display_name')->get(['id', 'display_name'])->toArray(),
            'most_missed' => $mostMissed,
        ];
    }
}
