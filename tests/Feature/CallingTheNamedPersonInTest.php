<?php

namespace Tests\Feature;

use App\Models\Concern;
use App\Models\ConcernSummons;
use App\Models\Notification;
use App\Models\User;
use App\Services\ConcernNotificationService;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Calling the person a concern names in to the office, and the letter.
 *
 * The step after TellingTheNamedPersonTest. They are told early that
 * something naming them is being looked into, without being told by whom;
 * this is what happens once the office is ready to deal with it -- a date,
 * a place, and a letter they can be handed.
 *
 * Everything here is one question: does the letter stay empty of the case?
 * The person reading it cannot open the concern, and a letter that explained
 * itself would walk straight around that wall. So the tests check what is
 * absent as carefully as what is present.
 */
class CallingTheNamedPersonInTest extends TestCase
{
    use RefreshDatabase;

    /** Appears in the description. If it reaches the letter, the wall leaks. */
    private const CANARY = 'KALABAW-CANARY';

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

    private function aConcernNaming(User $subject): Concern
    {
        $concern = Concern::create([
            'user_id' => $this->reporter()->id,
            'category' => 'Bullying',
            'subcategory' => 'Physical',
            'department' => 'College of Computer Studies',
            'description' => 'What happened, at length, including '.self::CANARY.'.',
            'urgency' => 'High',
            'status' => 'in_progress',
            'is_anonymous' => false,
            'assigned_to' => $this->handler()->id,
        ]);

        $concern->syncSubjects([$subject->id]);

        return $concern->fresh();
    }

    /** What the form posts, in the zone the staff member typed it in. */
    private function callIn(Concern $concern, User $person, array $overrides = []): array
    {
        return array_merge([
            'user_id' => $person->id,
            'meet_at' => now()->local()->addDays(3)->setTime(10, 0)->format('Y-m-d\TH:i'),
            'place' => 'the Guidance Office',
            'note' => 'Bring your student ID.',
        ], $overrides);
    }

    public function test_the_office_can_call_a_named_person_in(): void
    {
        $concern = $this->aConcernNaming($this->named());

        $this->actingAs($this->handler())
            ->post("/concerns/{$concern->id}/summon", $this->callIn($concern, $this->named()))
            ->assertRedirect();

        $summons = ConcernSummons::where('concern_id', $concern->id)->firstOrFail();

        $this->assertEquals($this->named()->id, $summons->user_id);
        $this->assertEquals($this->handler()->id, $summons->issued_by);
        $this->assertEquals('the Guidance Office', $summons->place);
    }

    /**
     * The time on the letter is the time that was typed.
     *
     * The box is a datetime-local: what arrives is a wall clock with no
     * zone on it, read off a screen in Manila, while every timestamp in
     * this database is UTC. Store it unconverted and the letter sends
     * somebody in at six in the evening for a ten o'clock meeting.
     */
    public function test_the_hour_on_the_letter_is_the_hour_that_was_typed(): void
    {
        $concern = $this->aConcernNaming($this->named());
        $typed = now()->local()->addDays(3)->setTime(10, 0);

        $this->actingAs($this->handler())->post(
            "/concerns/{$concern->id}/summon",
            $this->callIn($concern, $this->named(), ['meet_at' => $typed->format('Y-m-d\TH:i')])
        );

        $summons = ConcernSummons::where('concern_id', $concern->id)->firstOrFail();

        $this->assertEquals(
            $typed->format('Y-m-d H:i'),
            $summons->meet_at->local()->format('Y-m-d H:i'),
            'The letter must show the hour the staff member typed.'
        );

        // And it is UTC underneath, like everything else in this schema.
        $this->assertEquals(
            $typed->copy()->utc()->format('Y-m-d H:i'),
            $summons->meet_at->format('Y-m-d H:i'),
            'Stored in UTC, not as the local wall clock.'
        );
    }

    public function test_only_somebody_the_concern_names_can_be_called_in(): void
    {
        $concern = $this->aConcernNaming($this->named());
        $stranger = User::where('email', 'ccs.instructor@cspc.edu.ph')->firstOrFail();

        $this->actingAs($this->handler())
            ->post("/concerns/{$concern->id}/summon", $this->callIn($concern, $stranger));

        $this->assertDatabaseCount('concern_summons', 0);
        $this->assertSame(0, Notification::where('user_id', $stranger->id)->count());
    }

    public function test_a_date_that_has_passed_is_refused(): void
    {
        $concern = $this->aConcernNaming($this->named());

        $this->actingAs($this->handler())->post(
            "/concerns/{$concern->id}/summon",
            $this->callIn($concern, $this->named(), [
                'meet_at' => now()->local()->subDay()->format('Y-m-d\TH:i'),
            ])
        )->assertSessionHasErrors('meet_at');

        $this->assertDatabaseCount('concern_summons', 0);
    }

    public function test_a_place_is_required(): void
    {
        $concern = $this->aConcernNaming($this->named());

        $this->actingAs($this->handler())->post(
            "/concerns/{$concern->id}/summon",
            $this->callIn($concern, $this->named(), ['place' => ''])
        )->assertSessionHasErrors('place');

        $this->assertDatabaseCount('concern_summons', 0);
    }

    public function test_the_reporter_cannot_call_anybody_in(): void
    {
        $concern = $this->aConcernNaming($this->named());

        $this->actingAs($this->reporter())
            ->post("/concerns/{$concern->id}/summon", $this->callIn($concern, $this->named()))
            ->assertForbidden();

        $this->assertDatabaseCount('concern_summons', 0);
    }

    /**
     * The notice says when and where, and nothing about what.
     *
     * In-app only, for the same reason as every other notice to a named
     * person: an email saying a student is wanted at the Guidance Office
     * is readable on a lock screen by anybody standing nearby.
     */
    public function test_the_notice_carries_no_case_content(): void
    {
        $concern = $this->aConcernNaming($this->named());

        $this->actingAs($this->handler())
            ->post("/concerns/{$concern->id}/summon", $this->callIn($concern, $this->named()));

        $notice = Notification::where('user_id', $this->named()->id)
            ->where('type', ConcernNotificationService::TYPE_CALLED_IN)
            ->firstOrFail();

        $text = $notice->title.' '.$notice->message;

        foreach ([self::CANARY, $this->reporter()->name, $this->reporter()->email, 'Bullying', 'Physical'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase(
                $forbidden,
                $text,
                "The notice must not carry '{$forbidden}'."
            );
        }

        $this->assertStringContainsString('the Guidance Office', $text);
        $this->assertFalse((bool) $notice->email_sent, 'This notice is never emailed.');
    }

    public function test_the_letter_carries_no_case_content(): void
    {
        $concern = $this->aConcernNaming($this->named());

        $this->actingAs($this->handler())
            ->post("/concerns/{$concern->id}/summon", $this->callIn($concern, $this->named()));

        $summons = ConcernSummons::where('concern_id', $concern->id)->firstOrFail();

        $html = $this->actingAs($this->named())
            ->get("/concerns/{$concern->id}/summons/{$summons->id}/letter")
            ->assertOk()
            ->getContent();

        foreach ([self::CANARY, $this->reporter()->name, $this->reporter()->email, 'Bullying', 'Physical'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase(
                $forbidden,
                $html,
                "The letter must not carry '{$forbidden}'."
            );
        }

        // What it does carry: who it is for, when, where, and who signed it.
        $this->assertStringContainsString($this->named()->name, $html);
        $this->assertStringContainsString('the Guidance Office', $html);
        $this->assertStringContainsString($this->handler()->name, $html);
        $this->assertStringContainsString('Bring your student ID', $html);
    }

    /**
     * The letter is readable by the person it is for -- and they still
     * cannot open the concern behind it.
     */
    public function test_the_named_person_reads_their_letter_but_not_the_concern(): void
    {
        $concern = $this->aConcernNaming($this->named());

        $this->actingAs($this->handler())
            ->post("/concerns/{$concern->id}/summon", $this->callIn($concern, $this->named()));

        $summons = ConcernSummons::where('concern_id', $concern->id)->firstOrFail();

        $this->actingAs($this->named())
            ->get("/concerns/{$concern->id}/summons/{$summons->id}/letter")
            ->assertOk();

        $this->assertContains(
            $this->actingAs($this->named())->get("/concerns/{$concern->id}")->status(),
            [403, 404],
            'Being handed a letter must not open the case.'
        );
    }

    /**
     * Not the reporter's to read. It names the person their complaint is
     * about beside a date they are expected, which is not theirs to have.
     */
    public function test_the_reporter_cannot_read_the_letter(): void
    {
        $concern = $this->aConcernNaming($this->named());

        $this->actingAs($this->handler())
            ->post("/concerns/{$concern->id}/summon", $this->callIn($concern, $this->named()));

        $summons = ConcernSummons::where('concern_id', $concern->id)->firstOrFail();

        $this->actingAs($this->reporter())
            ->get("/concerns/{$concern->id}/summons/{$summons->id}/letter")
            ->assertForbidden();
    }

    public function test_somebody_with_no_part_in_the_case_cannot_read_the_letter(): void
    {
        $concern = $this->aConcernNaming($this->named());

        $this->actingAs($this->handler())
            ->post("/concerns/{$concern->id}/summon", $this->callIn($concern, $this->named()));

        $summons = ConcernSummons::where('concern_id', $concern->id)->firstOrFail();
        $stranger = User::where('email', 'student2@my.cspc.edu.ph')->first()
            ?? User::where('email', 'ccs.instructor@cspc.edu.ph')->firstOrFail();

        $this->actingAs($stranger)
            ->get("/concerns/{$concern->id}/summons/{$summons->id}/letter")
            ->assertForbidden();
    }

    /** A letter id from one concern cannot be read through another's URL. */
    public function test_a_letter_belongs_to_its_own_concern(): void
    {
        $concern = $this->aConcernNaming($this->named());
        $other = $this->aConcernNaming($this->named());

        $this->actingAs($this->handler())
            ->post("/concerns/{$concern->id}/summon", $this->callIn($concern, $this->named()));

        $summons = ConcernSummons::where('concern_id', $concern->id)->firstOrFail();

        $this->actingAs($this->handler())
            ->get("/concerns/{$other->id}/summons/{$summons->id}/letter")
            ->assertNotFound();
    }

    /**
     * Calling somebody in does not care what role they hold.
     *
     * The filing form offers staff only, so a new concern cannot name a
     * student -- but concerns filed while classmates were offered still
     * name one, and an office must be able to call those people in. The
     * subject is written straight onto the concern here for that reason:
     * it is the state on file, not a state the form can still produce.
     */
    public function test_anybody_named_can_be_called_in_whatever_their_role(): void
    {
        $classmate = User::where('email', 'student2@my.cspc.edu.ph')->first();

        if (! $classmate) {
            $this->markTestSkipped('No second student in the seed.');
        }

        $concern = $this->aConcernNaming($classmate);

        $this->actingAs($this->handler())
            ->post("/concerns/{$concern->id}/summon", $this->callIn($concern, $classmate));

        $this->assertDatabaseHas('concern_summons', [
            'concern_id' => $concern->id,
            'user_id' => $classmate->id,
        ]);
    }

    /**
     * The bell takes them to the letter, not to a 403.
     *
     * It is the one notice with somewhere better to go than the concern:
     * they cannot open the case, but the letter is theirs.
     */
    public function test_the_notification_opens_the_letter(): void
    {
        $concern = $this->aConcernNaming($this->named());

        $this->actingAs($this->handler())
            ->post("/concerns/{$concern->id}/summon", $this->callIn($concern, $this->named()));

        $summons = ConcernSummons::where('concern_id', $concern->id)->firstOrFail();
        $notice = Notification::where('user_id', $this->named()->id)
            ->where('type', ConcernNotificationService::TYPE_CALLED_IN)
            ->firstOrFail();

        $this->actingAs($this->named())
            ->post("/notifications/{$notice->id}/read")
            ->assertRedirect(route('concerns.summons.letter', [$concern->id, $summons->id]));
    }

    /**
     * A bell row whose notification is gone is not a dead end.
     *
     * The bell is the one part of the app people leave open. A tab from
     * this morning still lists rows the retention job has since purged
     * along with their concern, and clicking one gave a bare 404.
     */
    public function test_a_notification_that_no_longer_exists_does_not_404(): void
    {
        $concern = $this->aConcernNaming($this->named());

        $this->actingAs($this->handler())
            ->post("/concerns/{$concern->id}/summon", $this->callIn($concern, $this->named()));

        $notice = Notification::where('user_id', $this->named()->id)
            ->where('type', ConcernNotificationService::TYPE_CALLED_IN)
            ->firstOrFail();

        $id = $notice->id;
        $notice->delete();

        $this->actingAs($this->named())
            ->post("/notifications/{$id}/read")
            ->assertRedirect(route('concerns.index'));
    }

    /** Called in is written on the case, so the file shows it was done. */
    public function test_calling_somebody_in_is_written_on_the_case(): void
    {
        $concern = $this->aConcernNaming($this->named());

        $this->actingAs($this->handler())
            ->post("/concerns/{$concern->id}/summon", $this->callIn($concern, $this->named()));

        $this->assertDatabaseHas('audit_logs', [
            'concern_id' => $concern->id,
            'action' => 'person_called_in',
        ]);
    }

    /** The panel is on the case for the office, and lists what it issued. */
    public function test_the_panel_shows_on_the_concern(): void
    {
        $concern = $this->aConcernNaming($this->named());

        $this->actingAs($this->handler())
            ->get("/concerns/{$concern->id}")
            ->assertOk()
            ->assertSee('Call someone in');

        $this->actingAs($this->handler())
            ->post("/concerns/{$concern->id}/summon", $this->callIn($concern, $this->named()));

        $this->actingAs($this->handler())
            ->get("/concerns/{$concern->id}")
            ->assertOk()
            ->assertSee('Open the letter');
    }

    /** Nothing to call in on a concern that names nobody. */
    public function test_no_panel_when_the_concern_names_nobody(): void
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

        $this->actingAs($this->handler())
            ->get("/concerns/{$concern->id}")
            ->assertOk()
            ->assertDontSee('Call someone in');
    }
}
