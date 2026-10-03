<?php

namespace App\Http\Controllers;

use App\Http\Resources\QuizResultsResource;
use App\Models\Quiz;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * §12.4 — GET /quizzes/{id}/results (F-QUI-07) et export CSV (F-QUI-09, Could).
 */
class QuizResultController extends Controller
{
    /** F-QUI-07 — résultats par quiz : scores, questions ratées. */
    public function show(Request $request, Quiz $quiz): QuizResultsResource
    {
        $this->authorize('results', $quiz);

        $quiz->load([
            'questions',
            'attempts' => fn ($q) => $q->whereNotNull('submitted_at')->with('answers', 'student'),
            'classroom',
        ]);

        return new QuizResultsResource($quiz);
    }

    /**
     * F-QUI-09 (Could) — export CSV des résultats.
     * Les emails ne sont pas exportés (RG-18) : seuls le nom d'affichage,
     * le score et le pourcentage.
     */
    public function exportCsv(Request $request, Quiz $quiz)
    {
        $this->authorize('results', $quiz);

        $attempts = $quiz->attempts()
            ->whereNotNull('submitted_at')
            ->with('student')
            ->orderBy('student_id')
            ->get();

        $rows = $attempts->map(fn ($a) => [
            'display_name' => $a->student?->display_name,
            'attempt_no' => $a->attempt_no,
            'score' => $a->score,
            'max_score' => $a->max_score,
            'percentage' => $a->percentage(),
            'expired' => $a->expired ? 'oui' : 'non',
            'submitted_at' => $a->submitted_at?->toIso8601String(),
        ]);

        $filename = 'resultats-quiz-'.$quiz->id.'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM : accents corrects dans Excel
            fputcsv($out, array_keys((array) ($rows->first() ?? [
                'display_name' => '', 'attempt_no' => '', 'score' => '',
                'max_score' => '', 'percentage' => '', 'expired' => '', 'submitted_at' => '',
            ])), ';');

            foreach ($rows as $row) {
                if (preg_match('/^[\s]*[=+@-]/u', (string) $row['display_name'])) {
                    $row['display_name'] = "'".$row['display_name'];
                }
                fputcsv($out, (array) $row, ';');
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
