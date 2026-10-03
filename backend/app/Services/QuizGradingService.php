<?php

namespace App\Services;

use App\Enums\QuestionType;
use App\Enums\QuizStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Attempt;
use App\Models\AttemptAnswer;
use App\Models\Quiz;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * F-QUI-04, F-QUI-05, RG-12, RG-13.
 *
 * Règles appliquées :
 *  - RG-12 : « Un étudiant ne dépasse pas le nombre de tentatives autorisé.
 *    Une tentative dont le temps est écoulé est soumise automatiquement. »
 *  - RG-13 : « Les bonnes réponses ne sont jamais envoyées au navigateur
 *    avant la soumission. La correction se fait côté serveur. »
 *
 * Cette classe ne renvoie JAMAIS `options.is_correct`. Le jeu de questions
 * destiné à l'étudiant est construit par QuizQuestionResource, qui ne
 * sérialise que `id` et `label`.
 */
class QuizGradingService
{
    /**
     * Démarre une tentative. RG-12 : refus au-delà du maximum (T-16).
     *
     * @throws BusinessRuleException
     */
    public function start(User $student, Quiz $quiz): Attempt
    {
        if (! $quiz->isPublished()) {
            throw new BusinessRuleException('Ce quiz n\'est pas disponible.', 404);
        }

        return DB::transaction(function () use ($student, $quiz) {
        User::whereKey($student->id)->lockForUpdate()->firstOrFail();
        if ($quiz->classroom->isReadOnly()) {
            throw new BusinessRuleException('Archived classes are read-only.', 403);
        }
        if ($quiz->due_at && now()->gte($quiz->due_at)) {
            throw new BusinessRuleException('Quiz deadline has passed.', 409);
        }
        // RG-12 / T-16 : nombre de tentatives.
        $attemptsUsed = Attempt::where('quiz_id', $quiz->id)
            ->where('student_id', $student->id)
            ->count();

        if ($quiz->max_attempts !== null && $attemptsUsed >= $quiz->max_attempts) {
            throw new BusinessRuleException(
                'Nombre maximal de tentatives atteint.',
                409,
                ['max_attempts' => $quiz->max_attempts]
            );
        }

            /*
             * F-QUI-02 / US-26 : « les questions peuvent être mélangées ».
             *
             * Le mélange était entièrement fait par le client, donc
             * contournable : il suffisait de ne pas le mélanger. L'ordre est
             * désormais tiré par le serveur au démarrage de la tentative, puis
             * FIGÉ dessus : recharger la page, relancer la requête ou rouvrir
             * la tentative rend toujours le même ordre, et l'ordre réellement
             * servi reste vérifiable en base.
             */
            $questions = $quiz->questions()->with('options')->get();

            $ordered = $quiz->shuffle
                ? $questions->shuffle()->values()
                : $questions->values();

            return Attempt::create([
                'quiz_id' => $quiz->id,
                'student_id' => $student->id,
                'attempt_no' => $attemptsUsed + 1,
                'question_order' => $ordered->pluck('id')->map(static fn ($id) => (int) $id)->all(),
                'max_score' => $questions->count(),
                'started_at' => Carbon::now(),
            ]);
        });
    }

    /**
     * Questions d'une tentative, dans l'ordre figé au démarrage.
     *
     * Une tentative antérieure à la colonne `question_order` (ou dont l'ordre
     * ne contient plus toutes les questions du quiz) retombe sur l'ordre
     * `position` du quiz : on ne masque jamais une question.
     *
     * @return \Illuminate\Support\Collection<int, Question>
     */
    public function questionsFor(Attempt $attempt)
    {
        $questions = $attempt->quiz->questions()->with('options')->get()->keyBy('id');

        $order = $attempt->question_order;

        if (! is_array($order) || $order === []) {
            return $questions->values();
        }

        $ordered = collect();

        foreach ($order as $questionId) {
            $question = $questions->get((int) $questionId);

            if ($question) {
                $ordered->push($question);
            }
        }

        // Toute question absente de l'ordre figé est replacée en fin de liste
        // plutôt que perdue.
        foreach ($questions as $question) {
            if (! $ordered->contains('id', $question->id)) {
                $ordered->push($question);
            }
        }

        return $ordered->values();
    }

    /**
     * Enregistre les réponses en cours de tentative.
     *
     * Aucune bonne réponse n'est calculée ici : la correction a lieu à la
     * soumission, côté serveur (RG-13).
     *
     * @param  array<int, array{question_id: int, option_ids: array<int, int>}>  $answers
     */
    public function saveAnswers(Attempt $attempt, array $answers): void
    {
        $this->assertOwner($attempt);

        DB::transaction(function () use ($attempt, $answers) {
        $attempt = Attempt::whereKey($attempt->id)->lockForUpdate()->firstOrFail();

        if ($attempt->isSubmitted()) {
            throw new BusinessRuleException('Cette tentative a déjà été soumise.', 409);
        }

        /*
         * RG-12 : « Une tentative dont le temps est écoulé est soumise
         * automatiquement. »
         *
         * Une fois l'échéance dépassée, plus aucune réponse n'est acceptée :
         * elle ne doit être ni enregistrée, ni corrigée. `submitAttempt()`
         * finalize donc directement l'attempt en `expired` sans passer par
         * cette méthode ; ce garde-fou protège les appelants futurs.
         */
        if ($attempt->hasTimeExpired()) {
            throw new BusinessRuleException(
                'Le temps imparti est écoulé : les réponses ne sont plus acceptées.',
                409,
                ['expired' => true]
            );
        }

        $questionIds = $attempt->quiz->questions()->pluck('id')->map(static fn ($id) => (int) $id);

        foreach ($answers as $answer) {
            $questionId = (int) $answer['question_id'];

            if (! $questionIds->contains($questionId)) {
                throw new BusinessRuleException('Question inconnue pour ce quiz.', 422);
            }

            $optionIds = array_values(array_unique(array_map('intval', $answer['option_ids'] ?? [])));

            $validOptionIds = DB::table('options')
                ->where('question_id', $questionId)
                ->pluck('id')
                ->map(static fn ($id) => (int) $id);

            // On ne conserve que des options appartenant réellement à la question.
            $optionIds = array_values(array_intersect($optionIds, $validOptionIds->all()));

            AttemptAnswer::updateOrCreate(
                ['attempt_id' => $attempt->id, 'question_id' => $questionId],
                ['selected_option_ids' => $optionIds, 'is_correct' => false, 'awarded_score' => 0]
            );
        }
        });
    }

    /**
     * RG-12 : soumet et corrige. Idempotent — une tentative déjà soumise
     * renvoie son résultat sans être recalculée.
     *
     * T-15 : une soumission après la fin du temps est traitée comme expirée.
     */
    public function submit(Attempt $attempt, bool $forcedExpired = false): Attempt
    {
        $this->assertOwner($attempt);

        return $this->grade($attempt, $forcedExpired);
    }

    private function grade(Attempt $attempt, bool $forcedExpired = false): Attempt
    {

        if ($attempt->isSubmitted()) {
            return $attempt->load('answers.question.options');
        }

        $expired = $forcedExpired || $attempt->hasTimeExpired();

        return DB::transaction(function () use ($attempt, $expired) {
            $attempt = Attempt::whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            if ($attempt->isSubmitted()) {
                return $attempt->load('answers.question.options');
            }
            $attempt->load('answers.question.options');

            $questions = $attempt->quiz->questions()->with('options')->get()->keyBy('id');
            $answers = $attempt->answers->keyBy('question_id');

            $score = 0.0;

            foreach ($questions as $question) {
                /** @var AttemptAnswer|null $answer */
                $answer = $answers->get($question->id);

                $selected = $answer ? array_map('intval', (array) $answer->selected_option_ids) : [];
                $correct = array_map('intval', $question->correctOptionIds());

                $isCorrect = $this->evaluate($question->type, $selected, $correct);
                $awarded = $isCorrect ? 1.0 : 0.0;

                if ($answer) {
                    $answer->update(['is_correct' => $isCorrect, 'awarded_score' => $awarded]);
                }

                $score += $awarded;
            }

            $attempt->update([
                'score' => $score,
                'max_score' => $questions->count(),
                'submitted_at' => Carbon::now(),
                'expired' => $expired,
            ]);

            return $attempt->fresh('answers.question.options');
        });
    }

    /**
     * Correction.
     *  - single / true_false : correspondance exacte d'une seule option.
     *  - multiple : l'ensemble des options choisies doit être exactement
     *    l'ensemble des bonnes réponses (une réponse trop large ou trop
     *    étroite est fausse).
     */
    public function evaluate(QuestionType $type, array $selected, array $correct): bool
    {
        $selected = array_values(array_unique($selected));
        sort($selected);
        sort($correct);

        if ($selected === [] || $correct === []) {
            return false;
        }

        return $selected === $correct;
    }

    /**
     * RG-12 : si le temps est écoulé, la tentative est soumise
     * automatiquement avec les réponses déjà enregistrées.
     */
    public function autoSubmitIfExpired(Attempt $attempt): Attempt
    {
        if ($attempt->isSubmitted() || ! $attempt->hasTimeExpired()) {
            return $attempt;
        }

        return $this->submit($attempt, forcedExpired: true);
    }

    /**
     * Barème d'un quiz déjà validé : sert à figer `max_score` au démarrage
     * d'une tentative et à l'affichage des résultats enseignant.
     */
    public function maxScoreFor(Quiz $quiz): float
    {
        return (float) $quiz->questions()->count();
    }

    public function finalizeExpired(): int
    {
        $count = 0;
        Attempt::whereNull('submitted_at')->with('quiz')->chunkById(100, function ($attempts) use (&$count) {
            foreach ($attempts as $attempt) {
                if ($attempt->hasTimeExpired()) {
                    $this->grade($attempt, true);
                    $count++;
                }
            }
        });
        return $count;
    }

    public function isPublished(Quiz $quiz): bool
    {
        return $quiz->status === QuizStatus::Published->value;
    }

    /**
     * F-QUI-04 / T-27 — tentative en cours d'un étudiant sur un quiz.
     *
     * Reprendre une tentative déjà commencée est indispensable : sans cela,
     * un navigateur qui se ferme, un rechargement de page ou un changement
     * d'appareil fait perdre la tentative et consomme le quota de
     * `max_attempts` alors que l'étudiant ne l'a pas terminée.
     *
     * RG-13 : la reprise rend l'ordre FIGÉ au démarrage et les réponses déjà
     * enregistrées, jamais les bonnes réponses.
     */
    public function activeAttemptFor(User $student, Quiz $quiz): ?Attempt
    {
        $attempt = Attempt::where('quiz_id', $quiz->id)
            ->where('student_id', $student->id)
            ->whereNull('submitted_at')
            // La plus récente : une tentative expirée mais pas encore
            // finalisée ne doit pas masquer une tentative plus ancienne
            // encore jouable.
            ->orderByDesc('id')
            ->first();

        // RG-12 : une tentative dont le temps est écoulé est finalisée, pas
        // rendue reprenable — sinon l'étudiant pourrait la dépasser.
        if ($attempt && $attempt->hasTimeExpired()) {
            $this->grade($attempt, true);

            return null;
        }

        return $attempt;
    }

    private function assertOwner(Attempt $attempt): void
    {
        $student = auth()->user();

        if (! $student || $attempt->student_id !== $student->id) {
            throw new BusinessRuleException('Action non autorisée.', 403);
        }
    }
}
