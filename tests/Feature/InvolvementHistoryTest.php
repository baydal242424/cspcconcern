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
     * Referring a case away hands it over. It does not keep a copy.
     *
     * This used to assert the opposite -- that whoever let go of a concern
     * went on reading it for good. That made a referral a copy rather than
     * a hand-off: a report that passed through three desks on its way
     * somewhere was afterwards readable by all three, and each of them kept
     * watching what the office that actually took it wrote on it.
     */
    public function test_staff_loses_a_concern_they_referred_away(): void
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
        $staffSees = Concern::whereKey($c->id)->visibleTo($staff)->exists();
        fwrite(STDERR,"\n  [history] staff sees referred-away concern: ".($staffSees?'YES (BAD)':'NO (good, handed over)')."\n");
        $this->assertFalse($staffSees, 'A referral is a hand-off: the sender lets go of it');

        // And having let go, they cannot act on it either.
        $this->actingAs($staff)->get("/concerns/{$c->id}")->assertForbidden();
    }

    /**
     * A successful referral must not land on a 403.
     *
     * update() sent everybody to the concern page afterwards. Once a
     * referral became a real hand-off, that page was shut to the person
     * who had just referred it -- so the reward for referring correctly
     * was a Forbidden screen with no sign the referral had worked.
     */
    public function test_referring_lands_on_the_list_not_a_forbidden_page(): void
    {
        $staff=$this->u('staff@cspc.edu.ph');
        $c=Concern::create(['user_id'=>$this->u('student@my.cspc.edu.ph')->id,'category'=>'Personal',
          'department'=>'College of Computer Studies','description'=>'x','urgency'=>'Low',
          'status'=>'submitted','is_anonymous'=>false,'assigned_to'=>$staff->id]);

        $this->actingAs($staff)->patch("/concerns/{$c->id}",[
            'investigation_notes' => 'Looked into this and spoke with the people involved.',
            'resolution_notes' => 'Recorded what is being done about it.',
            'status'=>'referred','referred_to'=>'Guidance Counselor','urgency'=>'Low'])
            ->assertRedirect(route('concerns.index'));

        // And the page they land on actually opens, with the confirmation.
        $this->actingAs($staff)->get(route('concerns.index'))
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

    /** Referred back, it is visible again -- it is their desk once more. */
    public function test_a_concern_referred_back_returns_to_view(): void
    {
        $staff=$this->u('staff@cspc.edu.ph'); $counselor=$this->u('counselor@cspc.edu.ph');
        $c=Concern::create(['user_id'=>$this->u('student@my.cspc.edu.ph')->id,'category'=>'Personal',
          'department'=>'College of Computer Studies','description'=>'x','urgency'=>'Low',
          'status'=>'submitted','is_anonymous'=>false,'assigned_to'=>$staff->id]);

        $this->actingAs($staff)->patch("/concerns/{$c->id}",[
            'investigation_notes' => 'Looked into this and spoke with the people involved.',
            'resolution_notes' => 'Recorded what is being done about it.','status'=>'referred','referred_to'=>'Guidance Counselor','urgency'=>'Low']);
        $this->assertFalse(Concern::whereKey($c->id)->visibleTo($staff)->exists());

        // The counsellor hands it back.
        $c->refresh()->update(['assigned_to'=>$staff->id,'referred_to'=>'Instructor']);

        $this->assertTrue(
            Concern::whereKey($c->id)->visibleTo($staff)->exists(),
            'Back on their desk, back in their list'
        );
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