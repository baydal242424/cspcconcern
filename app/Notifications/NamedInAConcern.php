<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emails the person a concern NAMES, once it is actually being looked into.
 *
 * Natural justice: somebody should not learn months afterwards that a
 * complaint naming them was investigated. They are told at the start, and
 * told by email as well as in the app, because a person who is not in the
 * habit of signing in would otherwise never find out at all -- which is
 * the same as not having been told.
 *
 * What this email withholds, and why each one matters:
 *
 *  - WHO RAISED IT. The whole guarantee. A reporter who can be worked out
 *    from a notice is a reporter who will not file the next one.
 *  - THE CATEGORY. "A bullying report naming you" is an accusation
 *    legible on a lock screen to whoever is standing nearby, and in a
 *    section of twenty it narrows the complainant as well.
 *  - THE DESCRIPTION. Never anywhere near a mailbox.
 *  - THE CONCERN NUMBER. The reporter's own emails carry it because they
 *    can open the case; this person cannot, so the number tells them
 *    nothing and gives anyone else reading over their shoulder a handle.
 *
 * What is left is the one thing they are owed: that a matter involving
 * them is being looked into, and where to go to read the rest.
 *
 * The link goes to the dashboard, NOT to the concern. They are walled out
 * of the case by the conflict-of-interest rule, so a link to it would
 * answer a reasonable click with a Forbidden page.
 */
class NamedInAConcern extends Notification
{
    use Queueable;

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            // Deliberately plain. Anything more specific is read on a lock
            // screen by people the notice is not for.
            ->subject('A notice is waiting for you')
            ->greeting("Hi {$notifiable->name},")
            ->line('A matter involving you has been raised with the office that handles it.')
            ->line('You are being told now, at the start, rather than after it is over.')
            ->action('Sign in to read the notice', $this->dashboard())
            ->line('Who raised it is not disclosed, and what it is about is never included in this email.')
            ->line('You will be contacted if anything is needed from you.')
            ->salutation('— CSPC Report Concern');
    }

    private function dashboard(): string
    {
        return rtrim((string) config('app.public_url'), '/').route('dashboard', [], false);
    }
}
