<?php

namespace App\Services;

use App\Models\Concern;
use App\Models\ConcernNote;
use App\Models\Notification;
use App\Models\User;
use App\Notifications\ConcernAssigned;
use App\Notifications\ConcernNoticeFromHandler;
use App\Notifications\ConcernStatusUpdated;
use App\Notifications\NamedInAConcern;
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
    /** The notice sent to somebody a concern names. */
    public const TYPE_NAMED_IN_CONCERN = 'named_in_concern';

    /** A note written on the case, pointing at the note itself. */
    public const TYPE_NOTE_ADDED = 'note_added';

    /**
     * Tell the reporter the office has written on their case.
     *
     * Nothing told them before. A handler could write six notes in a
     * morning and the student would learn of it only by opening the page
     * and reading to the bottom -- which is to say, not at all.
     *
     * ONE notice per visit, not one per note. A handler saves review notes
     * and resolution notes together, so notifying per note would send two
     * for one piece of work, and a case worked through in an afternoon
     * would fill the bell with rows that all say the same thing.
     *
     * It carries the anchor of the first new note, so opening it lands on
     * the writing rather than at the top of a long case.
     *
     * In the app only. The student is already emailed when the status
     * changes, and an email for every note would turn a case being worked
     * properly into a reason to mute the sender.
     */
    public function notesAdded(Concern $concern, ConcernNote $firstNew): void
    {
        // Their own writing is not news to them. An admin or a counsellor
        // can be the reporter of a concern they then handle.
        if ($firstNew->user_id === $concern->user_id) {
            return;
        }

        Notification::create([
            'user_id' => $concern->user_id,
            'type' => self::TYPE_NOTE_ADDED,
            'concern_id' => $concern->id,
            'anchor' => 'note-'.$firstNew->id,
            'title' => 'New note on your concern',
            'message' => 'The office handling your concern has written on it.',
        ]);
    }

    /**
     * @param  int|null  $logId  the audit entry this change wrote, so the
     *                           notice can land on that line of the
     *                           timeline rather than at the top of a case
     *                           with eighteen of them.
     */
    public function statusChanged(Concern $concern, string $status, ?int $logId = null): void
    {
        $copy = self::STATUS_COPY[$status] ?? [
            'label' => ucfirst(str_replace('_', ' ', $status)),
            'line' => 'Your concern has been updated.',
        ];

        $row = Notification::create([
            'user_id' => $concern->user_id,
            'type' => 'status_update',
            'concern_id' => $concern->id,
            'anchor' => $logId ? 'log-'.$logId : null,
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
     * Tell the person a concern NAMES that it is being looked into.
     *
     * Natural justice: somebody should not learn months afterwards
     * that a complaint naming them was investigated. They are told
     * when it starts.
     *
     * What they are NOT told is who filed it. The subject of a concern
     * is walled out of the case entirely by the conflict-of-interest
     * rule in Concern::applyRoleVisibility(), and this notice must not
     * undo that in its text: no reporter, no description, no category,
     * no class. A category alone can be enough -- an academic concern
     * naming an instructor points at the one class they teach -- and a
     * section narrows it to forty people.
     *
     * Emailed as well as shown in the app. This was in-app only, on the
     * reasoning that a mailbox is outside everything the visibility rules
     * can enforce -- but it put the whole guarantee behind a habit: a
     * person who does not sign in was never told at all, which is the
     * same as not telling them. Being told is the point.
     *
     * The mail is written to survive being read by the wrong person: no
     * category, no description, no reporter, not even the concern number
     * (see App\Notifications\NamedInAConcern). It says a matter involving
     * them is being looked into and where to sign in. On a lock screen
     * that is a sentence; "a bullying report naming you" would be an
     * accusation.
     *
     * Sent once per person per concern: a case that goes in progress,
     * is referred, and comes back does not tell them twice. The caller
     * fires this on the move to "in progress" AND on the move to
     * "resolved", because nothing makes a handler pass through the first
     * on the way to the second -- and the once-per-person rule here is
     * what makes sending from both places safe.
     */
    public function investigationBegun(Concern $concern): void
    {
        foreach ($concern->subjects as $subject) {
            $already = Notification::where('user_id', $subject->id)
                ->where('concern_id', $concern->id)
                ->where('type', self::TYPE_NAMED_IN_CONCERN)
                ->exists();

            if ($already) {
                continue;
            }

            $row = Notification::create([
                'user_id' => $subject->id,
                'type' => self::TYPE_NAMED_IN_CONCERN,
                'concern_id' => $concern->id,
                // Worded for the moment it is actually sent: filing. It
                // said "has been taken up by the office handling it",
                // which was true while this waited for a handler to pick
                // the case up and became a small lie the moment it fired
                // on submission instead.
                'title' => 'A concern naming you has been filed',
                'message' => 'A concern that names you has been received by the office'
                    .' that handles it. You will be contacted if anything is needed from you.'
                    .' You cannot open the concern itself, and who raised it is not disclosed.',
            ]);

            // deliver() stamps email_sent and swallows a dead mail server,
            // so a working status change is never lost to one.
            $this->deliver($subject, new NamedInAConcern, $row);
        }
    }

    /** A handler asking the reporter for something, or telling them something. */
    public const TYPE_NOTICE_FROM_HANDLER = 'notice_from_handler';

    /** The reporter answering with the files they were asked for. */
    public const TYPE_DOCUMENTS_ADDED = 'documents_added';

    /**
     * Tell the REPORTER that the office handling their concern needs them.
     *
     * The in-app row carries the handler's words in full. That is safe in a
     * way the email is not: it is behind the sign-in, on the student's own
     * concern, and rule 1 is about mailboxes. Putting it here as well as on
     * the page means a student who is asked for a document on Friday reads
     * the request without first working out which of their concerns it is
     * about.
     *
     * It names the sender. A student is already told by name who holds
     * their case, and "come and see me" from nobody in particular is not
     * something anybody can act on.
     *
     * Anonymity is untouched in both directions: this is addressed to the
     * reporter's account, so a handler who does not know who filed the
     * concern still does not know after sending it.
     */
    public function noticeFromHandler(Concern $concern, string $kind, string $message, User $sender): Notification
    {
        $needsReply = $kind !== 'update';

        $row = Notification::create([
            'user_id' => $concern->user_id,
            'type' => self::TYPE_NOTICE_FROM_HANDLER,
            'concern_id' => $concern->id,
            'title' => Concern::noticeTitle($kind),
            'message' => $message."\n\n— ".$sender->name
                .($sender->role ? ', '.$sender->role->name : ''),
        ]);

        $this->deliver($concern->user, new ConcernNoticeFromHandler($concern, $needsReply), $row);

        return $row;
    }

    /**
     * Tell the HANDLER that the documents they asked for have arrived.
     *
     * Without this the request is a dead end from their side: they ask on
     * Tuesday and then have to keep reopening the case to find out whether
     * anything came. Addressed to whoever holds the concern now, which may
     * not be whoever asked -- the case can be referred in between, and the
     * files belong to the desk, not to the person who requested them.
     */
    public function documentsAdded(Concern $concern, int $count): void
    {
        if (! $concern->assigned_to) {
            return;
        }

        Notification::create([
            'user_id' => $concern->assigned_to,
            'type' => self::TYPE_DOCUMENTS_ADDED,
            'concern_id' => $concern->id,
            'title' => 'New documents on a concern you are handling',
            'message' => $count === 1
                ? "The reporter has added a file to concern #{$concern->id}."
                : "The reporter has added {$count} files to concern #{$concern->id}.",
            // In-app only. An email saying a student sent evidence, to an
            // address that may be read on a shared machine, says there is
            // an open case and who is handling it; the handler sees this
            // the moment they next open the app, which is where the files
            // are anyway.
            'email_sent' => false,
        ]);
    }

    /** A handler writing to somebody the concern names. */
    public const TYPE_NOTICE_TO_NAMED = 'notice_to_named';

    /**
     * Write to the person a concern NAMES, without calling them in.
     *
     * The gap this fills: the only thing that could reach a named person
     * was a summons with a date and a place. Most of what an office
     * actually needs to say is smaller than that -- send your written
     * explanation, bring your registration form, the meeting has moved to
     * Thursday -- and a formal letter for each of those is why handlers
     * end up using a personal phone instead, leaving no record.
     *
     * It names the sender, because "report to the office" from nobody in
     * particular is not something anybody can act on, and the sender is
     * staff, not the complainant.
     *
     * It does NOT name the complainant, and the caller's own words are
     * all it carries -- no category, no description, nothing read off the
     * concern. The handler can still write something careless, which is
     * why the form says so plainly above the box.
     *
     * In-app only, like every other notice to a named person: an email
     * telling somebody to report to the Guidance Office is readable on a
     * lock screen by anybody standing nearby.
     */
    public function noticeToNamedPerson(
        Concern $concern,
        User $person,
        string $kind,
        string $message,
        User $sender
    ): Notification {
        return Notification::create([
            'user_id' => $person->id,
            'type' => self::TYPE_NOTICE_TO_NAMED,
            'concern_id' => $concern->id,
            'title' => Concern::noticeTitleForNamed($kind),
            'message' => $message."\n\n— ".$sender->name
                .($sender->role ? ', '.$sender->role->name : ''),
            'email_sent' => false,
        ]);
    }

    /** The person a concern names, called in to the office. */
    public const TYPE_CALLED_IN = 'called_in';

    /**
     * Tell the person a concern names that they are expected at the office.
     *
     * The second half of what the panel asked for: they are told early
     * that something naming them is being looked into, and told again --
     * with a date and a place -- once the office is ready to deal with
     * it.
     *
     * When and where, and nothing about what. They still cannot open the
     * concern, and a notice that explained itself would route around
     * that in one line. Who complained is not in here either, for the
     * same reason it is in nothing else they can see.
     *
     * In-app only, like every other notice to a named person: an email
     * saying a student is wanted at the Guidance Office is readable on a
     * lock screen by anybody standing nearby.
     */
    public function calledIn(Concern $concern, User $person, \DateTimeInterface $meetAt, string $place, ?string $note = null): void
    {
        Notification::create([
            'user_id' => $person->id,
            'type' => self::TYPE_CALLED_IN,
            'concern_id' => $concern->id,
            'title' => 'You are asked to come to '.$place,
            // ->local(), like every other time a reader is shown: the row
            // is stored in UTC and a notice saying six in the evening for
            // a ten o'clock meeting is worse than no notice.
            'message' => 'Please come to '.$place.' on '
                .\Illuminate\Support\Carbon::parse($meetAt)->local()->format('l, j F Y \a\t g:i A').'.'
                .($note ? ' '.$note : '')
                .' A letter has been issued. This is about a matter being looked into;'
                .' it will be explained when you get there.',
            'email_sent' => false,
        ]);
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
