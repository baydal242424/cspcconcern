<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\User; use App\Models\Concern;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Database\Seeders\RoleSeeder; use Database\Seeders\UserSeeder;
class RefFixesTest extends TestCase {
    use RefreshDatabase;
    protected function setUp():void{parent::setUp();$this->seed([RoleSeeder::class,UserSeeder::class]);}
    private function u($e){return User::where('email',$e)->firstOrFail();}
    private function line($t){fwrite(STDERR,"  $t\n");}

    /** Referral to Department Head now transfers to a real user who can resolve */
    public function test_dept_head_referral_can_be_resolved(): void {
        $counselor=$this->u('counselor@cspc.edu');
        $depthead=$this->u('depthead@cspc.edu');
        $c=Concern::create(['user_id'=>$this->u('student@cspc.edu')->id,'category'=>'Bullying / Harassment',
            'department'=>'Guidance Office','description'=>'x','urgency'=>'Medium','status'=>'submitted',
            'is_anonymous'=>true,'assigned_to'=>$counselor->id]);
        // counselor refers to Department Head
        $this->actingAs($counselor)->patch("/concerns/{$c->id}",['status'=>'referred','referred_to'=>'Department Head','urgency'=>'Medium']);
        $c->refresh();
        $this->line("[refer] after refer to Dept Head, assigned_to=".$c->assigned_to." (depthead id=".$depthead->id.")");
        $this->assertEquals($depthead->id,$c->assigned_to,'Ownership must transfer to the Department Head');
        // dept head resolves it
        $r=$this->actingAs($depthead)->patch("/concerns/{$c->id}",['status'=>'resolved','urgency'=>'Medium','resolution_notes'=>'handled']);
        $c->refresh();
        $this->line("[refer] dept head resolve -> status '{$c->status}' (resp ".$r->getStatusCode().")");
        $this->assertEquals('resolved',$c->status,'Department Head must be able to resolve');
    }

    /** Reveal reason validation: junk/short is rejected, proper reason passes */
    public function test_reveal_reason_validation(): void {
        $head=$this->u('head@cspc.edu');
        $c=Concern::create(['user_id'=>$this->u('student@cspc.edu')->id,'category'=>'Bullying / Harassment',
            'department'=>'Guidance Office','description'=>'x','urgency'=>null,'status'=>'submitted','is_anonymous'=>true]);
        // too short
        $r1=$this->actingAs($head)->post("/concerns/{$c->id}/reveal-identity",['identity_reveal_reason'=>'dsadasda']);
        $r1->assertSessionHasErrors('identity_reveal_reason');
        // single long word (no spaces) -> rejected by regex
        $r2=$this->actingAs($head)->post("/concerns/{$c->id}/reveal-identity",['identity_reveal_reason'=>'dsadasdadasdadasdadasda']);
        $r2->assertSessionHasErrors('identity_reveal_reason');
        $c->refresh();
        $this->line("[validate] junk reasons rejected; revealed=".($c->identityIsRevealed()?'yes(BAD)':'no'));
        $this->assertFalse($c->identityIsRevealed());
        // proper reason passes
        $r3=$this->actingAs($head)->post("/concerns/{$c->id}/reveal-identity",['identity_reveal_reason'=>'Credible safety risk to the student requires contact.']);
        $c->refresh();
        $this->line("[validate] proper reason accepted; revealed=".($c->identityIsRevealed()?'yes':'no'));
        $this->assertTrue($c->identityIsRevealed());
    }

    /** Referral to a role with NO users is rejected (no stranding) */
    public function test_referral_to_empty_role_rejected(): void {
        // delete all Department Heads to simulate an empty role
        User::whereHas('role',fn($q)=>$q->where('name','Department Head'))->delete();
        $staff=$this->u('staff@cspc.edu');
        $c=Concern::create(['user_id'=>$this->u('student@cspc.edu')->id,'category'=>'Academic',
            'department'=>'College of Computer Studies','description'=>'x','urgency'=>'Low','status'=>'submitted',
            'is_anonymous'=>false,'assigned_to'=>$staff->id]);
        $r=$this->actingAs($staff)->patch("/concerns/{$c->id}",['status'=>'referred','referred_to'=>'Department Head','urgency'=>'Low']);
        $r->assertSessionHasErrors('referred_to');
        $c->refresh();
        $this->line("[strand] refer to empty role rejected; status still '{$c->status}'");
        $this->assertNotEquals('referred',$c->status,'Should not strand the concern');
    }
}