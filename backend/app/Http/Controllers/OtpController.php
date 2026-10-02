<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Requests\OtpRequest;
use App\Http\Requests\OtpVerifyRequest;
use App\Http\Resources\UserResource;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * F-AUTH-02 — connexion de secours par code à 6 chiffres.
 * §12.1 : POST /auth/otp/request, POST /auth/otp/verify.
 */
class OtpController extends Controller
{
    public function __construct(private readonly OtpService $otp) {}

    /**
     * §12.1 — 202 (accepté, traitement en cours).
     *
     * La réponse est identique que l'adresse existe ou non : elle ne doit
     * permettre d'énumérer les comptes (RG-01).
     */
    public function request(OtpRequest $request): JsonResponse
    {
        $this->otp->request($request->string('email')->toString());

        return response()->json([
            'message' => 'Si cette adresse est autorisée, un code vient d\'être envoyé.',
        ], 202);
    }

    /**
     * §12.1 — 200 avec jeton, ou 422.
     * T-05 : code correct dans les 10 minutes -> connexion réussie.
     * T-06 : code expiré ou 6e essai -> erreur, aucun jeton émis.
     */
    public function verify(OtpVerifyRequest $request): JsonResponse
    {
        $result = $this->otp->verify(
            $request->string('email')->toString(),
            $request->string('code')->toString()
        );

        if (! $result['ok']) {
            return response()->json(['message' => $result['reason']], 422);
        }

        return response()->json([
            'token' => $result['token'],
            'user' => UserResource::forSelf($result['user']),
        ]);
    }
}
