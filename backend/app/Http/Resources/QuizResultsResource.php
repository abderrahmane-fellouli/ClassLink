<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/** F-QUI-07 : résultats agrégés d'un quiz pour le propriétaire. */
class QuizResultsResource extends \Illuminate\Http\Resources\Json\JsonResource
{
    public function toArray(Request $request): array
    {
        $quiz = $this->resource;
        $attempts = $quiz->attempts->whereNotNull('submitted_at')->values();

        $scores = $attempts->map(fn ($a) => $a->percentage());
        $members = $quiz->classroom->memberships()
            ->where('status', 'accepted')
            ->count();

        // F-PRO-03 : questions les plus ratées.
        $missed = [];
        foreach ($quiz->questions as $question) {
            $total = 0;
            $wrong = 0;
            foreach ($attempts as $attempt) {
                $answer = $attempt->answers->firstWhere('question_id', $question->id);
                if (! $answer) {
                    continue;
                }
                $total++;
                if (! $answer->is_correct) {
                    $wrong++;
                }
            }
            if ($total > 0) {
                $missed[] = [
                    'question_id' => $question->id,
                    'statement' => $question->statement,
                    'misses' => $wrong,
                    'attempts' => $total,
                    'miss_rate' => round(($wrong / $total) * 100, 1),
                ];
            }
        }
        usort($missed, static fn ($a, $b) => $b['misses'] <=> $a['misses']);

        return [
            'quiz' => [
                'id' => $quiz->id,
                'title' => $quiz->title,
                'classroom_id' => $quiz->classroom_id,
                'questions_count' => $quiz->questions->count(),
            ],
            'summary' => [
                'students' => $members,
                'participants' => $attempts->pluck('student_id')->unique()->count(),
                'participation_rate' => $members === 0
                    ? 0.0
                    : round(($attempts->pluck('student_id')->unique()->count() / $members) * 100, 1),
                'average_percentage' => $scores->isEmpty() ? 0.0 : round($scores->avg(), 1),
                'highest_percentage' => $scores->isEmpty() ? 0.0 : round($scores->max(), 1),
                'lowest_percentage' => $scores->isEmpty() ? 0.0 : round($scores->min(), 1),
                'attempts_count' => $attempts->count(),
            ],

            // `->resolve()` evite un deuxieme niveau `data` imbrique.
            'attempts' => AttemptSummaryResource::collection($attempts->load('student'))->resolve(),
            'most_missed' => array_slice($missed, 0, 5),
        ];
    }
}
