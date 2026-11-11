<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\User; use App\Models\Concern; use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Database\Seeders\RoleSeeder; use Database\Seeders\UserSeeder;
class BreakGlassTest extends TestCase {
    use RefreshDatabase;
    protected function setUp():void{parent::setUp();$this->seed([RoleSeeder::class,UserSeeder::class]);}
    private function u($e){return User::where('email',$e)->firstOrFail();}
    private function line($t){fwrite(STDERR,"  $t\n");}
    /**
     * An anonymous concern that has reached the Head of School.
     *
     * assigned_to matters now. Visibility is the desk a concern sits on,
     * so the Head of School reads what is escalated to them rather than
     * everything -- and revealIdentity() checks they may view the concern
     * before it will unmask anybody. A concern they do not hold is one
     * they cannot reveal, which is the point: the safeguard follows the
     * case instead of the rank.
     */
    private function anon(){return Concern::create(['user_id'=>$this->u('student@my.cspc.edu.ph')->id,'category'=>'Bullying','department'=>'Guidance Office','description'=>'sensitive','urgency'=>null,'status'=>'submitted','is_anonymous'=>true,'assigned_to'=>$this->u('head@cspc.edu.ph')->id]);}

    public function test_head_can_reveal_with_reason_and_it_is_logged(): void {
        $c=$this->anon();
        $head=$this->u('head@cspc.edu.ph');
        $r=$this->actingAs($head)->post("/concerns/{$c->id}/reveal-identity",['identity_reveal_reason'=>'Credible safety risk requires contacting the student.']);
        $c->refresh();
        $this->line("[reveal] revealed_at set: ".($c->identity_revealed_at?'yes':'no').", by=".$c->identity_revealed_by);
        $this->assertNotNull($c->identity_revealed_at);
        $this->assertEquals($head->id,$c->identity_revealed_by);
        $logged=AuditLog::where('concern_id',$c->id)->where('action','identity_revealed')->exists();
        $this->line("[reveal] audit logged: ".($logged?'yes':'no'));
        $this->assertTrue($logged,'Reveal must be audit-logged');
    }

    public function test_reveal_requires_a_reason(): void {
        $c=$this->anon();
        $r=$this->actingAs($this->u('head@cspc.edu.ph'))->post("/concerns/{$c->id}/reveal-identity",['identity_reveal_reason'=>'']);
        $r->assertSessionHasErrors('identity_reveal_reason');
        $c->refresh();
        $this->line("[reveal] blank reason rejected; revealed=".($c->identityIsRevealed()?'yes(BAD)':'no'));
        $this->assertFalse($c->identityIsRevealed());
    }

    public function test_non_head_cannot_reveal(): void {
        $c=$this->anon();
        foreach(['staff@cspc.edu.ph','counselor@cspc.edu.ph','admin@cspc.edu.ph','student@my.cspc.edu.ph'] as $email){
            $r=$this->actingAs($this->u($email))->post("/concerns/{$c->id}/reveal-identity",['identity_reveal_reason'=>'trying to peek at the identity']);
            $this->line("[reveal] $email attempt -> ".$r->getStatusCode()." (want 403)");
            $r->assertForbidden();
        }
        $c->refresh();
        $this->assertFalse($c->identityIsRevealed(),'Only Head of School may reveal');
    }

    public function test_staff_still_cannot_see_identity_even_after_reveal(): void {
        // reveal only exposes identity to Head of School UI, not to staff
        $c=$this->anon();
        $c->update(['assigned_to'=>$this->u('counselor@cspc.edu.ph')->id]);
        $this->actingAs($this->u('head@cspc.edu.ph'))->post("/concerns/{$c->id}/reveal-identity",['identity_reveal_reason'=>'Investigating a suspected false report about staff.']);
        // counselor views -> must still see Anonymous, not the name
        $resp=$this->actingAs($this->u('counselor@cspc.edu.ph'))->get("/concerns/{$c->id}");
        $leak=str_contains($resp->getContent(),'John Student');
        $this->line("[reveal] counselor sees identity after reveal: ".($leak?'YES (LEAK)':'no'));
        $this->assertFalse($leak,'Reveal must not expose identity to handlers');
    }

    /**
     * The Head of School reads what reaches them, not everything.
     *
     * This asserted the opposite: that the rank could open any concern in
     * the college, so that escalations and suspected false reports could be
     * adjudicated. It was one of four standing windows that let staff read
     * concerns they were not handling, and it was the widest -- every
     * student's mental health and bullying report, in full.
     *
     * Content is still readable once a case is theirs, which is what
     * adjudicating actually needs, and the identity behind an anonymous
     * report is still separate from the content either way.
     */
    public function test_head_reads_a_concern_on_their_own_desk_and_not_others(): void {
        $head=$this->u('head@cspc.edu.ph');

        $theirs=Concern::create(['user_id'=>$this->u('student@my.cspc.edu.ph')->id,'category'=>'Mental Health','department'=>'Guidance Office','description'=>'HEADCANREAD','urgency'=>null,'status'=>'submitted','is_anonymous'=>true,'assigned_to'=>$head->id]);
        $resp=$this->actingAs($head)->get("/concerns/{$theirs->id}");
        $resp->assertOk();
        $resp->assertSee('HEADCANREAD');

        $somebodyElses=Concern::create(['user_id'=>$this->u('student@my.cspc.edu.ph')->id,'category'=>'Mental Health','department'=>'Guidance Office','description'=>'NOTTHEIRS','urgency'=>null,'status'=>'submitted','is_anonymous'=>true,'assigned_to'=>$this->u('counselor@cspc.edu.ph')->id]);
        $this->actingAs($head)->get("/concerns/{$somebodyElses->id}")->assertForbidden();

        $this->line("[content] head reads their own case, not the counsellor's");
    }
}