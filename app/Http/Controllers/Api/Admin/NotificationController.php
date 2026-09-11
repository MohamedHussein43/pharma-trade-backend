<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    // =========================================================
    // GET /api/v1/notifications
    // Returns paginated notifications for the authenticated user.
    // Unread notifications come first, then read ones.
    // =========================================================
    public function index(Request $request): JsonResponse
    {
        $notifications = Notification::where('user_id', $request->user()->id)
            ->orderBy('is_read', 'asc')        // unread first
            ->orderBy('created_at', 'desc')    // newest first within each group
            ->paginate($request->get('per_page', 20));

        $notifications->getCollection()->transform(fn($n) => [
            'id'              => $n->id,
            'title'           => $n->title,
            'body'            => $n->body,
            'type'            => $n->type,
            'notifiable_type' => $n->notifiable_type,
            'notifiable_id'   => $n->notifiable_id,
            'is_read'         => (bool)$n->is_read,
            'created_at'      => $n->created_at,
        ]);

        return response()->json([
            'message' => 'Notifications retrieved successfully.',
            'data'    => $notifications,
        ], 200);
    }

    // =========================================================
    // GET /api/v1/notifications/unread-count
    // Returns the count of unread notifications.
    // Flutter uses this for the badge number on the bell icon.
    // =========================================================
    public function unreadCount(Request $request): JsonResponse
    {
        $count = Notification::where('user_id', $request->user()->id)
            ->where('is_read', 0)
            ->count();

        return response()->json([
            'message' => 'Unread count retrieved.',
            'data'    => ['count' => $count],
        ], 200);
    }

    // =========================================================
    // PATCH /api/v1/notifications/{id}/read
    // Marks a single notification as read.
    // Called when user taps a notification row and navigates
    // to the relevant screen.
    // =========================================================
    public function markRead(Request $request, int $id): JsonResponse
    {
        $notification = Notification::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->first();

        if (! $notification) {
            return response()->json(['message' => 'Notification not found.'], 404);
        }

        $notification->update(['is_read' => 1]);

        return response()->json([
            'message' => 'Notification marked as read.',
            'data'    => ['id' => $notification->id, 'is_read' => true],
        ], 200);
    }

    // =========================================================
    // PATCH /api/v1/notifications/read-all
    // Marks ALL notifications as read for the current user.
    // Called when user opens the notifications screen.
    // =========================================================
    public function markAllRead(Request $request): JsonResponse
    {
        $updated = Notification::where('user_id', $request->user()->id)
            ->where('is_read', 0)
            ->update(['is_read' => 1]);

        return response()->json([
            'message' => 'All notifications marked as read.',
            'data'    => ['updated_count' => $updated],
        ], 200);
    }

    // =========================================================
    // DELETE /api/v1/notifications/{id}
    // Deletes a single notification.
    // =========================================================
    public function destroy(Request $request, int $id): JsonResponse
    {
        $notification = Notification::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->first();

        if (! $notification) {
            return response()->json(['message' => 'Notification not found.'], 404);
        }

        $notification->delete();

        return response()->json(['message' => 'Notification deleted.'], 200);
    }

    // =========================================================
    // DELETE /api/v1/notifications
    // Clears ALL notifications for the current user.
    // =========================================================
    public function destroyAll(Request $request): JsonResponse
    {
        $deleted = Notification::where('user_id', $request->user()->id)->delete();

        return response()->json([
            'message' => 'All notifications cleared.',
            'data'    => ['deleted_count' => $deleted],
        ], 200);
    }
}
