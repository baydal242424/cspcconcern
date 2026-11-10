<?php

namespace Tests\Feature;

use App\Models\Concern;
use App\Models\Notification;
use App\Models\User;
use App\Services\ConcernNotificationService;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The person a concern names is told it is being looked into.
 *
 * Natural justice: somebody should not learn months afterwards that a
 * complaint naming them was investigated. They are told when it starts.
 *
 * What they are not told is who raised it. The subject of a concern is
 * walled out of the case entirely by the conflict-of-interest rule, and this
 * notice must not undo that in its text -- no reporter, no description, no
 * category, no class. A category alone can be enough: an academic concern
 * naming an instructor points at the one class they teach.
 */
class TellingTheNamedPersonTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, UserSeeder::class]);
        Storage::fake('local');
    }

    private function reporter(): User
    {
        return User::where('email', 'student@my.cspc.edu.ph')->firstOrFail();
    }

    private function handler(): User
    {
        return User::where('email', 'counselor@cspc.edu.ph')->firstOrFail();
    }

    private function named(): User
    {
        return User::where('email', 'staff@cspc.edu.ph')->firstOrFail();
    }

    private function aConcernNaming(User $subject): Concern
    {
        $concern = Concern::create([
            'user_id' => $this->reporter()->id,
            'category' => 'Harassment',
            'department' => 'College of Computer Studies',
            'description' => 'What happened, described at length by the student.',
            'urgency' => 'High',
            'status' => 'submitted',
            'is_anonymous' => false,
            'assigned_to' => $this->handler()->id,
        ]);

        $concern->syncSubjects([$subject->id]);

        return $concern->fresh();
    }

    private function beginInvestigation(Concern $concern): void
    {
        $this->actingAs($this->handler())->patch("/concerns/{$concern->id}", [
            'investigation_notes' => 'Looked into this and spoke with the people involved.',
            'resolution_notes' => 'Recorded what is being done about it.',
            'status' => 'in_progress',
            'urgency' => 'High',
        ])->assertRedirect();
    }

    /**
     * They are emailed as well as shown a row in the bell.
     *
     * This was in-app only, which put the whole guarantee behind a habit:
     * somebody who does not sign in was never told at all, and not being
     * told is exactly what this exists to prevent.
     */
    public function test_the_named_person_is_emailed_as_well(): void
    {
        \Illuminate\Support\Facades\Notification::fake();

        $named = $this->named();
        $concern = $this->aConcernNaming($named);

        $this->beginInvestigation($concern);

        \Illuminate\Support\Facades\Notification::assertSentTo(
            $named,
            \App\Notifications\NamedInAConcern::class
        );

        $row = Notification::where('user_id', $named->id)
            ->where('type', ConcernNotificationService::TYPE_NAMED_IN_CONCERN)
            ->firstOrFail();

        $this->assertTrue((bool) $row->email_sent, 'the row should record that it went out');

        fwrite(STDERR, "  [named] they are emailed as well as told in the app\n");
    }

    /**
     * And the email gives away nothing -- not even the concern number.
     *
     * It is read on a lock screen by whoever is standing nearby. "A
     * bullying report naming you" is an accusation in those conditions,
     * and in a section of twenty it narrows the complainant too.
     */
    public function test_the_email_carries_nothing_about_the_case(): void
    {
        \Illuminate\Support\Facades\Notification::fake();

        $named = $this->named();
        $concern = $this->aConcernNaming($named);

        $this->beginInvestigation($concern);

        \Illuminate\Support\Facades\Notification::assertSentTo(
            $named,
            \App\Notifications\NamedInAConcern::class,
            function ($notification) use ($named, $concern) {
                $mail = $notification->toMail($named);
                $body = $mail->subject.' '.strip_tags((string) $mail->render());

                foreach ([
                    $this->reporter()->name,
                    $this->reporter()->email,
                    $concern->description,
                    $concern->category,
                    'complaint',
                    'accus',
                    '#'.$concern->id,
                ] as $forbidden) {
                    $this->assertStringNotContainsStringIgnoringCase(
                        $forbidden,
                        $body,
                        "The email must not carry '{$forbidden}'."
                    );
                }

                // And it must not send them at the concern, which is a 403.
                $this->assertStringNotContainsString('/concerns/'.$concern->id, $body);

                return true;
            }
        );

        fwrite(STDERR, "  [named] the email names nothing: not the case, not the number\n");
    }

    /**
     * Filing it is enough. Nobody has to touch the case first.
     *
     * This waited for a handler to pick the concern up, so a case sitting
     * at "submitted" for days told the person it named nothing at all --
     * and a report filed about somebody on Friday reached them on Monday
     * at the earliest, if anybody opened it. Being told is the point, and
     * a notice that depends on somebody else's diary is not dependable.
     *
     * What it costs is real and accepted: the notice goes out before any
     * member of staff has judged the report.
     */
    public function test_filing_it_tells_the_named_person_straight_away(): void
    {
        \Illuminate\Support\Facades\Notification::fake();

        $named = $this->named();

        $this->actingAs($this->reporter())->post('/concerns', [
            'category' => 'Bullying',
            'subcategory' => 'Verbal',
            'description' => 'A full account of what happened, written out at length.',
            'about_staff_id' => [$named->id],
        ])->assertRedirect();

        $concern = Concern::latest('id')->firstOrFail();

        $this->assertTrue(
            $concern->subjects->contains('id', $named->id),
            'the person should have been recorded as a subject'
        );

        $this->assertSame('submitted', $concern->status, 'nobody has picked it up yet');

        $notice = Notification::where('user_id', $named->id)
            ->where('concern_id', $concern->id)
            ->where('type', ConcernNotificationService::TYPE_NAMED_IN_CONCERN)
            ->first();

        $this->assertNotNull($notice, 'they should have been told as it was filed');
        $this->assertTrue((bool) $notice->email_sent, 'and emailed');

        \Illuminate\Support\Facades\Notification::assertSentTo(
            $named,
            \App\Notifications\NamedInAConcern::class
        );

        fwrite(STDERR, "  [named] filing alone tells them, and emails them\n");
    }

    /**
     * Told once, however many times it then moves.
     *
     * Filing tells them; the later move to in progress must not tell them
     * again, or a case that is worked on properly becomes the one that
     * pesters the person it named.
     */
    public function test_filing_then_working_on_it_tells_them_only_once(): void
    {
        $named = $this->named();

        $this->actingAs($this->reporter())->post('/concerns', [
            'category' => 'Bullying',
            'subcategory' => 'Verbal',
            'description' => 'A full account of what happened, written out at length.',
            'about_staff_id' => [$named->id],
        ])->assertRedirect();

        $concern = Concern::latest('id')->firstOrFail();
        $concern->update(['assigned_to' => $this->handler()->id]);

        $this->beginInvestigation($concern->fresh());

        $this->assertSame(
            1,
            Notification::where('user_id', $named->id)
                ->where('concern_id', $concern->id)
                ->where('type', ConcernNotificationService::TYPE_NAMED_IN_CONCERN)
                ->count(),
            'one notice, not two'
        );

        fwrite(STDERR, "  [named] filed then taken up: still one notice\n");
    }

    /**
     * Referring it counts as work starting.
     *
     * This used to fire on "in progress" and "resolved" only, so a case
     * that went submitted, referred, referred and only then resolved told
     * the person it named nothing until the very end -- the opposite of
     * telling them at the start. A referral is not idle triage either:
     * both note boxes are required to make one, so the referrer has
     * written down what they found before the case moves.
     */
    public function test_referring_it_tells_the_named_person(): void
    {
        $named = $this->named();
        $concern = $this->aConcernNaming($named);

        $this->assertSame(0, Notification::where('user_id', $named->id)->count());

        $this->actingAs($this->handler())->patch("/concerns/{$concern->id}", [
            'investigation_notes' => 'Looked at this; it belongs with another office.',
            'resolution_notes' => 'Sending it on.',
            'status' => 'referred',
            'referred_to' => 'Gender and Development',
            'urgency' => 'High',
        ])->assertRedirect();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $named->id,
            'concern_id' => $concern->id,
            'type' => ConcernNotificationService::TYPE_NAMED_IN_CONCERN,
        ]);

        fwrite(STDERR, "  [named] a referral tells them too\n");
    }

    /** They get a notice when work starts. */
    public function test_the_named_person_is_told_when_it_starts(): void
    {
        $named = $this->named();
        $concern = $this->aConcernNaming($named);

        $this->assertSame(0, Notification::where('user_id', $named->id)->count());

        $this->beginInvestigation($concern);

        $notice = Notification::where('user_id', $named->id)
            ->where('type', ConcernNotificationService::TYPE_NAMED_IN_CONCERN)
            ->first();

        $this->assertNotNull($notice, 'the person named should have been told');
        $this->assertStringContainsString('naming you', $notice->title);

        fwrite(STDERR, "  [named] the person named is told when it starts\n");
    }

    /**
     * Settled in one move, and they are still told.
     *
     * Nothing makes a handler pass through "in progress" on the way to
     * "resolved". The form offers resolving straight from submitted, and
     * resolving takes findings and proof -- so a case can be investigated
     * and closed out without the status ever showing it, and the notice
     * that fired only on "in progress" never fired at all.
     *
     * That is the worst version of what this exists to prevent: the person
     * was looked into, something was decided about them, and they were
     * never told it happened.
     */
    public function test_resolving_without_passing_through_in_progress_still_tells_them(): void
    {
        $named = $this->named();
        $concern = $this->aConcernNaming($named);

        $this->actingAs($this->handler())->patch("/concerns/{$concern->id}", [
            'investigation_notes' => 'Looked into this and spoke with the people involved.',
            'resolution_notes' => 'The matter was dealt with directly.',
            'status' => 'resolved',
            'urgency' => 'High',
            'resolution_attachments' => [UploadedFile::fake()->create('minutes.pdf', 30)],
        ])->assertRedirect();

        $this->assertSame('resolved', $concern->fresh()->status);

        $notice = Notification::where('user_id', $named->id)
            ->where('type', ConcernNotificationService::TYPE_NAMED_IN_CONCERN)
            ->first();

        $this->assertNotNull($notice,
            'a concern was investigated and settled without the person it named ever being told');

        fwrite(STDERR, "  [named] settled in one move, and they are still told\n");
    }

    /**
     * Closing without action adds nothing of its own.
     *
     * This used to be a real protection: a complaint reviewed and
     * dismissed never reached the person it named, because nothing was
     * sent until a handler took the case up. It no longer protects
     * anything for a report filed through the form, which tells them as
     * it is submitted -- before anybody can dismiss it. What is left is
     * narrower and still worth holding: closing a case is not itself an
     * occasion to tell somebody about it, so a concern that reached them
     * no other way is not announced by being thrown out.
     *
     * The concern here is written straight to the table rather than
     * filed, which is the only way to reach that state now.
     */
    public function test_closing_without_action_tells_them_nothing(): void
    {
        $named = $this->named();
        $concern = $this->aConcernNaming($named);

        $this->actingAs($this->handler())->patch("/concerns/{$concern->id}", [
            'investigation_notes' => 'Reviewed the report and found nothing to act on.',
            'resolution_notes' => 'No action taken.',
            'status' => 'closed_no_action',
            'closure_reason' => 'Reviewed in full and there is nothing here that needs acting on.',
            'urgency' => 'High',
        ])->assertRedirect();

        $this->assertSame('closed_no_action', $concern->fresh()->status);

        $this->assertSame(0, Notification::where('user_id', $named->id)
            ->where('type', ConcernNotificationService::TYPE_NAMED_IN_CONCERN)
            ->count(), 'a dismissed complaint was passed on to the person it named');

        fwrite(STDERR, "  [named] a dismissed complaint is not passed on to them\n");
    }

    /** And the notice gives nothing away. */
    public function test_the_notice_identifies_nobody(): void
    {
        $named = $this->named();
        $concern = $this->aConcernNaming($named);

        $this->beginInvestigation($concern);

        $notice = Notification::where('user_id', $named->id)
            ->where('type', ConcernNotificationService::TYPE_NAMED_IN_CONCERN)
            ->firstOrFail();

        $text = $notice->title.' '.$notice->message;

        foreach ([
            $this->reporter()->name,
            $this->reporter()->email,
            'Harassment',
            'College of Computer Studies',
            'What happened, described at length',
        ] as $secret) {
            $this->assertStringNotContainsString($secret, $text, 'the notice gave away: '.$secret);
        }

        fwrite(STDERR, "  [named] the notice names nobody and says nothing of the case\n");
    }

    /** They still cannot open the concern, and the bell does not try. */
    public function test_the_bell_does_not_send_them_to_a_concern_they_cannot_open(): void
    {
        $named = $this->named();
        $concern = $this->aConcernNaming($named);

        $this->beginInvestigation($concern);

        $notice = Notification::where('user_id', $named->id)
            ->where('type', ConcernNotificationService::TYPE_NAMED_IN_CONCERN)
            ->firstOrFail();

        $this->flushSession();

        // The conflict-of-interest rule stands.
        $this->assertFalse(
            Concern::visibleTo($named)->whereKey($concern->id)->exists(),
            'the person a concern is about must not be able to read it'
        );

        // So the notice sends them to their own list, not to a refusal.
        $this->actingAs($named)->post("/notifications/{$notice->id}/read")
            ->assertRedirect(route('concerns.index'));

        $this->actingAs($named)->get("/concerns/{$concern->id}")->assertForbidden();

        fwrite(STDERR, "  [named] the bell sends them to their list, not to a 403\n");
    }

    /** Only once, however many times the case moves. */
    public function test_they_are_not_told_twice(): void
    {
        $named = $this->named();
        $concern = $this->aConcernNaming($named);

        $this->beginInvestigation($concern);

        // Referred away and picked up again.
        $this->actingAs($this->handler())->patch("/concerns/{$concern->id}", [
            'investigation_notes' => 'Looked into this and spoke with the people involved.',
            'resolution_notes' => 'Recorded what is being done about it.',
            'status' => 'referred',
            'referred_to' => 'Dean',
            'urgency' => 'High',
        ])->assertRedirect();

        $holder = $concern->fresh()->assignedUser;

        $this->actingAs($holder)->patch("/concerns/{$concern->id}", [
            'investigation_notes' => 'Looked into this and spoke with the people involved.',
            'resolution_notes' => 'Recorded what is being done about it.',
            'status' => 'in_progress',
            'urgency' => 'High',
        ])->assertRedirect();

        $this->assertSame(
            1,
            Notification::where('user_id', $named->id)
                ->where('type', ConcernNotificationService::TYPE_NAMED_IN_CONCERN)
                ->count(),
            'one notice per person per concern'
        );

        fwrite(STDERR, "  [named] told once, however often the case moves\n");
    }

    /** A concern naming nobody tells nobody. */
    public function test_a_concern_naming_nobody_sends_nothing(): void
    {
        $concern = Concern::create([
            'user_id' => $this->reporter()->id,
            'category' => 'Facilities',
            'department' => 'College of Computer Studies',
            'description' => 'The aircon in Room 204 has not worked for two weeks.',
            'urgency' => 'Low',
            'status' => 'submitted',
            'is_anonymous' => false,
            'assigned_to' => $this->handler()->id,
        ]);

        $this->beginInvestigation($concern);

        $this->assertSame(
            0,
            Notification::where('type', ConcernNotificationService::TYPE_NAMED_IN_CONCERN)->count()
        );

        fwrite(STDERR, "  [named] a concern naming nobody sends nothing\n");
    }
}
