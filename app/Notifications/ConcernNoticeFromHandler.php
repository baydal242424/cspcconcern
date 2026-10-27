<?php

namespace App\Notifications;

use App\Models\Concern;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emails the REPORTER when the office handling their concern needs
 * something from them -- a document, or for them to come in.
 *
 * The handler's own words are NOT in here. They are the most specific thing
 * anybody writes about a case: "bring the medical certificate from the 3rd"
 * says what happened, roughly when, and that there was an injury. Rule 1 in
 * ConcernNotificationService keeps case content out of mailboxes, and this
 * is the notice that would break it hardest. The message itself waits
 * behind the sign-in; the mail exists to say that something is waiting.
 *
 * It does say that a REPLY is needed, because that is the difference
 * between a mail worth opening today and one worth opening on Friday, and
 * it discloses nothing: every concern has a reporter who can be asked for
 * something.
 */
class ConcernNoticeFromHandler extends Notification
{
    use Queueable;

    public function __construct(
        public Concern $concern,
        public bool $needsReply,
    ) {
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->needsReply
                ? "Action needed on your concern #{$this->concern->id}"
                : "A message about your concern #{$this->concern->id}")
            ->greeting("Hi {$notifiable->name},")
            ->line("The office handling your concern #{$this->concern->id} has sent you a message.");

        if ($this->needsReply) {
            $mail->line('They need something from you before they can take it further.');
        }

        return $mail
            ->action('Sign in to read it', ConcernAssigned::publicLink($this->concern))
            ->line('For your privacy, what they wrote is never included in this email.')
            ->salutation('— CSPC Report Concern');
    }
}
