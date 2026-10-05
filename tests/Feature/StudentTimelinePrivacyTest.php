<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Concern;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the student reads in the Activity Timeline.
 *
 * The timeline was the audit log, shown to everyone who could see the concern.
 * One real case carried six hand-offs, each naming the person who received it:
 *
 *   Referred to Program Chair (Jonuel Rey Colle, College of Computer Studies)
 *   Investigation notes updated
 *   Referred to Dean (Ms. Rosel O. Onesa (OIC), College of Computer Studies)
 *   ...
 *
 * The reporter could follow their own report around the college, desk by desk,
 * and learn who had read it. They are owed the state of their case -- referred,
 * being worked on, resolved -- and the time it reached that state. Not the map.
 *
 * Staff keep the log in full: it is what makes the handling answerable.
 */
class StudentTimelinePrivacyTest extends TestCase
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
        $student = $this->u('student@my.cspc.edu.ph');

        $concern = Concern::create([
            'user_id' => $student->id,
            'category' => 'Academic',
            'department' => 'College of Computer Studies',
            'description' => 'Something happened in class.',
            'urgency' => 'Low',
            'status' => 'submitted',
            'is_anonymous' => false,
            'assigned_to' => $this->u('staff@cspc.edu.ph')->id,
        ]);

        // The two entries filing a concern writes, mirrored here so the
        // fixture starts where a real case does.
        foreach ([
            ['concern_submitted', 'Student submitted a new concern'],
            ['urgency_assigned', 'Urgency auto-assigned as Low'],
        ] as [$action, $description]) {
            AuditLog::create([
                'user_id' => $student->id,
                'concern_id' => $concern->id,
                'action' => $action,
                'description' => $description,
            ]);
        }

        return $concern->fresh();
    }

    /**
     * The page as the reporter sees it.
     *
     * The session is flushed first. A referral leaves "Referred successfully
     * to Dr. Maria Reyes" in the flash bag, and a test that signs in as the
     * student without clearing it reads the handler's own confirmation on the
     * student's page -- an artefact of sharing one session, not something a
     * real reporter would ever be served.
     */
    private function studentView(Concern $concern)
    {
        $this->flushSession();

        return $this->actingAs($this->u('student@my.cspc.edu.ph'))
            ->get("/concerns/{$concern->id}")->assertOk();
    }

    /** One hand-off, as whoever currently holds the case. */
    private function refer(Concern $concern, User $from, string $to): void
    {
        $this->actingAs($from)->patch("/concerns/{$concern->id}", [
            'investigation_notes' => 'Looked into this and spoke with the people involved.',
            'resolution_notes' => 'Recorded what is being done about it.',
            'status' => 'referred',
            'referred_to' => $to,
            'urgency' => 'Low',
        ])->assertRedirect();
    }

    /**
     * The student is told who holds their case, and not the route it
     * took to get there.
     */
    public function test_the_student_is_told_who_holds_it_but_not_the_route(): void
    {
        $concern = $this->aConcern();
        $staff = $this->u('staff@cspc.edu.ph');
        $counselor = $this->u('counselor@cspc.edu.ph');

        $this->refer($concern, $staff, 'Guidance Counselor');

        $resp = $this->studentView($concern);

        // Who has it now, by name. A student with a complaint needs somebody
        // to follow it up with; "an office of the college" named nobody,
        // which is the thing people complain about in the first place.
        $resp->assertSee('Being handled by');
        $resp->assertSee($counselor->name);

        // What they do not get is the ROUTE. The timeline says the case moved
        // on, not which desks it crossed to get here -- that is what would
        // let a reporter work out who has read it.
        $resp->assertSee('Referred to another office');
        $resp->assertDontSee('Referred to Guidance Counselor');

        // And the staff-only phrasing stays staff-only.
        $resp->assertDontSee('Assigned to');

        fwrite(STDERR, "  [timeline] the student is told who holds it, not the route it took\n");
    }

    /**
     * The list does not give it away either.
     *
     * Every referred row carried "→ Guidance Counselor" under its status
     * badge. A student reading their own list would have learned there what
     * the detail page stopped telling them.
     */
    public function test_the_list_does_not_name_the_office_holding_a_case(): void
    {
        $concern = $this->aConcern();
        $this->refer($concern, $this->u('staff@cspc.edu.ph'), 'Guidance Counselor');

        $this->flushSession();

        $this->actingAs($this->u('student@my.cspc.edu.ph'))->get('/concerns')->assertOk()
            ->assertSee('Referred')
            ->assertDontSee('Guidance Counselor');

        // Staff still see it: that is how they work the queue.
        $this->flushSession();

        $this->actingAs($this->u('counselor@cspc.edu.ph'))->get('/concerns')->assertOk()
            ->assertSee('Guidance Counselor');

        fwrite(STDERR, "  [timeline] the list does not name the office either\n");
    }

    /**
     * A run of hand-offs is one line.
     *
     * Being passed from the chair to the dean to a staff member to Guidance is
     * one thing happening to the student: their concern is somewhere else. The
     * entry keeps the time of the first hand-off -- the moment it left the desk
     * they were told about.
     */
    public function test_consecutive_hand_offs_collapse_into_one_entry(): void
    {
        $concern = $this->aConcern();

        $this->refer($concern, $this->u('staff@cspc.edu.ph'), 'Guidance Counselor');
        $concern->refresh();
        $firstReferralAt = $concern->auditLogs()->where('action', 'status_updated')->oldest('id')->first();

        $this->refer($concern, $concern->fresh()->assignedUser, 'Dean');
        $this->refer($concern, $concern->fresh()->assignedUser, 'Guidance Counselor');

        $html = $this->studentView($concern)->getContent();

        $this->assertSame(
            1,
            substr_count($html, 'Referred to another office'),
            'three hand-offs are one thing happening to the student'
        );

        // And it is the first one that is shown.
        $this->assertStringContainsString(
            $firstReferralAt->created_at->local()->format('M d, Y \a\t g:i A'),
            $html
        );

        fwrite(STDERR, "  [timeline] three hand-offs collapse to one entry\n");
    }

    /**
     * A real change ends the run, so the next hand-off is a new line.
     *
     * This is the half that keeps the timeline honest: collapsing must not
     * swallow a referral that happened after the case was actually picked up.
     */
    public function test_a_later_milestone_starts_the_run_again(): void
    {
        $concern = $this->aConcern();

        $this->refer($concern, $this->u('staff@cspc.edu.ph'), 'Guidance Counselor');

        // Picked up...
        $holder = $concern->fresh()->assignedUser;
        $this->actingAs($holder)->patch("/concerns/{$concern->id}", [
            'investigation_notes' => 'Looked into this and spoke with the people involved.',
            'resolution_notes' => 'Recorded what is being done about it.',
            'status' => 'in_progress',
            'urgency' => 'Low',
        ])->assertRedirect();

        // ...then referred onward again.
        $this->refer($concern, $concern->fresh()->assignedUser, 'Dean');

        $html = $this->studentView($concern)->getContent();

        $this->assertSame(
            2,
            substr_count($html, 'Referred to another office'),
            'a referral after real progress is a separate event'
        );

        fwrite(STDERR, "  [timeline] a milestone between hand-offs starts a new entry\n");
    }

    /** Triage and the handlers' own notes are not the student's business. */
    public function test_internal_bookkeeping_is_left_out(): void
    {
        $concern = $this->aConcern();
        $this->refer($concern, $this->u('staff@cspc.edu.ph'), 'Guidance Counselor');

        $resp = $this->studentView($concern);

        $resp->assertDontSee('Investigation notes updated');
        $resp->assertDontSee('Urgency auto-assigned');
        $resp->assertSee('Concern submitted');

        fwrite(STDERR, "  [timeline] triage and working notes are left out\n");
    }

    /**
     * Closing a report without acting on it is explained to the person who
     * filed it. The reason is the one staff text the student does see.
     */
    public function test_a_closure_reason_still_reaches_the_student(): void
    {
        $concern = $this->aConcern();

        $this->actingAs($this->u('staff@cspc.edu.ph'))->patch("/concerns/{$concern->id}", [
            'investigation_notes' => 'Looked into this and spoke with the people involved.',
            'resolution_notes' => 'Recorded what is being done about it.',
            'status' => 'closed_no_action',
            'closure_reason' => 'The class in question was rescheduled before this was filed.',
            'urgency' => 'Low',
        ])->assertRedirect();

        $this->studentView($concern)
            ->assertSee('The class in question was rescheduled before this was filed.');

        fwrite(STDERR, "  [timeline] a closure reason still reaches the student\n");
    }

    /** Staff lose nothing: the full audit trail is still theirs. */
    public function test_staff_still_see_every_hand_off_and_every_name(): void
    {
        $concern = $this->aConcern();
        $counselor = $this->u('counselor@cspc.edu.ph');

        $this->refer($concern, $this->u('staff@cspc.edu.ph'), 'Guidance Counselor');

        $resp = $this->actingAs($counselor)->get("/concerns/{$concern->id}")->assertOk();

        $resp->assertSee('Referred to Guidance Counselor');
        $resp->assertSee($counselor->name);
        $resp->assertSee('Investigation notes updated');

        fwrite(STDERR, "  [timeline] staff keep the full audit trail\n");
    }
}
