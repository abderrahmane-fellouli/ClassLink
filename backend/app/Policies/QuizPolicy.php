<?php

namespace App\Policies;

use App\Enums\QuizStatus;
use App\Models\Quiz;
use App\Models\User;

/**
 * §12.4 — GET/PATCH/DELETE /quizzes/{id} : « Membre ou Propriétaire ».
 */
class QuizPolicy
{
    /**
     * RG-11 : un quiz en brouillon est invisible des étudiants. L'enseignant
     * propriétaire voit toujours le sien.
     */
    public function view(User $user, Quiz $quiz): bool
    {
        if ($quiz->classroom->isOwnedBy($user)) {
            return true;
        }

        return $quiz->isPublished() && $quiz->classroom->hasAcceptedMember($user->id);
    }

    public function update(User $user, Quiz $quiz): bool
    {
        return $quiz->classroom->isOwnedBy($user) && ! $quiz->classroom->isReadOnly();
    }

    /**
     * F-QUI-01 / US-25 — ajout, modification, réordonnancement et suppression
     * d'une question existante.
     *
     * Réservé au **brouillon** : modifier les questions d'un quiz publié
     * changerait l'épreuve sous les pieds des étudiants qui ont déjà répondu,
     * et rendrait les résultats déjà calculés incohérents avec l'énoncé.
     */
    public function manageQuestions(User $user, Quiz $quiz): bool
    {
        return $quiz->status === QuizStatus::Draft->value
            && $quiz->classroom->isOwnedBy($user)
            && ! $quiz->classroom->isReadOnly();
    }

    public function delete(User $user, Quiz $quiz): bool
    {
        return $this->update($user, $quiz) && ! $quiz->attempts()->exists();
    }

    /** F-QUI-03 / §12.4 : POST /quizzes/{id}/publish — propriétaire. */
    public function publish(User $user, Quiz $quiz): bool
    {
        return $quiz->classroom->isOwnedBy($user) && ! $quiz->classroom->isReadOnly();
    }

    /** §12.4 : POST /quizzes/{id}/attempts — étudiant membre. */
    public function attempt(User $user, Quiz $quiz): bool
    {
        return $quiz->isPublished()
            && $user->isStudent()
            && ! $quiz->classroom->isReadOnly()
            && $quiz->classroom->hasAcceptedMember($user->id);
    }

    /** F-QUI-07 : résultats de la classe — propriétaire. */
    public function results(User $user, Quiz $quiz): bool
    {
        return $quiz->classroom->isOwnedBy($user);
    }
}
