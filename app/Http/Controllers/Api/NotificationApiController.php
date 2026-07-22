<?php

namespace App\Http\Controllers\Api;

use App\Domain\Communication\Services\PreferenceManager;
use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationPreferenceResource;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationApiController extends Controller
{
    public function __construct(
        private readonly PreferenceManager $preferenceService
    ) {}

    /**
     * List user's notifications.
     */
    public function index(Request $request): JsonResponse
    {
        $notifications = $request->user()->notifications()
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return response()->json([
            'status' => 'success',
            'data' => NotificationResource::collection($notifications),
            'pagination' => [
                'total' => $notifications->total(),
                'per_page' => $notifications->perPage(),
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
            ],
        ]);
    }

    /**
     * Get unread notifications count.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $count = $request->user()->notifications()
            ->whereNull('read_at')
            ->count();

        return response()->json([
            'status' => 'success',
            'unread_count' => $count,
        ]);
    }

    /**
     * Mark an individual notification as read.
     */
    public function markAsRead(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        return response()->json([
            'status' => 'success',
            'message' => 'Notification marked as read.',
            'data' => new NotificationResource($notification),
        ]);
    }

    /**
     * Mark all user's notifications as read.
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        $request->user()->notifications()
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json([
            'status' => 'success',
            'message' => 'All notifications marked as read.',
        ]);
    }

    /**
     * Get user's notification preferences.
     */
    public function getPreferences(Request $request): JsonResponse
    {
        $prefs = $this->preferenceService->getPreferences($request->user());

        return response()->json([
            'status' => 'success',
            'data' => new NotificationPreferenceResource($prefs),
        ]);
    }

    /**
     * Update user's notification preferences.
     */
    public function updatePreferences(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email_enabled' => 'sometimes|boolean',
            'marketing_enabled' => 'sometimes|boolean',
            'order_notifications' => 'sometimes|boolean',
            'wallet_notifications' => 'sometimes|boolean',
            'security_notifications' => 'sometimes|boolean',
        ]);

        $prefs = $this->preferenceService->updatePreferences($request->user(), $validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Notification preferences updated.',
            'data' => new NotificationPreferenceResource($prefs),
        ]);
    }
}
