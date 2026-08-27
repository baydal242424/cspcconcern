<?php

namespace App\Notifications;

use App\Models\Concern;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emails the REPORTER at their CSPC address when staff move their concern to
 * a new status. Built by ConcernNotificationService, which supplies the
 * status label and the plain-English explanation of what it means.
 *
 * Deliberately content-free: the subject and body never name the category,
 * never quote the description, and never say who is handling it. Once a
 * message is in a mailbox it is outside everything Concern::scopeVisibleTo()
 * can protect -- it can be forwarded, left open on a shared machine, or
 * previewed on a lock screen. A subject line like "Update on your bullying
 * report" would disclose the sensitive fact to anyone glancing at the phone.
 * So the mail carries only a case number, a status, and a link; the detail
 * stays behind the sign-in, which is what policy.blade.php promises.
 */
class ConcernStatusUpdated extends Notification
{
    use Queueable;

    public function __construct(
        public Concern $concern,
        public string $statusLabel,
        public string $explanation,
    ) {
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Update on your concern #{$this->concern->id}: {$this->statusLabel}")
            ->greeting("Hi {$notifiable->name},")
            ->line("Your concern #{$this->concern->id} is now: {$this->statusLabel}.")
            ->line($this->explanation)
            ->action('Sign in to view it', route('concerns.show', $this->concern))
            ->line('For your privacy, the details of your concern are never included in this email. Sign in to read them.')
            ->salutation('— CSPC Report Concern');
    }
}
