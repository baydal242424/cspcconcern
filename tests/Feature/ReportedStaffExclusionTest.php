<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\User; use App\Models\Concern;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Database\Seeders\RoleSeeder; use Database\Seeders\UserSeeder;
class ReportedStaffExclusionTest extends TestCase {
    use RefreshDatabase;
    protected function setUp():void{parent::setUp();$this->seed([RoleSeeder::class,UserSeeder::class]);}
    private function u($e){return User::where('email',$e)->firstOrFail();}
    private function line($t){fwrite(STDERR,"  $t\n");}

    /** The reported staff CANNOT see or open a concern about themselves */
    public function test_reported_staff_cannot_see_concern_about_them(): void {
        $staff=$this->u('staff@cspc.edu');
        $c=Concern::create(['user_id'=>$this->u('student@cspc.edu')->id,'category'=>'Academic',
            'department'=>'College of Computer Studies','description'=>'about juan','urgency'=>null,
            'status'=>'submitted','is_anonymous'=>true,'about_staff_id'=>$staff->id]);
        $seesInList = Concern::whereKey($c->id)->visibleTo($staff)->exists();
        $this->line("[coi] reported staff sees concern in scope: ".($seesInList?'YES (LEAK)':'no'));
        $this->assertFalse($seesInList,'Reported staff must not see concern about them');
        // and cannot open it
        $r=$this->actingAs($staff)->get("/concerns/{$c->id}");
        $this->line("[coi] reported staff opens concern -> ".$r->getStatusCode()." (want 403)");
        $r->assertForbidden();
        // and cannot update it
        $r2=$this->actingAs($staff)->patch("/concerns/{$c->id}",['status'=>'resolved','urgency'=>'Low']);
        $c->refresh();
        $this->line("[coi] reported staff update -> status still '{$c->status}'");
        $this->assertNotEquals('resolved',$c->status);
    }

    /** A DIFFERENT staff (not the reported one) still works normally */
    public function test_other_staff_unaffected(): void {
        // make a second faculty user
        $role=\App\Models\Role::where('name','Faculty/Staff')->first();
        $other=User::create(['name'=>'Prof. Other','email'=>'other@cspc.edu','password'=>bcrypt('x'),'role_id'=>$role->id]);
        $reported=$this->u('staff@cspc.edu');
        $c=Concern::create(['user_id'=>$this->u('student@cspc.edu')->id,'category'=>'Academic',
            'department'=>'College of Computer Studies','description'=>'about juan','urgency'=>null,
            'status'=>'submitted','is_anonymous'=>true,'about_staff_id'=>$reported->id,'assigned_to'=>$other->id]);
        $sees = Concern::whereKey($c->id)->visibleTo($other)->exists();
        $this->line("[coi] a DIFFERENT staff (assigned) can see it: ".($sees?'yes (good)':'NO (BAD)'));
        $this->assertTrue($sees,'Non-reported staff assigned to it should see it');
    }

    /** Head of School still sees it (they adjudicate conflicts) */
    public function test_head_still_sees_conflict_concern(): void {
        $staff=$this->u('staff@cspc.edu');
        $c=Concern::create(['user_id'=>$this->u('student@cspc.edu')->id,'category'=>'Academic',
            'department'=>'College of Computer Studies','description'=>'about juan','urgency'=>null,
            'status'=>'submitted','is_anonymous'=>true,'about_staff_id'=>$staff->id]);
        $sees=Concern::whereKey($c->id)->visibleTo($this->u('head@cspc.edu'))->exists();
        $this->line("[coi] Head of School sees conflict concern: ".($sees?'yes (good)':'NO'));
        $this->assertTrue($sees);
    }

    /** The student who submitted still sees their own concern */
    public function test_student_still_sees_own(): void {
        $staff=$this->u('staff@cspc.edu');
        $student=$this->u('student@cspc.edu');
        $c=Concern::create(['user_id'=>$student->id,'category'=>'Academic',
            'department'=>'College of Computer Studies','description'=>'about juan','urgency'=>null,
            'status'=>'submitted','is_anonymous'=>true,'about_staff_id'=>$staff->id]);
        $sees=Concern::whereKey($c->id)->visibleTo($student)->exists();
        $this->line("[coi] submitting student sees own: ".($sees?'yes (good)':'NO (BAD)'));
        $this->assertTrue($sees);
    }
}