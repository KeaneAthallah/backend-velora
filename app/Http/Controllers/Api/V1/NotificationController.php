<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\UserNotification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $query = $this->inbox($request);

        if ($request->boolean('unread_only')) {
            $query->unread();
        }
        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        return $this->success([
            'notifications' => $query->orderByDesc('id')
                ->paginate((int) $request->input('per_page', 25))
                ->through(fn (UserNotification $notification) => $this->row($notification)),
            'unread_count' => $this->inbox($request)->unread()->count(),
        ]);
    }

    public function unreadCount(Request $request)
    {
        return $this->success(['unread_count' => $this->inbox($request)->unread()->count()]);
    }

    public function markRead(Request $request, UserNotification $notification)
    {
        abort_unless($notification->user_id === $request->user()->id, 404);

        if (! $notification->read_at) {
            $notification->update(['read_at' => now()]);
        }

        return $this->success(['notification' => $this->row($notification->fresh())], 'Notification read');
    }

    public function markAllRead(Request $request)
    {
        $updated = $this->inbox($request)->unread()->update(['read_at' => now()]);

        return $this->success(['updated' => $updated], 'Notifications marked read');
    }

    public function destroy(Request $request, UserNotification $notification)
    {
        abort_unless($notification->user_id === $request->user()->id, 404);

        $notification->delete();

        return $this->success(null, 'Notification deleted');
    }

    private function inbox(Request $request)
    {
        $businessId = $request->attributes->get('business_id');

        return UserNotification::where('user_id', $request->user()->id)
            ->where(fn ($query) => $query->whereNull('business_id')->orWhere('business_id', $businessId));
    }

    private function row(UserNotification $notification): array
    {
        return [
            'id' => (string) $notification->id,
            'type' => $notification->type,
            'title' => $notification->title,
            'body' => $notification->body,
            'data' => $notification->data,
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }
}
