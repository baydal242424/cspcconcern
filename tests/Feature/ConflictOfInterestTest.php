<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\User; use App\Models\Concern;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Database\Seeders\RoleSeeder; use Database\Seeders\UserSeeder;
class ConflictOfInterestTest extends TestCase {
    use RefreshDatabase;
    protected function setUp():void{parent::setUp();$this->seed([RoleSeeder::class,UserSeeder::class]);}
    private function u($e){return User::where('email',$e)->firstOrFail();}
    private function line($t){fwrite(STDERR,"  $t\n");}

    /** A concern ABOUT a staff member must NOT be routed to that staff member */
    public function test_concern_about_staff_is_not_routed_to_them(): void {
        $staff=$this->u('ccs.instructor@cspc.edu.ph'); // the CCS concern would normally go to them
        // student submits an Academic concern that is ABOUT that staff member
        $this->actingAs($this->u('student@my.cspc.edu.ph'))->post('/concerns',[
            'category'=>'Academic','department'=>'College of Computer Studies',
            'description'=>'complaint about this teacher','about_staff_id'=>$staff->id,
        ]);
        $c=Concern::where('description','complaint about this teacher')->firstOrFail();
        $this->line("[conflict] concern about staff({$staff->id}) assigned_to=".var_export($c->assigned_to,true));
        $this->assertNotEquals($staff->id,$c->assigned_to,'Must not assign a concern to the person it is about');
        // Another untainted handler exists, so it goes to them rather than escalating.
        $handlerRole=optional(optional(User::find($c->assigned_to))->role)->name;
        $this->line("[conflict] handed to role: ".($handlerRole ?? 'NONE'));
        $this->assertNotNull($c->assigned_to,'Someone must still own it');
        $this->assertEquals('Instructor',$handlerRole);
    }

    /** With no untainted peer left, it escalates up the chain instead */
    public function test_concern_about_the_last_staff_escalates(): void {
        $staff=$this->u('staff@cspc.edu.ph');
        // remove every other Instructor so the reported person is the only one
        User::whereHas('role',fn($q)=>$q->where('name','Instructor'))
            ->where('id','!=',$staff->id)->delete();
        $this->actingAs($this->u('student@my.cspc.edu.ph'))->post('/concerns',[
            'category'=>'Academic','department'=>'College of Computer Studies',
            'description'=>'complaint about the only teacher there is','about_staff_id'=>$staff->id,
        ]);
        $c=Concern::where('description','complaint about the only teacher there is')->firstOrFail();
        $handlerRole=optional(optional(User::find($c->assigned_to))->role)->name;
        $this->line("[conflict] escalated to role: ".($handlerRole ?? 'NONE'));
        $this->assertNotEquals($staff->id,$c->assigned_to,'Must not assign a concern to the person it is about');
        $this->assertContains($handlerRole,['Dean','Head of School'],'Should escalate to higher authority');
    }

    /** Normal concern (not about anyone) still routes normally to staff */
    public function test_normal_concern_routes_to_staff(): void {
        $resp=$this->actingAs($this->u('student@my.cspc.edu.ph'))->post('/concerns',[
            'category'=>'Academic','department'=>'College of Computer Studies',
            'description'=>'a normal concern that names no staff member',
        ]);
        $c=Concern::where('description','a normal concern that names no staff member')->firstOrFail();
        $handlerRole=optional(optional(User::find($c->assigned_to))->role)->name;
        $this->line("[normal] routed to: ".$handlerRole);
        $this->assertEquals('Instructor',$handlerRole);
    }
}