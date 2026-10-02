<?php

namespace App\Http\Controllers;

use App\Http\Resources\NotificationResource;
use App\Models\AppNotification;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** §12.5 — F-NOT-01 : notifications dans l'application. */
class NotificationController extends Controller
{
    public function __construct(private readonly NotificationService $notifications) {}

    /** GET /notifications. */
    public function index(Request $request): JsonResponse
    {
        $items = AppNotification::where('user_id', $request->user()->id)
            ->latest()
            ->limit(50)
            ->get();

        return response()->json([
            'data' => NotificationResource::collection($items),
            'unread_count' => $this->notifications->unreadCount($request->user()->id),
        ]);
    }

    /** POST /notifications/{id}/read. */
    public function markRead(Request $request, AppNotification $notification): Response
    {
        if ($notification->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Accès refusé.'], 403);
        }

        $notification->update(['read_at' => now()]);

        return response()->noContent();
    }

    /** POST /notifications/read-all. */
    public function markAllRead(Request $request): Response
    {
        AppNotification::where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->noContent();
    }
}
