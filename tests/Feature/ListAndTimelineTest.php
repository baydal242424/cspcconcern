<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\User; use App\Models\Concern;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Database\Seeders\RoleSeeder; use Database\Seeders\UserSeeder;

class ListAndTimelineTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void { parent::setUp(); $this->seed([RoleSeeder::class, UserSeeder::class]); }
    private function u($e){ return User::where('email',$e)->firstOrFail(); }

    /** Active list hides resolved by default; toggle shows them */
    public function test_resolved_hidden_by_default_and_toggle_shows(): void
    {
        $staff=$this->u('staff@cspc.edu.ph');
        // one open (referred) and one resolved, both handled by staff
        $open=Concern::create(['user_id'=>$this->u('student@my.cspc.edu.ph')->id,'category'=>'Academic',
          'department'=>'College of Computer Studies','description'=>'OPEN','urgency'=>'Low',
          'status'=>'submitted','is_anonymous'=>false,'assigned_to'=>$staff->id]);
        $this->actingAs($staff)->patch("/concerns/{$open->id}",['status'=>'referred','referred_to'=>'Guidance Counselor','urgency'=>'Low']);

        $resolved=Concern::create(['user_id'=>$this->u('student@my.cspc.edu.ph')->id,'category'=>'Academic',
          'department'=>'College of Computer Studies','description'=>'CLOSED','urgency'=>'Low',
          'status'=>'submitted','is_anonymous'=>false,'assigned_to'=>$staff->id]);
        $this->actingAs($staff)->patch("/concerns/{$resolved->id}",['status'=>'resolved','urgency'=>'Low','resolution_notes'=>'done']);

        // default list: resolved hidden
        $def=$this->actingAs($staff)->get('/concerns');
        $def->assertOk();
        $hasClosed = str_contains($def->getContent(), '/concerns/'.$resolved->id);
        fwrite(STDERR,"\n  [toggle] default list shows resolved: ".($hasClosed?'YES (BAD)':'NO (good)')."\n");
        $this->assertFalse($hasClosed,'Resolved should be hidden by default');

        // with toggle: resolved appears
        $all=$this->actingAs($staff)->get('/concerns?show_resolved=1');
        $all->assertOk();
        $hasClosed2 = str_contains($all->getContent(), '/concerns/'.$resolved->id);
        fwrite(STDERR,"  [toggle] show_resolved=1 shows resolved: ".($hasClosed2?'YES (good)':'NO (BAD)')."\n");
        $this->assertTrue($hasClosed2,'Toggle should reveal resolved');
    }

    /** Timeline shows referral destination and resolution with timestamps */
    public function test_timeline_shows_referral_and_resolution(): void
    {
        $staff=$this->u('staff@cspc.edu.ph'); $counselor=$this->u('counselor@cspc.edu.ph');
        $c=Concern::create(['user_id'=>$this->u('student@my.cspc.edu.ph')->id,'category'=>'Academic',
          'department'=>'College of Computer Studies','description'=>'x','urgency'=>'Low',
          'status'=>'submitted','is_anonymous'=>false,'assigned_to'=>$staff->id]);
        $this->actingAs($staff)->patch("/concerns/{$c->id}",['status'=>'referred','referred_to'=>'Guidance Counselor','urgency'=>'Low']);
        $this->actingAs($counselor)->patch("/concerns/{$c->id}",['status'=>'resolved','urgency'=>'Low','resolution_notes'=>'done']);

        $resp=$this->actingAs($counselor)->get("/concerns/{$c->id}");
        $resp->assertOk();
        $resp->assertSee('Activity Timeline');
        $resp->assertSee('Referred to Guidance Counselor');
        $resp->assertSee('Marked as resolved');
        fwrite(STDERR,"  [timeline] shows 'Referred to Guidance Counselor' + 'Marked as resolved': YES\n");
    }
}