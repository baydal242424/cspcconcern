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

    /** A concern ABOUT the assigned staff must NOT be routed to that staff */
    public function test_concern_about_staff_is_not_routed_to_them(): void {
        $staff=$this->u('staff@cspc.edu'); // the only Faculty/Staff user
        // student submits an Academic concern that is ABOUT that staff member
        $resp=$this->actingAs($this->u('student@cspc.edu'))->post('/concerns',[
            'category'=>'Academic','department'=>'College of Computer Studies',
            'description'=>'complaint about this teacher','about_staff_id'=>$staff->id,
        ]);
        $c=Concern::where('description','complaint about this teacher')->firstOrFail();
        $this->line("[conflict] concern about staff({$staff->id}) assigned_to=".var_export($c->assigned_to,true));
        $this->assertNotEquals($staff->id,$c->assigned_to,'Must not assign a concern to the person it is about');
        // it should have escalated to Department Head or Head of School
        $handler=User::find($c->assigned_to);
        $handlerRole=optional(optional($handler)->role)->name;
        $this->line("[conflict] escalated to role: ".($handlerRole ?? 'NONE'));
        $this->assertContains($handlerRole,['Department Head','Head of School'],'Should escalate to higher authority');
    }

    /** Normal concern (not about anyone) still routes normally to staff */
    public function test_normal_concern_routes_to_staff(): void {
        $resp=$this->actingAs($this->u('student@cspc.edu'))->post('/concerns',[
            'category'=>'Academic','department'=>'College of Computer Studies',
            'description'=>'a normal concern that names no staff member',
        ]);
        $c=Concern::where('description','a normal concern that names no staff member')->firstOrFail();
        $handlerRole=optional(optional(User::find($c->assigned_to))->role)->name;
        $this->line("[normal] routed to: ".$handlerRole);
        $this->assertEquals('Faculty/Staff',$handlerRole);
    }
}