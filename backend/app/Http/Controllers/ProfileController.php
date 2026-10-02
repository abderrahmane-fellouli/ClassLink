<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Http\Resources\UserResource;
use App\Models\AuditLog;
use App\Services\TokenService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * §12.1 — GET / PATCH /me
 * F-AUTH-08 : profil = nom d'affichage + langue (FR/EN).
 */
class ProfileController extends Controller
{
    public function __construct(private readonly TokenService $tokens) {}

    public function show(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    public function update(Request $request): UserResource
    {
        $data = $request->validate([
            // Le rôle n'est PAS modifiable ici : §16 « Élévation de rôle ».
            'display_name' => ['sometimes', 'required', 'string', 'max:255'],
            // F-UI-01 / §1.5 : deux langues seulement, l'arabe est exclu.
            'locale' => ['sometimes', 'required', 'string', 'in:fr,en'],

            /*
             | Ces champs sont rejetés explicitement plutôt que silencieusement
             | ignorés : sans cela, un client qui envoie `role` recevrait un
             | 200 alors que rien n'a été appliqué, ce qui donne une fausse
             | impression de succès. RG-03 / RG-04.
             */
            'role' => ['prohibited'],
            'role_locked' => ['prohibited'],
            'is_active' => ['prohibited'],
            'email' => ['prohibited'],
        ]);

        $request->user()->update($data);

        return new UserResource($request->user()->fresh());
    }

    /**
     * « Se déconnecter de toutes les sessions » (écran Sécurité du profil).
     * F-AUTH-06.
     */
    public function destroySessions(Request $request): Response
    {
        $this->tokens->revokeAll($request->user());

        return response()->noContent();
    }
}
