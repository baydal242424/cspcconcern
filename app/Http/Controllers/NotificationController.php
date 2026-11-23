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
    /**
     * Everything, not just the last ten.
     *
     * The bell holds ten, because that query runs on every page load and
     * one that grows with the account would be paid for on every screen.
     * The cost of the cap was that a notice scrolled off the bottom and
     * was gone -- a student asked for a document on Monday had no way
     * back to the request on Friday.
     *
     * Paged rather than infinite: a page of twenty-five with a link at
     * the foot is the same idea as "See previous notifications", and it
     * works with the back button, which an endless list does not.
     */
    public function index(Request $request)
    {
        // Ten at a time, matching what the bell opens with, so "see
        // previous" adds another panel's worth rather than a page's.
        $notifications = Notification::where('user_id', Auth::id())
            ->with('concern')
            ->latest()
            ->paginate(10);

        // Asked for by the bell: just the rows, to be appended to the list
        // already on screen. No layout, no panel, nothing that would make
        // the dropdown jump when it arrives.
        //
        // The header says whether there is anything after this, so the
        // script can retire the button instead of offering a click that
        // returns nothing.
        if ($request->boolean('partial')) {
            return response(
                view('partials.bell-rows', ['rows' => $notifications->getCollection()])->render()
            )->header('X-Has-More', $notifications->hasMorePages() ? '1' : '0');
        }

        return view('notifications.index', [
            'notifications' => $notifications,
            'unreadTotal' => Notification::where('user_id', Auth::id())
                ->where('is_read', false)
                ->count(),
        ]);
    }

    public function read(Request $request, string $notificationId)
    {
        // Looked up rather than route-bound, so a row that is no longer
        // there is something this can answer instead of a bare 404.
        //
        // The bell is the one part of the app people leave open. A tab
        // from this morning still shows rows that the retention job has
        // since purged along with their concern, and clicking one of
        // those gave a blank Not Found. There is nothing to fail at:
        // marking a notification read when it no longer exists has
        // already achieved what it was for, so it ends where reading one
        // normally ends.
        $notification = Notification::find($notificationId);

        if (! $notification) {
            return redirect()->route('concerns.index');
        }

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
        // Being called in to the office is the one notice with somewhere
        // better to go than the concern: the letter they are expected to
        // bring. They cannot open the case, but the letter is theirs.
        if ($notification->type === \App\Services\ConcernNotificationService::TYPE_CALLED_IN
            && $notification->concern_id) {
            $summons = \App\Models\ConcernSummons::where('concern_id', $notification->concern_id)
                ->where('user_id', Auth::id())
                ->latest('id')
                ->first();

            if ($summons) {
                return redirect()->route('concerns.summons.letter', [
                    $notification->concern_id,
                    $summons->id,
                ]);
            }
        }

        if ($notification->concern_id && $notification->concern) {
            // A notice can belong to somebody who may not open the
            // concern behind it: the person a complaint names is told
            // an investigation has begun and is walled out of the case
            // itself. Sending them to a 403 would answer a reasonable
            // click with an accusation they cannot read.
            $mayOpen = \App\Models\Concern::visibleTo(Auth::user())
                ->whereKey($notification->concern_id)
                ->exists();

            if (! $mayOpen) {
                return redirect()->route('concerns.index');
            }

            // Straight to the thing the notice is about, where it named
            // one. "There is a new note on your concern" that drops the
            // reader at the top of a case with eighteen timeline entries
            // and a dozen notes has not told them which.
            //
            // The fragment is appended rather than passed as a route
            // parameter: route() would percent-encode the '#' into the
            // path and produce an address that does not exist.
            $url = route('concerns.show', $notification->concern_id);

            if ($notification->anchor) {
                $url .= '#'.$notification->anchor;
            }

            return redirect($url);
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
