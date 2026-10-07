<?php

namespace App\Policies;

use App\Models\Classroom;
use App\Models\User;

/**
 * §17.7 — extrait de référence, reproduit à l'identique.
 *
 * §12 : « Les autorisations sont vérifiées côté serveur par des policies
 * Laravel : le rôle, puis la propriété de la classe ou l'adhésion
 * acceptée. »
 */
class ClassroomPolicy
{
    /** RG-04 : un enseignant ne gère que ses propres classes. */
    public function manage(User $user, Classroom $class): bool
    {
        if ($class->is_official) { return $class->isOwnedBy($user); }
        return $user->role === 'admin'
            || ($user->role === 'teacher' && $class->teacher_id === $user->id);
    }

    /**
     * RG-05 : un étudiant n'accède au contenu d'une classe que si son
     * adhésion est « accepted ».
     */
    public function view(User $user, Classroom $class): bool
    {
        if ($class->is_official) { return app(\App\Services\SchoolAccess::class)->canView($user, $class); }
        return $this->manage($user, $class)
            || $class->memberships()
                ->where('student_id', $user->id)
                ->where('status', 'accepted')
                ->exists();
    }

    /** F-REQ-08 : retirer un étudiant. */
    public function removeMember(User $user, Classroom $class): bool
    {
        return $this->modify($user, $class);
    }

    public function decideJoinRequest(User $user, Classroom $class): bool
    {
        return $this->manage($user, $class);
    }

    public function transfer(User $user, Classroom $class): bool
    {
        return $user->isAdmin() && ! $class->isReadOnly();
    }

    public function archive(User $user, Classroom $class): bool
    {
        return $this->manage($user, $class);
    }

    /**
     * §12.4 / F-DEV-01 : création d'un devoir.
     *
     * `AssignmentController::store()` appelle `authorize('create', $class)` ;
     * sans cette ability, la création d'un devoir renvoyait 403 à
     * l'enseignant légitime.
     *
     * RG-04 : seul le propriétaire de la classe crée son contenu, et
     * RG-10 : pas dans une classe archivée. Le super-administrateur ne fait
     * pas partie des capacités documentées en §12.6 (F-ADM-01 à F-ADM-06 :
     * utilisateurs, classes, fournisseurs IA, journaux, statistiques) : il
     * ne crée donc pas de devoir à la place d'un enseignant.
     */
    public function create(User $user, Classroom $class): bool
    {
        if ($class->is_official) { return $class->isOwnedBy($user) && ! $class->isReadOnly(); }
        return $user->isTeacher()
            && $class->teacher_id === $user->id
            && ! $class->isReadOnly();
    }

    /**
     * RG-10 : une classe archivée est en lecture seule. Le propriétaire peut
     * encore l'archiver/désarchiver, mais plus rien d'autre.
     */
    public function modify(User $user, Classroom $class): bool
    {
        return $this->manage($user, $class) && ! $class->isReadOnly();
    }

    /**
     * §12.4 / F-IA-01 : la génération IA appartient au propriétaire de la
     * classe. Sans cette ability, `authorize('generate', $classroom)`
     * refuse tout le monde, y compris l'enseignant légitime.
     */
    public function generate(User $user, Classroom $class): bool
    {
        return $this->manage($user, $class);
    }
}
