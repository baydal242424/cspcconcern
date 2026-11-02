<?php

namespace Tests\Feature;

use App\Models\Concern;
use App\Models\Notification;
use App\Models\User;
use App\Services\ConcernNotificationService;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One panel, both sides of a concern.
 *
 * A handler could write to the student who filed a case, and the only
 * thing that could reach the person it named was a summons with a date
 * and a place. Most of what an office needs to say is smaller than that
 * -- send your written explanation, the meeting has moved -- and a formal
 * letter for each is why handlers reach for a personal phone instead,
 * which leaves no record on the case at all.
 *
 * The wall between the two sides does not move: the named person still
 * cannot open the concern, is still not told who raised it, and what
 * reaches them carries nothing read off the case.
 */
class WritingToEitherSideTest extends TestCase
{
    use RefreshDatabase;

    /** In the description. If it reaches the named person, the wall leaks. */
    private const CANARY = 'TALONG-CANARY';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, UserSeeder::class]);
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

    private function aConcern(): Concern
    {
        $concern = Concern::create([
            'user_id' => $this->reporter()->id,
            'category' => 'Bullying',
            'subcategory' => 'Verbal',
            'department' => 'College of Computer Studies',
            'description' => 'What happened, at length, including '.self::CANARY.'.',
            'urgency' => 'High',
            'status' => 'in_progress',
            'is_anonymous' => false,
            'assigned_to' => $this->handler()->id,
        ]);

        $concern->syncSubjects([$this->named()->id]);

        return $concern->fresh();
    }

    private function send(Concern $concern, array $payload)
    {
        return $this->actingAs($this->handler())
            ->post("/concerns/{$concern->id}/notify-reporter", $payload);
    }

    public function test_the_reporter_can_be_written_to(): void
    {
        $concern = $this->aConcern();

        $this->send($concern, [
            'to' => 'reporter',
            'kind' => 'documents',
            'message' => 'Please send the screenshots you mentioned, by Friday if you can.',
        ])->assertRedirect();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->reporter()->id,
            'concern_id' => $concern->id,
            'type' => ConcernNotificationService::TYPE_NOTICE_FROM_HANDLER,
        ]);
    }

    public function test_a_named_person_can_be_written_to(): void
    {
        $concern = $this->aConcern();

        $this->send($concern, [
            'to' => 'subject:'.$this->named()->id,
            'kind' => 'meeting',
            'message' => 'Please come to the Guidance Office on Thursday at 2 PM.',
        ])->assertRedirect();

        $notice = Notification::where('user_id', $this->named()->id)
            ->where('type', ConcernNotificationService::TYPE_NOTICE_TO_NAMED)
            ->firstOrFail();

        $this->assertStringContainsString('Thursday at 2 PM', $notice->message);
        $this->assertSame('You are asked to come in', $notice->title);
    }

    /**
     * The heading cannot say "your concern" to somebody who has none, and
     * must not say "the complaint about you" either.
     */
    public function test_the_heading_is_worded_for_a_named_person(): void
    {
        $concern = $this->aConcern();

        $this->send($concern, [
            'to' => 'subject:'.$this->named()->id,
            'kind' => 'documents',
            'message' => 'Please send your written explanation to this office.',
        ]);

        $notice = Notification::where('user_id', $this->named()->id)
            ->where('type', ConcernNotificationService::TYPE_NOTICE_TO_NAMED)
            ->firstOrFail();

        $this->assertStringNotContainsStringIgnoringCase('your concern', $notice->title);
        $this->assertStringNotContainsStringIgnoringCase('complaint', $notice->title);
    }

    /** Nothing read off the case travels with it, and it is never emailed. */
    public function test_the_named_person_is_told_nothing_about_the_case(): void
    {
        $concern = $this->aConcern();

        $this->send($concern, [
            'to' => 'subject:'.$this->named()->id,
            'kind' => 'meeting',
            'message' => 'Please come to the Guidance Office on Thursday at 2 PM.',
        ]);

        $notice = Notification::where('user_id', $this->named()->id)
            ->where('type', ConcernNotificationService::TYPE_NOTICE_TO_NAMED)
            ->firstOrFail();

        $text = $notice->title.' '.$notice->message;

        foreach ([self::CANARY, $this->reporter()->name, $this->reporter()->email, 'Bullying', 'Verbal'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $text);
        }

        // The sender is named: "report to the office" from nobody in
        // particular is not something anybody can act on.
        $this->assertStringContainsString($this->handler()->name, $notice->message);
        $this->assertFalse((bool) $notice->email_sent, 'Never emailed.');
    }

    public function test_neither_side_receives_the_others_message(): void
    {
        $concern = $this->aConcern();

        $this->send($concern, [
            'to' => 'reporter', 'kind' => 'documents',
            'message' => 'Please send the screenshots you mentioned this week.',
        ]);
        $this->send($concern, [
            'to' => 'subject:'.$this->named()->id, 'kind' => 'meeting',
            'message' => 'Please come to the Guidance Office on Thursday at 2 PM.',
        ]);

        $this->assertSame(0, Notification::where('user_id', $this->reporter()->id)
            ->where('message', 'like', '%Thursday at 2 PM%')->count());

        $this->assertSame(0, Notification::where('user_id', $this->named()->id)
            ->where('message', 'like', '%screenshots%')->count());
    }

    /** Checked against the record, not the form. */
    public function test_somebody_the_concern_does_not_name_cannot_be_written_to(): void
    {
        $concern = $this->aConcern();
        $stranger = User::where('email', 'ccs.instructor@cspc.edu.ph')->firstOrFail();

        $this->send($concern, [
            'to' => 'subject:'.$stranger->id,
            'kind' => 'update',
            'message' => 'This should never reach anybody at all, ever.',
        ]);

        $this->assertSame(0, Notification::where('user_id', $stranger->id)->count());
    }

    /** The field is optional, and its absence means the reporter. */
    public function test_a_post_with_no_recipient_still_reaches_the_reporter(): void
    {
        $concern = $this->aConcern();

        $this->send($concern, [
            'kind' => 'update',
            'message' => 'An old-style post with no recipient field at all.',
        ])->assertRedirect();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->reporter()->id,
            'type' => ConcernNotificationService::TYPE_NOTICE_FROM_HANDLER,
        ]);
    }

    /** Writing to them does not open the case to them. */
    public function test_writing_to_a_named_person_does_not_let_them_in(): void
    {
        $concern = $this->aConcern();

        $this->send($concern, [
            'to' => 'subject:'.$this->named()->id,
            'kind' => 'update',
            'message' => 'A short message that changes nothing about access.',
        ]);

        $this->assertContains(
            $this->actingAs($this->named())->get("/concerns/{$concern->id}")->status(),
            [403, 404]
        );
    }

    /** The timeline records it, under its own action. */
    public function test_writing_to_a_named_person_is_on_the_record(): void
    {
        $concern = $this->aConcern();

        $this->send($concern, [
            'to' => 'subject:'.$this->named()->id,
            'kind' => 'meeting',
            'message' => 'Please come to the Guidance Office on Thursday at 2 PM.',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'concern_id' => $concern->id,
            'action' => 'named_person_notified',
        ]);
    }

    /** The form offers the choice, and warns about the one that matters. */
    public function test_the_form_offers_both_and_carries_the_warning(): void
    {
        $concern = $this->aConcern();

        $this->actingAs($this->handler())->get("/concerns/{$concern->id}")
            ->assertOk()
            ->assertSee('Who is this for?')
            ->assertSee('The student who filed this')
            ->assertSee('subject:'.$this->named()->id, false)
            ->assertSee('no names, and nothing');
    }

    /** No choice to make when the concern names nobody. */
    public function test_no_recipient_picker_when_nobody_is_named(): void
    {
        $concern = Concern::create([
            'user_id' => $this->reporter()->id,
            'category' => 'Facilities',
            'subcategory' => 'Classrooms',
            'department' => 'College of Computer Studies',
            'description' => 'The aircon in room 204 has been broken for a fortnight.',
            'urgency' => 'Low',
            'status' => 'in_progress',
            'is_anonymous' => false,
            'assigned_to' => $this->handler()->id,
        ]);

        $this->actingAs($this->handler())->get("/concerns/{$concern->id}")
            ->assertOk()
            ->assertDontSee('Who is this for?');
    }
}
