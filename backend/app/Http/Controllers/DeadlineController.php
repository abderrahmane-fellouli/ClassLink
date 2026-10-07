<?php

namespace App\Http\Controllers;

use App\Http\Resources\DeadlineResource;
use App\Models\Assignment;
use App\Models\Quiz;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeadlineController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $classes = $request->user()->acceptedClassrooms()->where('status', 'active')->pluck('id');
        $assignments = Assignment::whereIn('classroom_id', $classes)->where('publication_status', 'published')->whereNotNull('due_at')
            ->whereDoesntHave('submissions', fn ($q) => $q->where('student_id', $request->user()->id))
            ->with('classroom:id,name')->get();
        $quizzes = Quiz::whereIn('classroom_id', $classes)->published()->whereNotNull('due_at')
            ->whereDoesntHave('attempts', fn ($q) => $q->where('student_id', $request->user()->id)->whereNotNull('submitted_at'))
            ->with('classroom:id,name')->get();

        return response()->json(['data' => DeadlineResource::collection(
            $assignments->toBase()->concat($quizzes)->sortBy('due_at')->values()
        )]);
    }
}
