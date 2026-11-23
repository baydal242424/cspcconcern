<?php

namespace Tests\Feature;

use App\Models\Concern;
use App\Models\ConcernNote;
use App\Models\Notification;
use App\Models\User;
use App\Services\ConcernNotificationService;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A notice that says which, not just that.
 *
 * Writing on a case told the reporter nothing at all: a handler could add
 * six notes in a morning and the student would learn of it only by opening
 * the page and reading to the bottom, which is to say not at all.
 *
 * And a notice that did exist dropped them at the top of a case with
 * eighteen timeline entries and a dozen notes, leaving them to find what it
 * meant. On a case of any size that is the same as not saying which.
 */
class ANoticeLandsOnWhatItIsAboutTest extends TestCase
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

    private function aConcern(): Concern
    {
        return Concern::create([
            'user_id' => $this->u('student@my.cspc.edu.ph')->id,
            'category' => 'Personal',
            'department' => 'College of Computer Studies',
            'description' => 'Something happened that I would like looked into.',
            'urgency' => 'Low',
            'status' => 'in_progress',
            'is_anonymous' => false,
            'assigned_to' => $this->u('staff@cspc.edu.ph')->id,
        ]);
    }

    private function write(Concern $concern, string $review, string $resolution): void
    {
        $this->actingAs($this->u('staff@cspc.edu.ph'))
            ->patch("/concerns/{$concern->id}", [
                'investigation_notes' => $review,
                'resolution_notes' => $resolution,
                'status' => 'in_progress',
                'urgency' => 'Low',
            ])->assertRedirect();
    }

    /** Writing on a case tells the person who filed it. */
    public function test_the_reporter_is_told_when_the_office_writes(): void
    {
        $concern = $this->aConcern();

        $this->write($concern, 'I have spoken to the people involved.', 'Working out what to do.');

        $notice = Notification::where('user_id', $concern->user_id)
            ->where('type', ConcernNotificationService::TYPE_NOTE_ADDED)
            ->latest('id')->first();

        $this->assertNotNull($notice, 'the office writing on a case is news to the reporter');
        $this->assertSame('New note on your concern', $notice->title);

        fwrite(STDERR, "  [notice] the reporter is told when the office writes\n");
    }

    /**
     * One notice per visit, not one per note.
     *
     * Review and resolution notes are saved together, so notifying per
     * note would send two for a single piece of work.
     */
    public function test_one_notice_per_saving_not_one_per_note(): void
    {
        $concern = $this->aConcern();

        $this->write($concern, 'First look at it.', 'First plan.');

        $this->assertSame(1, Notification::where('user_id', $concern->user_id)
            ->where('type', ConcernNotificationService::TYPE_NOTE_ADDED)->count());

        // A second visit is a second notice -- that is a second piece of
        // work, and the point is to say something is happening.
        $this->write($concern, 'Second look, after speaking to them.', 'Revised plan.');

        $this->assertSame(2, Notification::where('user_id', $concern->user_id)
            ->where('type', ConcernNotificationService::TYPE_NOTE_ADDED)->count());

        fwrite(STDERR, "  [notice] one per saving, not one per note\n");
    }

    /** It points at the note, and following it lands there. */
    public function test_following_the_notice_lands_on_the_note(): void
    {
        $concern = $this->aConcern();

        $this->write($concern, 'The note this notice is about.', 'And the plan beside it.');

        $notice = Notification::where('user_id', $concern->user_id)
            ->where('type', ConcernNotificationService::TYPE_NOTE_ADDED)
            ->latest('id')->firstOrFail();

        $note = ConcernNote::where('concern_id', $concern->id)->oldest('id')->firstOrFail();

        $this->assertSame('note-'.$note->id, $notice->anchor);

        // Opening it goes to the concern WITH the fragment.
        $this->flushSession();

        $this->actingAs($this->u('student@my.cspc.edu.ph'))
            ->post("/notifications/{$notice->id}/read")
            ->assertRedirect(route('concerns.show', $concern->id).'#note-'.$note->id);

        // And the page has something at that address to land on.
        $this->flushSession();

        $this->actingAs($this->u('student@my.cspc.edu.ph'))
            ->get("/concerns/{$concern->id}")->assertOk()
            ->assertSee('id="note-'.$note->id.'"', false);

        fwrite(STDERR, "  [notice] following it lands on the note itself\n");
    }

    /**
     * A status change lands on that line of the timeline.
     *
     * "Your concern status has been updated to In progress" on a case
     * with eighteen entries left the reader to find which one.
     */
    public function test_a_status_notice_lands_on_its_timeline_entry(): void
    {
        $concern = $this->aConcern();

        $this->actingAs($this->u('staff@cspc.edu.ph'))
            ->patch("/concerns/{$concern->id}", [
                'investigation_notes' => 'Looked into this and spoke with the people involved.',
                'resolution_notes' => 'Recorded what is being done about it.',
                'status' => 'resolved',
                'urgency' => 'Low',
            ])->assertRedirect();

        $notice = Notification::where('user_id', $concern->user_id)
            ->where('type', 'status_update')
            ->latest('id')->firstOrFail();

        $this->assertNotNull($notice->anchor, 'a status notice knows which change it is about');
        $this->assertStringStartsWith('log-', $notice->anchor);

        // And the page has that line to land on.
        $this->flushSession();

        $this->actingAs($this->u('student@my.cspc.edu.ph'))
            ->get("/concerns/{$concern->id}")->assertOk()
            ->assertSee('id="'.$notice->anchor.'"', false);

        fwrite(STDERR, "  [notice] a status notice lands on its timeline entry\n");
    }

    /**
     * A message from the handler lands on the message.
     *
     * The bell and the page read different tables -- notifications and
     * audit entries -- so this one could name the message and still drop
     * the reader at the top of the case with it somewhere below.
     */
    public function test_a_message_notice_lands_on_the_message(): void
    {
        $concern = $this->aConcern();

        $this->actingAs($this->u('staff@cspc.edu.ph'))
            ->post("/concerns/{$concern->id}/notify-reporter", [
                'to' => 'reporter',
                'kind' => 'documents',
                'message' => 'Please send the receipt by Friday.',
            ])->assertRedirect();

        $notice = Notification::where('user_id', $concern->user_id)
            ->where('type', ConcernNotificationService::TYPE_NOTICE_FROM_HANDLER)
            ->latest('id')->firstOrFail();

        $this->assertNotNull($notice->anchor, 'a message notice knows which message it is');
        $this->assertStringStartsWith('notice-', $notice->anchor);

        $this->flushSession();

        $this->actingAs($this->u('student@my.cspc.edu.ph'))
            ->get("/concerns/{$concern->id}")->assertOk()
            ->assertSee('id="'.$notice->anchor.'"', false)
            ->assertSee('Please send the receipt by Friday.');

        fwrite(STDERR, "  [notice] a message notice lands on the message\n");
    }

    /**
     * The bell can fetch the batch after the one it opened with.
     *
     * It holds ten, because that query runs on every page load. The cost
     * was that a notice scrolled off the bottom and was gone -- a student
     * asked for a document on Monday had no way back to the request on
     * Friday.
     */
    public function test_the_bell_can_fetch_older_notices(): void
    {
        $student = $this->u('student@my.cspc.edu.ph');

        foreach (range(1, 25) as $i) {
            Notification::create([
                'user_id' => $student->id,
                'type' => 'status_update',
                'title' => "Notice number {$i}",
                'message' => "The {$i}th thing that happened.",
            ]);
        }

        // The panel opens with ten.
        $panel = $this->actingAs($student)->get('/concerns')->assertOk()->getContent();

        $this->assertSame(10, substr_count($panel, 'class="bell-row'));
        $this->assertStringContainsString('id="bell-more"', $panel);

        // And the next ten arrive as rows, with nothing around them --
        // no layout, no panel, nothing that would make the dropdown jump.
        $this->flushSession();

        $second = $this->actingAs($student)->get('/notifications?partial=1&page=2');

        $second->assertOk();
        $second->assertHeader('X-Has-More', '1');

        $this->assertSame(10, substr_count($second->getContent(), 'class="bell-row'));
        $this->assertStringNotContainsString('<html', $second->getContent());

        // The last batch says so, so the control can retire rather than
        // offering a press that returns nothing.
        $this->flushSession();

        $this->actingAs($student)->get('/notifications?partial=1&page=3')
            ->assertOk()
            ->assertHeader('X-Has-More', '0');

        fwrite(STDERR, "  [bell] older notices can be fetched a batch at a time\n");
    }

    /** And the full page is there for anybody without JavaScript. */
    public function test_the_whole_list_has_a_page_of_its_own(): void
    {
        $student = $this->u('student@my.cspc.edu.ph');

        Notification::create([
            'user_id' => $student->id,
            'type' => 'status_update',
            'title' => 'Something happened',
            'message' => 'And here is what it was.',
        ]);

        $this->actingAs($student)->get('/notifications')->assertOk()
            ->assertSee('Something happened')
            // The project carries no Tailwind, and links() with no
            // argument gives Laravel's Tailwind pager -- whose chevrons
            // have no size rule and rendered the height of the screen.
            ->assertDontSee('h-5 w-5', false);

        fwrite(STDERR, "  [bell] the whole list has a page of its own\n");
    }

    /** A notice about the case as a whole still goes to the top. */
    public function test_a_notice_with_nothing_finer_to_point_at_is_unchanged(): void
    {
        $concern = $this->aConcern();

        $notice = Notification::create([
            'user_id' => $concern->user_id,
            'type' => 'status_update',
            'concern_id' => $concern->id,
            'title' => 'Concern Status Updated',
            'message' => 'Your concern status has been updated to In progress',
        ]);

        $this->assertNull($notice->anchor);

        $this->actingAs($this->u('student@my.cspc.edu.ph'))
            ->post("/notifications/{$notice->id}/read")
            ->assertRedirect(route('concerns.show', $concern->id));

        fwrite(STDERR, "  [notice] a whole-case notice is unchanged\n");
    }

    /**
     * The handler writing is not told about their own writing.
     *
     * An admin or a counsellor can be the reporter of a concern they then
     * handle, and a notice telling them what they just typed is noise.
     */
    public function test_nobody_is_told_about_their_own_writing(): void
    {
        $staff = $this->u('staff@cspc.edu.ph');

        $own = Concern::create([
            'user_id' => $staff->id,
            'category' => 'Personal',
            'department' => 'College of Computer Studies',
            'description' => 'A concern I filed and am also handling.',
            'urgency' => 'Low',
            'status' => 'in_progress',
            'is_anonymous' => false,
            'assigned_to' => $staff->id,
        ]);

        // The service is called directly rather than through a PATCH.
        // Filing a concern and then handling it is refused elsewhere for
        // its own reasons, so going through the form would test that rule
        // instead of this one -- and this rule still has to hold, because
        // the two roles can meet by other routes.
        $note = $own->addNote(ConcernNote::KIND_REVIEW, 'My own note.', $staff->id);

        app(ConcernNotificationService::class)->notesAdded($own, $note);

        $this->assertSame(0, Notification::where('user_id', $staff->id)
            ->where('type', ConcernNotificationService::TYPE_NOTE_ADDED)->count());

        fwrite(STDERR, "  [notice] nobody is told about their own writing\n");
    }
}
