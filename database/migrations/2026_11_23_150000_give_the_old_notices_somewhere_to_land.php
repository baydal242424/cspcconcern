<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Old notifications learn what they were about.
 *
 * Notices carry an anchor now -- "log-412", "notice-98" -- so opening one
 * scrolls to the thing it named and marks it. Everything written before
 * that column existed has none, which is most of a working bell: 621
 * status updates and 5 messages from a handler. Clicking any of them
 * drops the reader at the top of the concern, which is what the anchor
 * was added to stop.
 *
 * They can be matched. A notification is written in the SAME REQUEST as
 * the audit entry it describes, so the two sit within a second or so of
 * each other on the same concern. This pairs each notice with the nearest
 * matching entry at or before it, inside a ten-second window.
 *
 * Ten seconds rather than a minute: two status changes on one concern a
 * few seconds apart is possible, and a wrong anchor sends somebody to the
 * wrong line with a highlight saying "this one". A notice left unanchored
 * behaves exactly as it does today, so the cost of not matching is
 * nothing and the cost of matching wrongly is a lie.
 *
 * NOT backfilled: new_concern, which tells a handler a case has arrived
 * and is about the whole concern rather than a line in it; and
 * named_in_concern, which goes to somebody who cannot open the case at
 * all.
 */
return new class extends Migration
{
    public function up(): void
    {
        $pairs = [
            // The notice sent when a status changed, and the audit entry
            // that recorded the change.
            'status_update' => 'status_updated',
            // "You are asked to meet the person handling your concern",
            // and the entry carrying what they wrote.
            'notice_from_handler' => 'reporter_notified',
        ];

        foreach ($pairs as $type => $action) {
            DB::table('notifications')
                ->where('type', $type)
                ->whereNull('anchor')
                ->whereNotNull('concern_id')
                ->orderBy('id')
                ->chunkById(500, function ($notices) use ($action) {
                    foreach ($notices as $notice) {
                        // Ten seconds either side, not just before.
                        //
                        // The order of the two writes differs by path:
                        // update() records the status change and THEN
                        // notifies, while notifyReporter() creates the
                        // bell row first and writes the audit entry
                        // after. Looking only backwards matched every
                        // status update and not one message.
                        $at = strtotime($notice->created_at);

                        $log = DB::table('audit_logs')
                            ->where('concern_id', $notice->concern_id)
                            ->where('action', $action)
                            ->whereBetween('created_at', [
                                date('Y-m-d H:i:s', $at - 10),
                                date('Y-m-d H:i:s', $at + 10),
                            ])
                            ->orderByRaw('ABS(TIMESTAMPDIFF(SECOND, created_at, ?))', [$notice->created_at])
                            ->orderByDesc('id')
                            ->first();

                        if (! $log) {
                            continue;
                        }

                        DB::table('notifications')->where('id', $notice->id)->update([
                            'anchor' => ($action === 'status_updated' ? 'log-' : 'notice-').$log->id,
                        ]);
                    }
                });
        }
    }

    public function down(): void
    {
        // Only the ones this put there can be told apart from the ones the
        // application writes, and they cannot -- so nothing is cleared.
        // An anchor pointing at a real entry does no harm if it stays.
    }
};
