<?php

namespace App\Policies;

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

    public function delete(User $user, Quiz $quiz): bool
    {
        return $this->update($user, $quiz);
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
            && $quiz->classroom->hasAcceptedMember($user->id);
    }

    /** F-QUI-07 : résultats de la classe — propriétaire. */
    public function results(User $user, Quiz $quiz): bool
    {
        return $quiz->classroom->isOwnedBy($user);
    }
}
