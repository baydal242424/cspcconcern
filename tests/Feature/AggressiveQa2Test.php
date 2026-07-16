<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\User; use App\Models\Concern;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Database\Seeders\RoleSeeder; use Database\Seeders\UserSeeder;
class AggressiveQa2Test extends TestCase {
    use RefreshDatabase;
    protected function setUp():void{parent::setUp();$this->seed([RoleSeeder::class,UserSeeder::class]);}
    private function u($e){return User::where('email',$e)->firstOrFail();}
    private function line($t){fwrite(STDERR,"  $t\n");}
    private function mk($o=[]){return Concern::create(array_merge(['user_id'=>$this->u('student@cspc.edu')->id,'category'=>'Academic','department'=>'College of Computer Studies','description'=>'x','urgency'=>null,'status'=>'submitted','is_anonymous'=>false],$o));}

    // staff A cannot update a concern assigned to staff via another role's referral hijack
    public function test_counselor_cannot_hijack_staff_only_concern(): void {
        $staff=$this->u('staff@cspc.edu');
        $c=$this->mk(['assigned_to'=>$staff->id,'category'=>'Academic']); // never referred to counselor
        $r=$this->actingAs($this->u('counselor@cspc.edu'))->patch("/concerns/{$c->id}",['status'=>'resolved','urgency'=>'Low']);
        $c->refresh();
        $this->line("[hijack] counselor resolving staff's non-referred concern -> status '{$c->status}' (want submitted)");
        $this->assertNotEquals('resolved',$c->status);
    }

    // roleless dashboard must not leak other people's concern data
    public function test_roleless_dashboard_leaks_nothing(): void {
        $this->mk(['description'=>'SECRETDATA','assigned_to'=>$this->u('staff@cspc.edu')->id]);
        $nr=User::create(['name'=>'NR','email'=>'nr2@cspc.edu','password'=>bcrypt('x'),'role_id'=>null]);
        $r=$this->actingAs($nr)->get('/dashboard');
        $leak=str_contains($r->getContent(),'SECRETDATA');
        $this->line("[leak] roleless dashboard shows others' data: ".($leak?'YES (LEAK)':'no'));
        $this->assertFalse($leak);
    }

    // student cannot change category to escape routing after submit (edit tampering)
    public function test_student_editing_reroutes_correctly(): void {
        $c=$this->mk(['category'=>'Academic','assigned_to'=>$this->u('staff@cspc.edu')->id]);
        // student edits to Mental Health while still 'submitted' -> should re-route to counselor, not stay with staff
        // the edit must pass validation (description min:20) or the re-route
        // never runs and the assertion below fails for the wrong reason
        $this->actingAs($this->u('student@cspc.edu'))->patch("/concerns/{$c->id}",[
            'category'=>'Mental Health / Personal','department'=>'College of Computer Studies',
            'description'=>'edited into a mental health concern for rerouting','is_anonymous'=>0,
        ]);
        $c->refresh();
        $counselor=$this->u('counselor@cspc.edu');
        $this->line("[reroute] after student changes to MH, assigned_to=".$c->assigned_to." counselor=".$counselor->id);
        $this->assertEquals($counselor->id,$c->assigned_to,'Changing category should re-route');
    }

    // resolved concern cannot be deleted by student either
    public function test_student_cannot_delete_resolved(): void {
        $c=$this->mk(['status'=>'resolved','assigned_to'=>$this->u('staff@cspc.edu')->id]);
        $this->actingAs($this->u('student@cspc.edu'))->delete("/concerns/{$c->id}");
        $this->line("[integrity] resolved concern exists after student delete: ".(Concern::find($c->id)?'yes':'NO (BUG)'));
        $this->assertNotNull(Concern::find($c->id));
    }

    // IDOR on edit route (not just show)
    public function test_student_cannot_edit_others_concern(): void {
        $other=$this->mk(['user_id'=>$this->u('student2@cspc.edu')->id]);
        $r=$this->actingAs($this->u('student@cspc.edu'))->get("/concerns/{$other->id}/edit");
        $this->line("[IDOR] student edit others -> ".$r->getStatusCode()." (want 403)");
        $r->assertForbidden();
    }

    // mass-assign resolved_at / assigned_to via student edit
    public function test_student_cannot_mass_assign_protected_fields(): void {
        $c=$this->mk();
        // valid description so the edit actually goes through -- otherwise the
        // whole request is rejected and mass-assignment is never exercised
        $this->actingAs($this->u('student@cspc.edu'))->patch("/concerns/{$c->id}",[
            'category'=>'Academic','department'=>'College of Computer Studies',
            'description'=>'legit edit attempting to smuggle staff fields','is_anonymous'=>0,
            'assigned_to'=>$this->u('admin@cspc.edu')->id, // try to reassign
            'status'=>'resolved', // try to resolve
        ]);
        $c->refresh();
        $this->line("[mass-assign] student edit -> status='{$c->status}' assigned_to=".$c->assigned_to);
        $this->assertNotEquals('resolved',$c->status,'student cannot self-resolve via edit');
    }
}