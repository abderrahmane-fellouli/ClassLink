<?php

namespace App\Http\Controllers;

use App\Http\Resources\PartnerProfileResource;
use App\Http\Resources\PartnerRequestResource;
use App\Models\Classroom;
use App\Models\PartnerRequest;
use App\Services\PartnerMatchingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * §12.5 — Partenaires d'étude.
 * F-PAR-04 (Must) : aucune adresse email n'est renvoyée par ce contrôleur.
 */
class PartnerController extends Controller
{
    public function __construct(private readonly PartnerMatchingService $partners) {}

    /** §12.5 — GET /me/partner-profile. F-PAR-01. */
    public function profile(Request $request): PartnerProfileResource
    {
        return new PartnerProfileResource($this->partners->profileFor($request->user()));
    }

    /** §12.5 — PUT /me/partner-profile. */
    public function updateProfile(Request $request): PartnerProfileResource
    {
        $data = $request->validate([
            'opt_in' => ['required', 'boolean'],
            'skills' => ['nullable', 'array', 'max:20'],
            'skills.*' => ['string', 'max:60'],
            'availability' => ['nullable', 'array', 'max:20'],
            'availability.*' => ['string', 'max:60'],
        ], [], ['opt_in' => 'inscription volontaire']);

        return new PartnerProfileResource($this->partners->updateProfile($request->user(), $data));
    }

    /** §12.5 — GET /classes/{id}/partners. F-PAR-02. */
    public function candidates(Request $request, Classroom $classroom): JsonResponse
    {
        $this->authorize('view', $classroom);

        return response()->json([
            'data' => $this->partners->candidates($request->user(), $classroom),
        ]);
    }

    /** §12.5 — POST /partner-requests. F-PAR-03. */
    public function request(Request $request): JsonResponse
    {
        $data = $request->validate([
            'to_user_id' => ['required', 'integer', 'exists:users,id'],
            'classroom_id' => ['required', 'integer', 'exists:classrooms,id'],
        ], [], ['to_user_id' => 'camarade', 'classroom_id' => 'classe']);

        $this->authorize('create', [PartnerRequest::class, Classroom::findOrFail($data['classroom_id'])]);

        $partnerRequest = $this->partners->request(
            $request->user(),
            \App\Models\User::findOrFail($data['to_user_id']),
            Classroom::findOrFail($data['classroom_id'])
        );

        return response()->json(new PartnerRequestResource($partnerRequest), 201);
    }

    /** §12.5 — POST /partner-requests/{id}/respond. F-PAR-03. */
    public function respond(Request $request, PartnerRequest $partnerRequest): PartnerRequestResource
    {
        $this->authorize('respond', $partnerRequest);

        $data = $request->validate([
            'status' => ['required', 'string', 'in:accepted,rejected'],
        ], [], ['status' => 'réponse']);

        return new PartnerRequestResource(
            $this->partners->respond($request->user(), $partnerRequest, $data['status'])
        );
    }

    /** Mes demandes (sortantes et entrantes). */
    public function myRequests(Request $request): JsonResponse
    {
        $requests = PartnerRequest::where('from_user_id', $request->user()->id)
            ->orWhere('to_user_id', $request->user()->id)
            ->with(['fromUser', 'toUser', 'classroom'])
            ->latest()
            ->get();

        return response()->json(['data' => PartnerRequestResource::collection($requests)]);
    }
}
