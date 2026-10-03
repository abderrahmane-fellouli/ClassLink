<?php

namespace App\Policies;

use App\Models\Classroom;
use App\Models\User;

/**
 * F-QUI-08 (Should) — révision par flashcards.
 * Un deck n'est visible des étudiants que s'il est publié ET si l'étudiant
 * a une adhésion acceptée (même règle que les quiz, RG-05).
 */
class FlashcardDeckPolicy
{
    public function view(User $user, $deck): bool
    {
        return $deck->classroom->isOwnedBy($user)
            || ($deck->isPublished() && $deck->classroom->hasAcceptedMember($user->id));
    }

    public function manage(User $user, Classroom $classroom): bool
    {
        return $classroom->isOwnedBy($user) && ! $classroom->isReadOnly();
    }

    public function update(User $user, $deck): bool
    {
        return $this->manage($user, $deck->classroom);
    }

    public function delete(User $user, $deck): bool
    {
        return $this->update($user, $deck);
    }

    /**
     * §11 (F-QUI-08) : memorisation « su » / « à revoir » par l'etudiant.
     *
     * Meme regle que `view` : le deck doit etre publie et l'etudiant avoir
     * une adhesion acceptee. Un brouillon reste donc invisible, et l'etat
     * d'un autre eleve n'est ni lisible ni modifiable.
     */
    public function review(User $user, $deck): bool
    {
        return $this->view($user, $deck) && ! $deck->classroom->isReadOnly();
    }
}
