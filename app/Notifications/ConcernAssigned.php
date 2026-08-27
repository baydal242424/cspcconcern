<?php

namespace App\Notifications;

use App\Models\Concern;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emails the STAFF MEMBER a concern has just been routed to, so a case does
 * not sit unseen until they happen to log in. Same content-free rule as
 * ConcernStatusUpdated: urgency is included because it is what tells the
 * handler how fast to respond, but the reporter, category and description
 * are not -- those stay behind the sign-in.
 */
class ConcernAssigned extends Notification
{
    use Queueable;

    public function __construct(
        public Concern $concern,
    ) {
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $urgency = $this->concern->urgency ?? 'untriaged';

        return (new MailMessage)
            ->subject("A concern has been assigned to you (#{$this->concern->id})")
            ->greeting("Hi {$notifiable->name},")
            ->line("Concern #{$this->concern->id} has been assigned to you. Urgency: {$urgency}.")
            ->action('Sign in to review it', route('concerns.show', $this->concern))
            ->line('Details are only available after you sign in.')
            ->salutation('— CSPC Report Concern');
    }
}
