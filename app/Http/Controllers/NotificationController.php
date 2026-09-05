<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The navbar bell. Notification rows have been written on every concern
 * status change since the notification service was added, but until now
 * nothing displayed them -- a student only found out about an update by
 * opening the concern and looking.
 *
 * Everything here is scoped to the signed-in user's own notifications.
 * A notification carries a concern title and status, so reading someone
 * else's would leak which cases exist and who is involved in them.
 */
class NotificationController extends Controller
{
    /**
     * Mark one notification read and continue to the concern it is about.
     *
     * This is a POST, not a GET link: it changes state, so a prefetcher or a
     * third-party page must not be able to silently clear someone's unread
     * badge.
     */
    public function read(Request $request, Notification $notification)
    {
        // Scoped, not just "does it exist" -- otherwise anyone could mark
        // (and by ID, enumerate) another user's notifications.
        if ($notification->user_id !== Auth::id()) {
            abort(403);
        }

        if (! $notification->is_read) {
            $notification->update([
                'is_read' => true,
                'read_at' => now(),
            ]);
        }

        // The concern may since have been deleted; fall back to the list
        // rather than 404-ing on a stale notification.
        if ($notification->concern_id && $notification->concern) {
            return redirect()->route('concerns.show', $notification->concern_id);
        }

        // A reactivation request is about an ACCOUNT, not a concern, so it
        // opens the page where it can be acted on. Sending an Admin to the
        // concern list would leave them hunting for the student by hand.
        if ($notification->type === 'reactivation_request') {
            return redirect()->route('admin.users');
        }

        return redirect()->route('concerns.index');
    }

    /**
     * Clear the badge in one go.
     */
    public function readAll(Request $request)
    {
        Notification::where('user_id', Auth::id())
            ->where('is_read', false)
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);

        return back()->with('success', 'All notifications marked as read.');
    }
}
