<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * §16 « Fuite de données personnelles : … réponses API filtrées par des
 * ressources dédiées ».
 *
 * T-22 : « Liste des membres consultée par un étudiant -> Aucun email dans
 * la réponse. »
 * RG-18 : l'email n'est visible que par l'intéressé, l'enseignant de sa
 * classe et le super admin.
 *
 * Règle appliquée par défaut : `email` n'est sérialisé que si la politique
 * l'autorise pour le demandeur. Un appelant ne peut donc pas obtenir un
 * email « par accident » en oubliant d'appliquer une règle quelque part.
 */
class UserResource extends \Illuminate\Http\Resources\Json\JsonResource
{
    /**
     * Reponse de connexion : le demandeur vient de prouver qu'il detient
     * cette adresse (Microsoft, ou code a usage unique), l'email peut donc
     * lui etre renvoye meme si la requete n'est pas encore authentifiee.
     */
    protected bool $forceEmail = false;

    public static function forSelf(mixed $user): self
    {
        return (new self($user))->withForcedEmail();
    }

    public function withForcedEmail(): static
    {
        $this->forceEmail = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        return array_filter([
            'id' => $this->id,
            'display_name' => $this->display_name,
            'role' => $this->role,
            'locale' => $this->locale,
            'is_active' => (bool) $this->is_active,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'initials' => $this->initials(),

            // RG-18 : present uniquement si la politique l'autorise.
            'email' => $this->forceEmail || $this->mayShowEmail() ? $this->email : null,
        ], static fn ($value) => $value !== null);
    }

    /**
     * L'email est visible par l'intéressé, l'enseignant d'une classe dont
     * l'utilisateur est membre accepté, et le super admin.
     */
    private function mayShowEmail(): bool
    {
        $viewer = request()->user();

        if (! $viewer) {
            return false;
        }

        if ((int) $viewer->id === (int) $this->id) {
            return true;
        }

        return Gate::forUser($viewer)->allows('viewEmail', $this->resource);
    }
}
