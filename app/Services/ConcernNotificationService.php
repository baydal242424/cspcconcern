<?php

namespace App\Services;

use App\Models\Concern;
use App\Models\Notification;
use App\Models\User;
use App\Notifications\ConcernAssigned;
use App\Notifications\ConcernStatusUpdated;
use Illuminate\Support\Facades\Log;

/**
 * The single place that decides who gets told what when a concern moves.
 *
 * Every concern notification -- the in-app row AND the email to the person's
 * CSPC address -- is created here. ConcernController no longer builds
 * notification payloads itself; it just says "this concern changed status"
 * or "this concern was assigned" and lets this class work out the wording,
 * the recipient, and the delivery.
 *
 * Why a service and not a controller: a Laravel controller's job is to turn
 * an HTTP request into a response. Nothing here is triggered by a URL --
 * these run as a side effect of a concern changing, and the same call is
 * needed from the controller, from a future scheduled reminder, and from
 * tests. A controller cannot be reused that way; a service can.
 *
 * Two rules everything in this file follows:
 *
 *  1. NO CASE CONTENT IN EMAIL. Subjects and bodies carry a case number and
 *     a status, never the category, the description, the reporter's name, or
 *     who is handling it. Once a message is in a mailbox it is outside
 *     everything Concern::scopeVisibleTo() can enforce -- it can be
 *     forwarded, read on a shared PC, or previewed on a lock screen. A
 *     subject like "Update on your bullying report" would leak the sensitive
 *     part to anyone glancing at the phone.
 *
 *  2. EMAIL FAILURE IS NEVER FATAL. By the time we are called, the concern
 *     update and its audit row are already committed. A dead SMTP host must
 *     not turn a successful status change into a 500 and lose the staff
 *     member's work. Failures are logged and swallowed; a missed email is
 *     recoverable, a lost case update is not.
 */
class ConcernNotificationService
{
    /**
     * Human-readable wording for each status the reporter can be told about.
     * Keyed by the raw DB value, because "in_progress" is not something a
     * student should ever be shown.
     *
     * @var array<string, array{label: string, line: string}>
     */
    private const STATUS_COPY = [
        'submitted' => [
            'label' => 'Received',
            'line' => 'Your concern has been received and is waiting to be picked up by the right office.',
        ],
        'in_progress' => [
            'label' => 'In progress',
            'line' => 'Someone is now actively working on your concern.',
        ],
        'referred' => [
            'label' => 'Referred',
            'line' => 'Your concern has been passed to another office that is better placed to handle it. It is still being tracked.',
        ],
        'resolved' => [
            'label' => 'Resolved',
            'line' => 'Your concern has been marked resolved. You can now rate how it was handled.',
        ],
        // Worded to send the student to the case rather than explain in the
        // email: the closure reason is case content, and rule 1 above keeps
        // case content out of the mail.
        'closed_no_action' => [
            'label' => 'Closed',
            'line' => 'Your concern was reviewed and closed without further action. Sign in to read the reason given.',
        ],
    ];

    /**
     * Tell the REPORTER their concern moved to a new status.
     *
     * $status is the raw column value ('in_progress', 'referred', ...); the
     * wording shown to the student comes from STATUS_COPY above.
     */
    public function statusChanged(Concern $concern, string $status): void
    {
        $copy = self::STATUS_COPY[$status] ?? [
            'label' => ucfirst(str_replace('_', ' ', $status)),
            'line' => 'Your concern has been updated.',
        ];

        $row = Notification::create([
            'user_id' => $concern->user_id,
            'type' => 'status_update',
            'concern_id' => $concern->id,
            'title' => 'Concern Status Updated',
            'message' => "Your concern status has been updated to {$copy['label']}",
        ]);

        $this->deliver(
            $concern->user,
            new ConcernStatusUpdated($concern, $copy['label'], $copy['line']),
            $row
        );
    }

    /**
     * Tell a STAFF MEMBER a concern has landed on their desk -- so a Critical
     * case does not wait until the next time they happen to open the app.
     */
    public function assigned(Concern $concern): void
    {
        if (! $concern->assigned_to) {
            return;
        }

        $severity = $concern->urgency ?? 'untriaged';

        $row = Notification::create([
            'user_id' => $concern->assigned_to,
            'type' => 'new_concern',
            'concern_id' => $concern->id,
            'title' => 'New Concern Assigned',
            'message' => "A new {$severity} {$concern->category} concern has been assigned to you and needs triage",
        ]);

        $this->deliver($concern->assignedUser, new ConcernAssigned($concern), $row);
    }

    /**
     * Send one notification to the recipient's CSPC address and record on the
     * notifications row whether it actually went out (the email_sent column).
     *
     * Because registration and Google sign-in both reject any domain other
     * than @my.cspc.edu.ph / @cspc.edu.ph (see AuthController), $user->email
     * is always the CSPC address -- there is no separate field to look up.
     *
     * See rule 2 in the class docblock for why the catch is deliberately
     * broad and silent to the caller.
     */
    private function deliver(?User $recipient, $notification, Notification $row): void
    {
        if (! $recipient || ! $recipient->email) {
            return;
        }

        try {
            $recipient->notify($notification);
            $row->update(['email_sent' => true]);
        } catch (\Throwable $e) {
            Log::warning('Concern notification email failed', [
                'notification_id' => $row->id,
                'concern_id' => $row->concern_id,
                'recipient_id' => $recipient->id,
                'class' => get_class($e),
                'message' => $e->getMessage(),
            ]);
        }
    }
}
