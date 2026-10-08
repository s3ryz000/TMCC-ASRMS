<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Student: the portal notification bell (#89). Always the signed-in
 * student's own notifications; another student's id is simply not found.
 */
class NotificationController extends Controller
{
    use AuthorizesRole;

    private const PER_PAGE = 15;

    /**
     * GET /api/student/notifications: newest first, paginated, with the
     * unread count.
     */
    public function index(Request $request): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['student'])) {
            return $err;
        }

        $user = $request->user();
        // notifications() is newest first (latest()).
        $page = $user->notifications()->paginate(self::PER_PAGE);
        $page->getCollection()->transform(fn (DatabaseNotification $n) => $this->present($n));

        return response()->json([
            ...$page->toArray(),
            'unread_count' => $user->unreadNotifications()->count(),
        ]);
    }

    /** PATCH /api/student/notifications/{id}/read */
    public function markRead(Request $request, string $id): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['student'])) {
            return $err;
        }

        $notification = $request->user()->notifications()->whereKey($id)->first();
        if (! $notification) {
            return response()->json(['message' => 'Notification not found.'], 404);
        }

        $notification->markAsRead();

        return response()->json([
            'notification' => $this->present($notification),
            'unread_count' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    /** PATCH /api/student/notifications/read-all */
    public function markAllRead(Request $request): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['student'])) {
            return $err;
        }

        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['unread_count' => 0]);
    }

    private function present(DatabaseNotification $n): array
    {
        return [
            'id' => $n->id,
            'kind' => $n->data['kind'] ?? null,
            'status' => $n->data['status'] ?? null,
            'message' => $n->data['message'] ?? '',
            'link' => $n->data['link'] ?? null,
            'read_at' => $n->read_at?->toDateTimeString(),
            'created_at' => $n->created_at?->toDateTimeString(),
        ];
    }
}
