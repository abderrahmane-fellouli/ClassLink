<?php

namespace App\Services;

use App\Enums\MembershipStatus;
use App\Enums\PartnerRequestStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Classroom;
use App\Models\Membership;
use App\Models\PartnerProfile;
use App\Models\PartnerRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * F-PAR-01 à F-PAR-04, RG-17.
 *
 * RG-17 : « Le profil partenaire est désactivé par défaut. Il n'est visible
 * que par les membres des mêmes classes et n'affiche jamais l'email. »
 * F-PAR-04 (Must) : aucune adresse email n'est renvoyée par ces méthodes.
 */
class PartnerMatchingService
{
    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * F-PAR-01 — profil opt-in, créé à la première sauvegarde.
     */
    public function profileFor(User $student): PartnerProfile
    {
        return PartnerProfile::firstOrCreate(
            ['user_id' => $student->id],
            ['opt_in' => false, 'skills' => [], 'availability' => []]
        );
    }

    public function updateProfile(User $student, array $data): PartnerProfile
    {
        $profile = $this->profileFor($student);

        $profile->update([
            'opt_in' => (bool) ($data['opt_in'] ?? $profile->opt_in),
            'skills' => array_values($data['skills'] ?? $profile->skills ?? []),
            'availability' => array_values($data['availability'] ?? $profile->availability ?? []),
        ]);

        return $profile->fresh();
    }

    /**
     * F-PAR-02 — recherche de camarades « uniquement parmi les membres de
     * ses classes », et seulement ceux qui ont activé leur profil.
     *
     * @return array<int, array<string, mixed>>  aucun email n'est inclus
     */
    public function candidates(User $student, Classroom $classroom): array
    {
        if (! $classroom->hasAcceptedMember($student->id)) {
            throw new BusinessRuleException('Action non autorisée.', 403);
        }

        $myProfile = $this->profileFor($student);

        $peerIds = Membership::where('classroom_id', $classroom->id)
            ->where('status', MembershipStatus::Accepted->value)
            ->where('student_id', '!=', $student->id)
            ->pluck('student_id');

        $profiles = PartnerProfile::whereIn('user_id', $peerIds)
            ->where('opt_in', true) // RG-17 : opt-in obligatoire
            ->with('user:id,display_name')
            ->get();

        return $profiles->map(function (PartnerProfile $profile) use ($myProfile, $student) {
            $sharedSkills = array_values(array_intersect(
                (array) ($myProfile->skills ?? []),
                (array) ($profile->skills ?? [])
            ));

            $sharedSlots = array_values(array_intersect(
                (array) ($myProfile->availability ?? []),
                (array) ($profile->availability ?? [])
            ));

            $compatibility = $this->compatibility(
                count($sharedSkills),
                count($sharedSlots),
                count((array) ($myProfile->availability ?? [])) ?: null,
            );

            return [
                // Jamais d'email (F-PAR-04, Must).
                'user_id' => $profile->user_id,
                'display_name' => $profile->user?->display_name,
                'skills' => $profile->skills,
                'availability' => $profile->availability,
                'shared_skills' => $sharedSkills,
                'compatibility' => $compatibility,
            ];
        })
            ->sortByDesc('compatibility')
            ->values()
            ->all();
    }

    /** F-PAR-03 — demande de contact dans l'application. */
    public function request(User $from, User $to, Classroom $classroom): PartnerRequest
    {
        if ($from->id === $to->id) {
            throw new BusinessRuleException('Demande invalide.', 422);
        }

        if (! $classroom->hasAcceptedMember($from->id) || ! $classroom->hasAcceptedMember($to->id)) {
            throw new BusinessRuleException('Action non autorisée.', 403);
        }

        /*
         * RG-17 : « Le profil partenaire est désactivé par défaut. Il n'est
         * visible que par les membres des mêmes classes. »
         *
         * `candidates()` filtre déjà sur `opt_in`, mais un appel direct à
         * l'API connaissant l'identifiant du camarade ne passait pas par ce
         * filtre : le consentement doit être vérifié ici, sur le chemin
         * d'écriture. Un camarade sans profil est considéré comme n'ayant
         * rien accepté — on ne crée pas de profil à sa place.
         */
        if (! PartnerProfile::firstWhere('user_id', $to->id)?->opt_in) {
            throw new BusinessRuleException(
                'Ce camarade n\'accepte pas les demandes de contact.',
                403
            );
        }

        $existing = PartnerRequest::where('from_user_id', $from->id)
            ->where('to_user_id', $to->id)
            ->where('classroom_id', $classroom->id)
            ->first();

        if ($existing && $existing->status === PartnerRequestStatus::Pending->value) {
            throw new BusinessRuleException('Demande déjà envoyée.', 409);
        }

        $request = DB::transaction(function () use ($existing, $from, $to, $classroom) {
            return PartnerRequest::updateOrCreate(
                ['from_user_id' => $from->id, 'to_user_id' => $to->id, 'classroom_id' => $classroom->id],
                ['status' => PartnerRequestStatus::Pending->value]
            );
        });

        $this->notifications->notify($to, NotificationService::PARTNER_REQUEST_RECEIVED, [
            'request_id' => $request->id,
            'from_name' => $from->display_name,
            'classroom_name' => $classroom->name,
        ]);

        return $request->fresh();
    }

    /** F-PAR-03 — acceptation ou refus par le destinataire. */
    public function respond(User $user, PartnerRequest $request, string $status): PartnerRequest
    {
        if ($request->to_user_id !== $user->id) {
            throw new BusinessRuleException('Action non autorisée.', 403);
        }

        if (! in_array($status, [
            PartnerRequestStatus::Accepted->value,
            PartnerRequestStatus::Rejected->value,
        ], true)) {
            throw new BusinessRuleException('Statut invalide.', 422);
        }

        $request->update(['status' => $status]);

        $this->notifications->notify(
            $request->fromUser,
            NotificationService::PARTNER_REQUEST_ANSWERED,
            ['request_id' => $request->id, 'status' => $status]
        );

        return $request->fresh();
    }

    /**
     * Score de compatibilité 0-100 : compétences communes et créneaux
     * communs. Heuristique locale, aucun appel réseau.
     */
    private function compatibility(int $sharedSkills, int $sharedSlots, ?int $mySlots): int
    {
        $skillScore = min(100, $sharedSkills * 25);

        $slotScore = ($mySlots === null || $mySlots === 0)
            ? 0
            : min(100, (int) round(($sharedSlots / $mySlots) * 100));

        return (int) round(($skillScore * 0.6) + ($slotScore * 0.4));
    }
}
