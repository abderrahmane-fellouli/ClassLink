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

    public function preferences(Request $request): JsonResponse
    {
        $preferences = $request->user()->notification_preferences ?? ['email_digest' => true, 'types' => []];
        $preferences['types'] = (object) ($preferences['types'] ?? []);
        return response()->json($preferences);
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $rules = ['email_digest' => ['required', 'boolean'], 'types' => ['present', 'array:'.implode(',', NotificationService::types())]];
        foreach (NotificationService::types() as $type) {
            $rules['types.'.$type] = ['sometimes', 'boolean'];
        }
        $data = $request->validate($rules);
        $request->user()->forceFill(['notification_preferences' => $data])->save();
        $data['types'] = (object) $data['types'];
        return response()->json($data);
    }

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
        $this->authorize('update', $notification);

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
