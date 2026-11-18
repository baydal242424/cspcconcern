<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\User; use App\Models\Concern; use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Database\Seeders\RoleSeeder; use Database\Seeders\UserSeeder;

class InvolvementHistoryTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void { parent::setUp(); $this->seed([RoleSeeder::class, UserSeeder::class]); }
    private function u($e){ return User::where('email',$e)->firstOrFail(); }

    /**
     * Referring a case away hands over the DESK. The record stays.
     *
     * This assertion has now been written three ways, and the third is the
     * one that holds both halves apart.
     *
     * It first said the referrer went on reading the case for good. That
     * made a referral a copy rather than a hand-off: a report that passed
     * three desks was afterwards readable by all three, each watching the
     * office that actually took it.
     *
     * So it was cut to nothing -- referred away, gone. That went too far in
     * the other direction. The person who referred it handled it too, and
     * losing the case took their own account of it with them: a student who
     * comes back to the adviser they first told, or a panel asking why a
     * complaint went to the Dean, met somebody with nothing to show.
     *
     * The line sits between the work and the record. They keep the record
     * -- their own notes, the evidence as it stood, and the state the case
     * has reached since -- and they lose every power to act on it.
     */
    public function test_staff_keeps_a_copy_but_loses_the_desk(): void
    {
        $staff=$this->u('staff@cspc.edu.ph'); $counselor=$this->u('counselor@cspc.edu.ph');
        $c=Concern::create(['user_id'=>$this->u('student@my.cspc.edu.ph')->id,'category'=>'Personal',
          'department'=>'College of Computer Studies','description'=>'x','urgency'=>'Low',
          'status'=>'submitted','is_anonymous'=>false,'assigned_to'=>$staff->id]);

        // staff refers to counselor (this writes an audit log for staff, and transfers ownership)
        $this->actingAs($staff)->patch("/concerns/{$c->id}",[
            'investigation_notes' => 'Looked into this and spoke with the people involved.',
            'resolution_notes' => 'Recorded what is being done about it.','status'=>'referred','referred_to'=>'Guidance Counselor','urgency'=>'Low']);
        $c->refresh();

        // The desk moved.
        $this->assertSame($counselor->id, $c->assigned_to, 'the case is on the counsellor\'s desk now');

        // The record stayed.
        $staffSees = Concern::whereKey($c->id)->visibleTo($staff)->exists();
        fwrite(STDERR,"\n  [history] staff keeps a copy of what they referred: ".($staffSees?'YES (good)':'NO (BAD)')."\n");
        $this->assertTrue($staffSees, 'The referrer keeps their copy of a case they handled');

        // It opens, and says what it is.
        $this->actingAs($staff)->get("/concerns/{$c->id}")
            ->assertOk()
            ->assertSee('Your copy of this concern')
            ->assertDontSee('Update Concern Status');

        // And it is a record, not a desk: they cannot resolve it.
        $this->actingAs($staff)->patch("/concerns/{$c->id}",[
            'investigation_notes' => 'Taking this back off them.',
            'resolution_notes' => 'Marking it done myself.','status'=>'resolved','urgency'=>'Low']);

        $this->assertNotSame('resolved', $c->fresh()->status,
            'a case handed on cannot be resolved by the person who handed it on');
    }

    /**
     * A successful referral must not land on a 403.
     *
     * update() sent everybody to the concern page afterwards. Once a
     * referral became a real hand-off, that page was shut to the person
     * who had just referred it -- so the reward for referring correctly
     * was a Forbidden screen with no sign the referral had worked.
     *
     * The fix at the time was to send them to the list instead. Now that
     * the referrer keeps a readable copy, the concern page opens again and
     * is the better place to land: it shows the hand-off that just
     * happened. What is under test is unchanged -- wherever they are sent,
     * it must be somewhere they can actually go.
     */
    public function test_referring_lands_on_a_page_that_opens(): void
    {
        $staff=$this->u('staff@cspc.edu.ph');
        $c=Concern::create(['user_id'=>$this->u('student@my.cspc.edu.ph')->id,'category'=>'Personal',
          'department'=>'College of Computer Studies','description'=>'x','urgency'=>'Low',
          'status'=>'submitted','is_anonymous'=>false,'assigned_to'=>$staff->id]);

        $this->actingAs($staff)->patch("/concerns/{$c->id}",[
            'investigation_notes' => 'Looked into this and spoke with the people involved.',
            'resolution_notes' => 'Recorded what is being done about it.',
            'status'=>'referred','referred_to'=>'Guidance Counselor','urgency'=>'Low'])
            ->assertRedirect(route('concerns.show', $c->id));

        // And the page they land on actually opens, with the confirmation.
        $this->actingAs($staff)->get(route('concerns.show', $c->id))
            ->assertOk()
            ->assertSee('Referred successfully', false);
    }

    /** An update that keeps the case still lands on the case. */
    public function test_an_ordinary_update_still_lands_on_the_concern(): void
    {
        $staff=$this->u('staff@cspc.edu.ph');
        $c=Concern::create(['user_id'=>$this->u('student@my.cspc.edu.ph')->id,'category'=>'Personal',
          'department'=>'College of Computer Studies','description'=>'x','urgency'=>'Low',
          'status'=>'submitted','is_anonymous'=>false,'assigned_to'=>$staff->id]);

        $this->actingAs($staff)->patch("/concerns/{$c->id}",[
            'investigation_notes' => 'Looked into this and spoke with the people involved.',
            'resolution_notes' => 'Recorded what is being done about it.',
            'status'=>'in_progress','urgency'=>'Low'])
            ->assertRedirect(route('concerns.show', $c->id));
    }

    /** Whoever holds it now reads it, and what the previous desk wrote. */
    public function test_the_desk_it_went_to_sees_it_and_the_earlier_notes(): void
    {
        $staff=$this->u('staff@cspc.edu.ph'); $counselor=$this->u('counselor@cspc.edu.ph');
        $c=Concern::create(['user_id'=>$this->u('student@my.cspc.edu.ph')->id,'category'=>'Personal',
          'department'=>'College of Computer Studies','description'=>'x','urgency'=>'Low',
          'status'=>'submitted','is_anonymous'=>false,'assigned_to'=>$staff->id]);

        $this->actingAs($staff)->patch("/concerns/{$c->id}",[
            'investigation_notes' => 'Spoke to the student on Tuesday.',
            'resolution_notes' => 'Passing this to Guidance.','status'=>'referred','referred_to'=>'Guidance Counselor','urgency'=>'Low']);

        $this->actingAs($counselor)->get("/concerns/{$c->id}")
            ->assertOk()
            ->assertSee('Spoke to the student on Tuesday.');
    }

    /**
     * Referred back, it is a desk again and not just a copy.
     *
     * Visibility no longer tells these two apart -- the referrer can see it
     * either way -- so what is tested is the thing that actually changes:
     * whether they may act on it.
     */
    public function test_a_concern_referred_back_becomes_theirs_again(): void
    {
        $staff=$this->u('staff@cspc.edu.ph'); $counselor=$this->u('counselor@cspc.edu.ph');
        $c=Concern::create(['user_id'=>$this->u('student@my.cspc.edu.ph')->id,'category'=>'Personal',
          'department'=>'College of Computer Studies','description'=>'x','urgency'=>'Low',
          'status'=>'submitted','is_anonymous'=>false,'assigned_to'=>$staff->id]);

        $this->actingAs($staff)->patch("/concerns/{$c->id}",[
            'investigation_notes' => 'Looked into this and spoke with the people involved.',
            'resolution_notes' => 'Recorded what is being done about it.','status'=>'referred','referred_to'=>'Guidance Counselor','urgency'=>'Low']);

        // Readable, but handed on: a copy.
        $this->assertTrue(Concern::whereKey($c->id)->visibleTo($staff)->exists());
        $this->assertNotNull($c->fresh()->handoverLogFor($staff), 'handed on, so a copy');

        // The counsellor hands it back.
        $c->refresh()->update(['assigned_to'=>$staff->id,'referred_to'=>'Instructor']);

        $this->assertTrue(
            Concern::whereKey($c->id)->visibleTo($staff)->exists(),
            'Back on their desk, back in their list'
        );

        $this->assertNull(
            $c->fresh()->handoverLogFor($staff),
            'Back on their desk: live again, not a copy'
        );

        // Which means the form is back.
        $this->actingAs($staff)->get("/concerns/{$c->id}")
            ->assertOk()
            ->assertSee('Update Concern Status')
            ->assertDontSee('Your copy of this concern');
    }

    /** Counselor can update->resolved without 403, and it stays in her history after */
    public function test_counselor_resolves_then_keeps_history(): void
    {
        $staff=$this->u('staff@cspc.edu.ph'); $counselor=$this->u('counselor@cspc.edu.ph');
        $c=Concern::create(['user_id'=>$this->u('student@my.cspc.edu.ph')->id,'category'=>'Personal',
          'department'=>'College of Computer Studies','description'=>'x','urgency'=>'Low',
          'status'=>'submitted','is_anonymous'=>false,'assigned_to'=>$staff->id]);
        $this->actingAs($staff)->patch("/concerns/{$c->id}",[
            'investigation_notes' => 'Looked into this and spoke with the people involved.',
            'resolution_notes' => 'Recorded what is being done about it.','status'=>'referred','referred_to'=>'Guidance Counselor','urgency'=>'Low']);

        // counselor resolves (no 403)
        $resp=$this->actingAs($counselor)->patch("/concerns/{$c->id}",[
            'investigation_notes' => 'Looked into this and spoke with the people involved.',
            'resolution_notes' => 'Recorded what is being done about it.','status'=>'resolved','resolution_attachments'=>[\Illuminate\Http\UploadedFile::fake()->create('proof.jpg', 50, 'image/jpeg')],'urgency'=>'Low','resolution_notes'=>'done']);
        fwrite(STDERR,"  [resolve] counselor update status: ".$resp->getStatusCode()." (expect 302, not 403)\n");
        $this->assertNotEquals(403,$resp->getStatusCode());
        $c->refresh();
        $this->assertEquals('resolved',$c->status);

        // after resolving, counselor STILL sees it (she handled it -> history)
        $stillSees = Concern::whereKey($c->id)->visibleTo($counselor)->exists();
        fwrite(STDERR,"  [history] counselor sees resolved concern she handled: ".($stillSees?'YES (good)':'NO (BAD)')."\n");
        $this->assertTrue($stillSees, 'Counselor keeps concerns she resolved as history');
    }

    /** A staff who NEVER touched a Mental Health concern still cannot see it */
    public function test_uninvolved_staff_still_blocked_from_mh(): void
    {
        $mh=Concern::create(['user_id'=>$this->u('student@my.cspc.edu.ph')->id,'category'=>'Mental Health',
          'department'=>'Guidance Office','description'=>'x','urgency'=>'Low','status'=>'submitted','is_anonymous'=>false]);
        $staff=$this->u('staff@cspc.edu.ph');
        $sees = Concern::whereKey($mh->id)->visibleTo($staff)->exists();
        fwrite(STDERR,"  [privacy] uninvolved staff sees MH concern: ".($sees?'YES (BAD)':'NO (good)')."\n");
        $this->assertFalse($sees, 'Staff must not see MH concerns they never handled');
    }
}