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
    private function anon(){return Concern::create(['user_id'=>$this->u('student@cspc.edu')->id,'category'=>'Bullying / Harassment','department'=>'Guidance Office','description'=>'sensitive','urgency'=>null,'status'=>'submitted','is_anonymous'=>true]);}

    public function test_head_can_reveal_with_reason_and_it_is_logged(): void {
        $c=$this->anon();
        $head=$this->u('head@cspc.edu');
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
        $r=$this->actingAs($this->u('head@cspc.edu'))->post("/concerns/{$c->id}/reveal-identity",['identity_reveal_reason'=>'']);
        $r->assertSessionHasErrors('identity_reveal_reason');
        $c->refresh();
        $this->line("[reveal] blank reason rejected; revealed=".($c->identityIsRevealed()?'yes(BAD)':'no'));
        $this->assertFalse($c->identityIsRevealed());
    }

    public function test_non_head_cannot_reveal(): void {
        $c=$this->anon();
        foreach(['staff@cspc.edu','counselor@cspc.edu','admin@cspc.edu','student@cspc.edu'] as $email){
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
        $c->update(['assigned_to'=>$this->u('counselor@cspc.edu')->id]);
        $this->actingAs($this->u('head@cspc.edu'))->post("/concerns/{$c->id}/reveal-identity",['identity_reveal_reason'=>'Investigating a suspected false report about staff.']);
        // counselor views -> must still see Anonymous, not the name
        $resp=$this->actingAs($this->u('counselor@cspc.edu'))->get("/concerns/{$c->id}");
        $leak=str_contains($resp->getContent(),'John Student');
        $this->line("[reveal] counselor sees identity after reveal: ".($leak?'YES (LEAK)':'no'));
        $this->assertFalse($leak,'Reveal must not expose identity to handlers');
    }

    public function test_head_sees_content_of_all_concerns(): void {
        // Head of School can read content (to adjudicate) but that is separate from identity
        $c=Concern::create(['user_id'=>$this->u('student@cspc.edu')->id,'category'=>'Mental Health / Personal','department'=>'Guidance Office','description'=>'HEADCANREAD','urgency'=>null,'status'=>'submitted','is_anonymous'=>true]);
        $resp=$this->actingAs($this->u('head@cspc.edu'))->get("/concerns/{$c->id}");
        $resp->assertOk();
        $resp->assertSee('HEADCANREAD');
        $this->line("[content] head reads MH content: yes");
    }
}