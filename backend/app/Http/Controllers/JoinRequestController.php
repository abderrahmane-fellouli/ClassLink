<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Http\Resources\MembershipResource;
use App\Models\Membership;
use App\Services\MembershipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * §12.2 — POST /join-requests, GET /join-requests/mine.
 * §17.7 : logique de référence, reproduite à l'identique.
 */
class JoinRequestController extends Controller
{
    public function __construct(private readonly MembershipService $memberships) {}

    /**
     * §17.7 — T-07 : statut pending + notification.
     * T-08 : 409 sur doublon. T-09 : 429 dans le délai. T-10 : 404 code invalide.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'size:8'],
        ], [], ['code' => 'code d\'invitation']);

        $membership = $this->memberships->request($request->user(), $data['code']);

        return response()->json(new MembershipResource($membership->load('classroom.teacher')), 201);
    }

    /** F-REQ-06 — suivi du statut de mes demandes. */
    public function mine(Request $request): JsonResponse
    {
        $memberships = Membership::where('student_id', $request->user()->id)
            ->with('classroom.teacher')
            ->latest('requested_at')
            ->get();

        return response()->json([
            'data' => MembershipResource::collection($memberships),
        ]);
    }
}
