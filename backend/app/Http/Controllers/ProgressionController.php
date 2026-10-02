<?php

namespace App\Http\Controllers;

use App\Services\ProgressionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * §12.5 — GET /me/progress (étudiant), GET /classes/{id}/progress (enseignant).
 * F-PRO-01, F-PRO-02, F-PRO-03.
 */
class ProgressionController extends Controller
{
    public function __construct(private readonly ProgressionService $progression) {}

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->progression->forStudent($request->user()));
    }

    public function classroom(Request $request, \App\Models\Classroom $classroom): JsonResponse
    {
        $this->authorize('manage', $classroom);

        return response()->json($this->progression->forClassroom($classroom));
    }
}
