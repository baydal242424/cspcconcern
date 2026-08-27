<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\User; use App\Models\Concern;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Database\Seeders\RoleSeeder; use Database\Seeders\UserSeeder;
class AggressiveQa2Test extends TestCase {
    use RefreshDatabase;
    protected function setUp():void{parent::setUp();$this->seed([RoleSeeder::class,UserSeeder::class]);}
    private function u(string $e): User {return User::where('email',$e)->firstOrFail();}
    private function line(string $t): void {fwrite(STDERR,"  $t\n");}
    private function mk(array $o=[]): Concern {return Concern::create(array_merge(['user_id'=>$this->u('student@my.cspc.edu.ph')->id,'category'=>'Academic','department'=>'College of Computer Studies','description'=>'x','urgency'=>null,'status'=>'submitted','is_anonymous'=>false],$o));}

    // staff A cannot update a concern assigned to staff via another role's referral hijack
    public function test_counselor_cannot_hijack_staff_only_concern(): void {
        $staff=$this->u('staff@cspc.edu.ph');
        $c=$this->mk(['assigned_to'=>$staff->id,'category'=>'Academic']); // never referred to counselor
        $r=$this->actingAs($this->u('counselor@cspc.edu.ph'))->patch("/concerns/{$c->id}",['status'=>'resolved','urgency'=>'Low']);
        $c->refresh();
        $this->line("[hijack] counselor resolving staff's non-referred concern -> status '{$c->status}' (want submitted)");
        $this->assertNotEquals('resolved',$c->status);
    }

    // roleless dashboard must not leak other people's concern data
    public function test_roleless_dashboard_leaks_nothing(): void {
        $this->mk(['description'=>'SECRETDATA','assigned_to'=>$this->u('staff@cspc.edu.ph')->id]);
        $nr=User::create(['name'=>'NR','email'=>'nr2@my.cspc.edu.ph','password'=>bcrypt('x'),'role_id'=>null]);
        $r=$this->actingAs($nr)->get('/dashboard');
        $leak=str_contains($r->getContent(),'SECRETDATA');
        $this->line("[leak] roleless dashboard shows others' data: ".($leak?'YES (LEAK)':'no'));
        $this->assertFalse($leak);
    }

    // a concern is final once submitted -- the reporter cannot re-file it into a
    // different category/department to escape the routing staff already saw
    public function test_student_cannot_edit_after_submitting(): void {
        $staff=$this->u('staff@cspc.edu.ph');
        $c=$this->mk(['category'=>'Academic','assigned_to'=>$staff->id]);
        $r=$this->actingAs($this->u('student@my.cspc.edu.ph'))->patch("/concerns/{$c->id}",[
            'category'=>'Mental Health / Personal','department'=>'College of Computer Studies',
            'description'=>'edited into a mental health concern for rerouting','is_anonymous'=>0,
        ]);
        $c->refresh();
        $this->line("[final] student edit -> ".$r->getStatusCode()." (want 403), category still '{$c->category}'");
        $r->assertForbidden();
        $this->assertEquals('Academic',$c->category,'Category must survive the rejected edit');
        $this->assertEquals($staff->id,$c->assigned_to,'Routing must not change');
    }

    // resolved concern cannot be deleted by student either
    public function test_student_cannot_delete_resolved(): void {
        $c=$this->mk(['status'=>'resolved','assigned_to'=>$this->u('staff@cspc.edu.ph')->id]);
        $this->actingAs($this->u('student@my.cspc.edu.ph'))->delete("/concerns/{$c->id}");
        $this->line("[integrity] resolved concern exists after student delete: ".(Concern::find($c->id)?'yes':'NO (BUG)'));
        $this->assertNotNull(Concern::find($c->id));
    }

    // the edit form is gone entirely -- the route must not exist for anyone
    public function test_edit_route_no_longer_exists(): void {
        $own=$this->mk();
        $r=$this->actingAs($this->u('student@my.cspc.edu.ph'))->get("/concerns/{$own->id}/edit");
        $this->line("[removed] GET edit form -> ".$r->getStatusCode()." (want 404)");
        $r->assertNotFound();
    }

    // IDOR: another student's concern must still be unreachable via update
    public function test_student_cannot_update_others_concern(): void {
        $other=$this->mk(['user_id'=>$this->u('student2@my.cspc.edu.ph')->id]);
        $r=$this->actingAs($this->u('student@my.cspc.edu.ph'))->patch("/concerns/{$other->id}",[
            'category'=>'Academic','department'=>'College of Computer Studies',
            'description'=>'trying to edit somebody elses concern entirely',
        ]);
        $this->line("[IDOR] student update others -> ".$r->getStatusCode()." (want 403)");
        $r->assertForbidden();
    }

    // mass-assign resolved_at / assigned_to through the update endpoint
    public function test_student_cannot_mass_assign_protected_fields(): void {
        $c=$this->mk();
        $this->actingAs($this->u('student@my.cspc.edu.ph'))->patch("/concerns/{$c->id}",[
            'category'=>'Academic','department'=>'College of Computer Studies',
            'description'=>'legit edit attempting to smuggle staff fields','is_anonymous'=>0,
            'assigned_to'=>$this->u('admin@cspc.edu.ph')->id, // try to reassign
            'status'=>'resolved', // try to resolve
        ]);
        $c->refresh();
        $this->line("[mass-assign] student edit -> status='{$c->status}' assigned_to=".$c->assigned_to);
        $this->assertNotEquals('resolved',$c->status,'student cannot self-resolve via edit');
    }
}