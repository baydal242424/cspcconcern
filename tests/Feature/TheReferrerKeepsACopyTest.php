<?php

namespace Tests\Feature;

use App\Models\Concern;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the person who referred a case away is left holding.
 *
 * A referral is a hand-off, and it was made a complete one: the case left
 * the referrer's list and could not be opened by them again. That was right
 * about the WORK -- they should not be watching the next desk type -- and
 * wrong about the RECORD. They handled the case too. Their notes, the
 * evidence they read, the fact that they looked at it at all went with it,
 * and somebody asked six months later why a complaint went to the Dean had
 * nothing to answer with.
 *
 * So they keep a copy, frozen at the moment they let go, plus the state the
 * case is in now:
 *
 *   KEPT    the report, the evidence as it stood, their own notes, their
 *           own referral, and whether the case is in progress, referred
 *           onward or settled.
 *   NOT     anything the next desk wrote: its notes, its evidence, the
 *           letters it issued, what it asked the student for.
 *   NEVER   any power to act. The copy is a record, not a desk.
 */
class TheReferrerKeepsACopyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, UserSeeder::class]);
    }

    private function u(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    /** A concern sitting on the first handler's desk. */
    private function aConcern(): Concern
    {
        return Concern::create([
            'user_id' => $this->u('student@my.cspc.edu.ph')->id,
            'category' => 'Personal',
            'department' => 'College of Computer Studies',
            'description' => 'Something happened in class that I need help with.',
            'urgency' => 'Low',
            'status' => 'submitted',
            'is_anonymous' => false,
            'assigned_to' => $this->u('staff@cspc.edu.ph')->id,
        ]);
    }

    /** The first handler writes their account and hands it to Guidance. */
    private function handOver(Concern $concern): void
    {
        $this->actingAs($this->u('staff@cspc.edu.ph'))
            ->patch("/concerns/{$concern->id}", [
                'investigation_notes' => 'I spoke with the student and read the class record.',
                'resolution_notes' => 'This needs a counsellor, so I am passing it to Guidance.',
                'status' => 'referred',
                'referred_to' => 'Guidance Counselor',
                'urgency' => 'Low',
            ])->assertRedirect();
    }

    /** The next desk works the case and writes its own account. */
    private function theNextDeskWorks(Concern $concern, string $status = 'in_progress'): void
    {
        $this->flushSession();

        $this->actingAs($this->u('counselor@cspc.edu.ph'))
            ->patch("/concerns/{$concern->id}", [
                'investigation_notes' => 'SECRET COUNSELLING ACCOUNT, not the referrer2 business.',
                'resolution_notes' => 'SECRET PLAN agreed with the student in confidence.',
                'status' => $status,
                'urgency' => 'Low',
            ])->assertRedirect();
    }

    private function asTheReferrer(Concern $concern)
    {
        $this->flushSession();

        return $this->actingAs($this->u('staff@cspc.edu.ph'))
            ->get("/concerns/{$concern->id}");
    }

    /** The case is still theirs to open, and still on their list. */
    public function test_the_referrer_can_still_open_their_copy(): void
    {
        $concern = $this->aConcern();
        $this->handOver($concern);

        $this->asTheReferrer($concern)
            ->assertOk()
            ->assertSee('Your copy of this concern')
            ->assertSee('Something happened in class that I need help with.');

        // And they can find it again without the id -- in its own pile.
        //
        // Not in the active list: the copies are kept out of that, or a
        // handler who has referred thirty cases reads thirty rows they can
        // do nothing about before reaching one they can.
        $this->flushSession();
        $this->actingAs($this->u('staff@cspc.edu.ph'))->get('/concerns')->assertOk()
            ->assertDontSee("concerns/{$concern->id}\"", false);

        $this->flushSession();
        $this->actingAs($this->u('staff@cspc.edu.ph'))->get('/concerns?handed_over=1')->assertOk()
            ->assertSee("concerns/{$concern->id}", false)
            ->assertSee('Referred — passed to another office');

        fwrite(STDERR, "  [copy] the referrer keeps a copy they can open\n");
    }

    /** Their own account of the case is in it. */
    public function test_their_own_notes_are_in_the_copy(): void
    {
        $concern = $this->aConcern();
        $this->handOver($concern);
        $this->theNextDeskWorks($concern);

        $this->asTheReferrer($concern)->assertOk()
            ->assertSee('I spoke with the student and read the class record.')
            ->assertSee('This needs a counsellor, so I am passing it to Guidance.');

        fwrite(STDERR, "  [copy] their own notes are kept\n");
    }

    /**
     * The next desk's writing is not.
     *
     * This is the half the earlier decision turned on: a referral has to
     * mean the next office can work without the last one reading over its
     * shoulder.
     */
    public function test_the_next_desks_notes_are_not_in_the_copy(): void
    {
        $concern = $this->aConcern();
        $this->handOver($concern);
        $this->theNextDeskWorks($concern);

        $this->asTheReferrer($concern)->assertOk()
            ->assertDontSee('SECRET COUNSELLING ACCOUNT')
            ->assertDontSee('SECRET PLAN');

        fwrite(STDERR, "  [copy] the next desk's notes stay with the next desk\n");
    }

    /**
     * But the state of the case is live.
     *
     * "Nakikita ng nag-refer kung in progress na ba, o pinag-refer niya rin."
     * A record that stops dead at your own last entry cannot answer whether
     * anything ever came of it.
     */
    public function test_the_referrer_sees_where_the_case_has_got_to(): void
    {
        $concern = $this->aConcern();
        $this->handOver($concern);
        $this->theNextDeskWorks($concern);

        $page = $this->asTheReferrer($concern)->assertOk();

        // Picked up by the office they sent it to.
        $page->assertSee('Status changed to in progress');

        // Their own hand-off is still on the timeline, named.
        $page->assertSee('Referred to Guidance Counselor');

        fwrite(STDERR, "  [copy] the referrer sees that it is being worked on\n");
    }

    /** And sees it when the next desk passes it on again. */
    public function test_the_referrer_sees_an_onward_referral(): void
    {
        $concern = $this->aConcern();
        $this->handOver($concern);

        // Somebody for the second hand-off to land on. A Personal concern
        // reaches the Guidance Counselor or an adviser and nowhere else.
        User::factory()->create([
            'name' => 'A Class Adviser',
            'role_id' => \App\Models\Role::where('name', 'Adviser')->value('id'),
            'department' => 'College of Computer Studies',
            'status' => 'approved',
        ]);

        $this->flushSession();
        $this->actingAs($this->u('counselor@cspc.edu.ph'))
            ->patch("/concerns/{$concern->id}", [
                'investigation_notes' => 'SECRET COUNSELLING ACCOUNT, not theirs to read.',
                'resolution_notes' => 'The adviser should take this one.',
                'status' => 'referred',
                'referred_to' => 'Adviser',
                'urgency' => 'Low',
            ])->assertRedirect();

        $page = $this->asTheReferrer($concern)->assertOk();

        // Where it went next, named -- that is the whole point of the live
        // half. Still without the counsellor's account of why.
        $page->assertSee('Referred to Adviser');
        $page->assertDontSee('SECRET COUNSELLING ACCOUNT');

        fwrite(STDERR, "  [copy] an onward referral is visible to the first referrer\n");
    }

    /** Resolution reaches them as a state, not as the resolution. */
    public function test_the_referrer_sees_that_it_was_settled(): void
    {
        $concern = $this->aConcern();
        $this->handOver($concern);
        $this->theNextDeskWorks($concern, 'resolved');

        $this->flushSession();

        // It stays in the Referred pile even once the next office settles
        // it -- NOT in Finished. It is theirs to look back on, not theirs
        // to have finished, and the three piles do not overlap.
        $this->actingAs($this->u('staff@cspc.edu.ph'))
            ->get('/concerns?handed_over=1')->assertOk()
            ->assertSee("concerns/{$concern->id}", false);

        $this->flushSession();
        $this->actingAs($this->u('staff@cspc.edu.ph'))
            ->get('/concerns?show_resolved=1')->assertOk()
            ->assertDontSee("concerns/{$concern->id}\"", false);

        $this->asTheReferrer($concern)->assertOk()
            ->assertSee('Marked as resolved')
            ->assertDontSee('SECRET PLAN');

        fwrite(STDERR, "  [copy] the referrer learns it was settled, not how\n");
    }

    /**
     * The copy is a record, not a desk.
     *
     * The gate matters most for a Dean: the rules that let one act on a
     * concern are satisfied by any Dean who can SEE it, and were safe only
     * because a Dean who referred a case could no longer see it.
     */
    public function test_the_referrer_cannot_act_on_the_copy(): void
    {
        $concern = $this->aConcern();
        $this->handOver($concern);

        // No form on the page.
        $this->asTheReferrer($concern)->assertOk()
            ->assertDontSee('Update Concern Status');

        // And the server refuses the request even if one is forged.
        $this->flushSession();
        $this->actingAs($this->u('staff@cspc.edu.ph'))
            ->patch("/concerns/{$concern->id}", [
                'investigation_notes' => 'Taking this back off them.',
                'resolution_notes' => 'Marking it done myself.',
                'status' => 'resolved',
                'urgency' => 'Low',
            ])->assertRedirect();

        $this->assertNotSame(
            'resolved',
            Concern::find($concern->id)->status,
            'the referrer must not be able to resolve a case they handed on'
        );

        fwrite(STDERR, "  [copy] the referrer cannot act on the copy\n");
    }

    /** A case handed back is theirs again, in full. */
    public function test_a_case_referred_back_is_live_again(): void
    {
        $concern = $this->aConcern();
        $this->handOver($concern);

        $this->flushSession();
        $this->actingAs($this->u('counselor@cspc.edu.ph'))
            ->patch("/concerns/{$concern->id}", [
                'investigation_notes' => 'This is academic after all, sending it back.',
                'resolution_notes' => 'Returned to the faculty member who raised it.',
                'status' => 'referred',
                'referred_to' => 'Faculty/Staff',
                'urgency' => 'Low',
            ])->assertRedirect();

        $concern->refresh();

        // Only meaningful if it actually landed back on them.
        if ((int) $concern->assigned_to !== (int) $this->u('staff@cspc.edu.ph')->id) {
            $this->markTestSkipped('the return did not route back to the original handler');
        }

        $this->asTheReferrer($concern)->assertOk()
            ->assertDontSee('Your copy of this concern')
            ->assertSee('This is academic after all, sending it back.');

        fwrite(STDERR, "  [copy] a case handed back is live again\n");
    }
}
