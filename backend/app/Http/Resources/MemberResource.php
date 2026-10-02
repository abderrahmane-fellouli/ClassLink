<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * F-CLS-06 : « Liste des membres (nom d'affichage uniquement pour les
 * étudiants). »
 * T-22 : aucun email dans la réponse pour un étudiant.
 */
class MemberResource extends \Illuminate\Http\Resources\Json\JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $user = $this->resource->student;
        $classroom = $this->resource->classroom;

        /*
         * T-22 : « Liste des membres consultée par un étudiant -> Aucun
         * email dans la réponse. »
         *
         * L'email n'apparaît donc QUE pour le propriétaire de la classe
         * (F-CLS-07) et le super admin. Contrairement à /me, un étudiant
         * ne voit pas son propre email dans une liste : il le connaît
         * déjà, et la règle de confidentialité s'applique sans exception
         * pour rendre la réponse triviale à auditer.
         */
        $maySeeEmail = $viewer && $user && $classroom && (
            $viewer->isAdmin() || $classroom->isOwnedBy($viewer)
        );

        return array_filter([
            'membership_id' => $this->id,
            'status' => $this->status,
            'requested_at' => $this->requested_at?->toIso8601String(),
            'decided_at' => $this->decided_at?->toIso8601String(),

            'user' => $user ? array_filter([
                'id' => $user->id,
                'display_name' => $user->display_name,
                'initials' => $user->initials(),
                'role' => $user->role,
                'locale' => $user->locale,

                // RG-18 : réservé au propriétaire de la classe et à l'admin.
                'email' => $maySeeEmail ? $user->email : null,
            ], static fn ($v) => $v !== null) : null,
        ], static fn ($value) => $value !== null);
    }
}
