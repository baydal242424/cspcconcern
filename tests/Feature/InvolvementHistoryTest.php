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

    /** After staff refers to counselor, the concern STAYS in staff's history */
    public function test_staff_keeps_referred_concern_as_history(): void
    {
        $staff=$this->u('staff@cspc.edu.ph'); $counselor=$this->u('counselor@cspc.edu.ph');
        $c=Concern::create(['user_id'=>$this->u('student@my.cspc.edu.ph')->id,'category'=>'Academic',
          'department'=>'College of Computer Studies','description'=>'x','urgency'=>'Low',
          'status'=>'submitted','is_anonymous'=>false,'assigned_to'=>$staff->id]);

        // staff refers to counselor (this writes an audit log for staff, and transfers ownership)
        $this->actingAs($staff)->patch("/concerns/{$c->id}",['status'=>'referred','referred_to'=>'Guidance Counselor','urgency'=>'Low']);
        $c->refresh();
        $staffSees = Concern::whereKey($c->id)->visibleTo($staff)->exists();
        fwrite(STDERR,"\n  [history] staff sees referred-away concern: ".($staffSees?'YES (good, reference kept)':'NO (BAD)')."\n");
        $this->assertTrue($staffSees, 'Staff should keep referred concern as history');
    }

    /** Counselor can update->resolved without 403, and it stays in her history after */
    public function test_counselor_resolves_then_keeps_history(): void
    {
        $staff=$this->u('staff@cspc.edu.ph'); $counselor=$this->u('counselor@cspc.edu.ph');
        $c=Concern::create(['user_id'=>$this->u('student@my.cspc.edu.ph')->id,'category'=>'Academic',
          'department'=>'College of Computer Studies','description'=>'x','urgency'=>'Low',
          'status'=>'submitted','is_anonymous'=>false,'assigned_to'=>$staff->id]);
        $this->actingAs($staff)->patch("/concerns/{$c->id}",['status'=>'referred','referred_to'=>'Guidance Counselor','urgency'=>'Low']);

        // counselor resolves (no 403)
        $resp=$this->actingAs($counselor)->patch("/concerns/{$c->id}",['status'=>'resolved','urgency'=>'Low','resolution_notes'=>'done']);
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