<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\User; use App\Models\Concern;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Database\Seeders\RoleSeeder; use Database\Seeders\UserSeeder;

class AggressiveQaTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void { parent::setUp(); $this->seed([RoleSeeder::class, UserSeeder::class]); }
    private function u($e){ return User::where('email',$e)->firstOrFail(); }
    private function mk(array $o=[]): Concern {
        return Concern::create(array_merge([
            'user_id'=>$this->u('student@my.cspc.edu.ph')->id,'category'=>'Academic',
            'department'=>'College of Computer Studies','description'=>'x','urgency'=>null,
            'status'=>'submitted','is_anonymous'=>false,
        ],$o));
    }
    private function line($t){ fwrite(STDERR,"  $t\n"); }

    // ========== A. IDOR / broken access control ==========
    public function test_student_cannot_open_another_students_concern(): void
    {
        $other=$this->mk(['user_id'=>$this->u('student2@my.cspc.edu.ph')->id]);
        $r=$this->actingAs($this->u('student@my.cspc.edu.ph'))->get("/concerns/{$other->id}");
        $this->line("[IDOR] student opening other's concern -> ".$r->getStatusCode()." (want 403)");
        $r->assertForbidden();
    }
    public function test_student_cannot_update_status_via_forged_post(): void
    {
        // student tries to send staff-only fields (status/urgency) to their own concern
        $c=$this->mk();
        // valid description so the student edit succeeds -- proves the staff-only
        // fields are ignored on an ACCEPTED edit, not just on a rejected one
        $this->actingAs($this->u('student@my.cspc.edu.ph'))->patch("/concerns/{$c->id}",[
            'status'=>'resolved','urgency'=>'Critical','category'=>'Academic',
            'department'=>'College of Computer Studies',
            'description'=>'an edited description of acceptable length',
        ]);
        $c->refresh();
        $this->line("[escalation] student forced status='{$c->status}' urgency=".var_export($c->urgency,true)." (want NOT resolved/Critical)");
        $this->assertNotEquals('resolved',$c->status,'Student must not set status');
        $this->assertNull($c->urgency,'Student must not set urgency');
    }
    public function test_student_cannot_delete_processed_concern(): void
    {
        $c=$this->mk(['status'=>'in_progress','assigned_to'=>$this->u('staff@cspc.edu.ph')->id]);
        $r=$this->actingAs($this->u('student@my.cspc.edu.ph'))->delete("/concerns/{$c->id}");
        $this->line("[integrity] student delete in-progress -> ".$r->getStatusCode()." concern exists=".(Concern::find($c->id)?'yes':'no'));
        $this->assertNotNull(Concern::find($c->id),'In-progress concern must survive student delete');
    }

    // ========== B. Privacy / anonymity ==========
    public function test_anonymous_identity_never_leaks_to_staff_on_show(): void
    {
        $c=$this->mk(['is_anonymous'=>true,'assigned_to'=>$this->u('staff@cspc.edu.ph')->id]);
        $r=$this->actingAs($this->u('staff@cspc.edu.ph'))->get("/concerns/{$c->id}");
        $leak=str_contains($r->getContent(),'John Student');
        $this->line("[privacy] staff sees anon name on detail -> ".($leak?'YES (LEAK)':'no'));
        $this->assertFalse($leak);
    }
    public function test_admin_cannot_read_untouched_mental_health(): void
    {
        $mh=$this->mk(['category'=>'Mental Health']);
        $r=$this->actingAs($this->u('admin@cspc.edu.ph'))->get("/concerns/{$mh->id}");
        $this->line("[least-priv] admin opens untouched MH -> ".$r->getStatusCode()." (want 403)");
        $r->assertForbidden();
    }
    public function test_staff_cannot_read_untouched_mental_health(): void
    {
        $mh=$this->mk(['category'=>'Mental Health']);
        $r=$this->actingAs($this->u('staff@cspc.edu.ph'))->get("/concerns/{$mh->id}");
        $this->line("[least-priv] staff opens untouched MH -> ".$r->getStatusCode()." (want 403)");
        $r->assertForbidden();
    }

    // ========== C. Business-rule / state machine ==========
    public function test_cannot_edit_resolved_concern(): void
    {
        $c=$this->mk(['status'=>'resolved','assigned_to'=>$this->u('staff@cspc.edu.ph')->id,'urgency'=>'Low']);
        $this->actingAs($this->u('staff@cspc.edu.ph'))->patch("/concerns/{$c->id}",['status'=>'in_progress','urgency'=>'High']);
        $c->refresh();
        $this->line("[state] edit resolved -> status now '{$c->status}' (want resolved)");
        $this->assertEquals('resolved',$c->status);
    }
    public function test_referral_requires_destination(): void
    {
        $c=$this->mk(['assigned_to'=>$this->u('staff@cspc.edu.ph')->id]);
        $r=$this->actingAs($this->u('staff@cspc.edu.ph'))->patch("/concerns/{$c->id}",['status'=>'referred','referred_to'=>'','urgency'=>'Low']);
        $r->assertSessionHasErrors('referred_to');
        $this->line("[state] refer w/o destination rejected: yes");
    }
    public function test_invalid_status_rejected(): void
    {
        $c=$this->mk(['assigned_to'=>$this->u('staff@cspc.edu.ph')->id]);
        $r=$this->actingAs($this->u('staff@cspc.edu.ph'))->patch("/concerns/{$c->id}",['status'=>'HACKED','urgency'=>'Low']);
        $r->assertSessionHasErrors('status');
        $this->line("[validation] invalid status 'HACKED' rejected: yes");
    }
    public function test_invalid_urgency_rejected(): void
    {
        $c=$this->mk(['assigned_to'=>$this->u('staff@cspc.edu.ph')->id]);
        $r=$this->actingAs($this->u('staff@cspc.edu.ph'))->patch("/concerns/{$c->id}",['status'=>'in_progress','urgency'=>'SUPERCRITICAL']);
        $r->assertSessionHasErrors('urgency');
        $this->line("[validation] invalid urgency rejected: yes");
    }
    public function test_invalid_category_on_create_rejected(): void
    {
        $r=$this->actingAs($this->u('student@my.cspc.edu.ph'))->post("/concerns",[
            'category'=>'Nonexistent','department'=>'College of Computer Studies','description'=>'x',
        ]);
        $r->assertSessionHasErrors('category');
        $this->line("[validation] invalid category rejected: yes");
    }
    public function test_submitted_department_is_ignored(): void
    {
        // The form no longer asks for a department -- it comes from the
        // reporter's account -- so a forged one must simply be discarded
        // rather than trusted or bounced back as a validation error.
        $student=$this->u('student@my.cspc.edu.ph');
        $this->actingAs($student)->post("/concerns",[
            'category'=>'Academic','department'=>'Hogwarts',
            'description'=>'a concern carrying a forged department field',
        ]);
        $c=Concern::where('description','a concern carrying a forged department field')->firstOrFail();
        $this->line("[validation] forged department stored as '{$c->department}' (want '{$student->department}')");
        $this->assertSame($student->department,$c->department,'Department must come from the account');
    }

    // ========== D. Auth / guests ==========
    public function test_guest_blocked_from_concerns(): void
    {
        $r=$this->get('/concerns');
        $this->line("[auth] guest -> /concerns status ".$r->getStatusCode()." (want redirect 302)");
        $r->assertRedirect();
    }
    public function test_guest_blocked_from_dashboard(): void
    {
        $this->get('/dashboard')->assertRedirect();
        $this->line("[auth] guest -> /dashboard redirected: yes");
    }
    public function test_there_is_no_password_endpoint_to_brute_force(): void
    {
        // This used to assert that /login throttled after 5 bad passwords.
        // CSPC Mail is now the only way in, so the password endpoint is gone
        // entirely -- a stronger guarantee than rate limiting it, because
        // there is no credential to guess at any rate. Google owns the
        // brute-force defence for the account itself.
        $r = $this->post('/login', ['email'=>'admin@cspc.edu.ph','password'=>'wrongpass']);
        $this->line("[security] POST /login status: ".$r->getStatusCode()." (405 = no such endpoint)");
        $this->assertEquals(405, $r->getStatusCode(), 'Password login must not exist');
        $this->assertGuest();
    }

    // ========== E. XSS / injection storage ==========
    public function test_description_xss_is_escaped_on_render(): void
    {
        $payload='<script>alert(1)</script>';
        $c=$this->mk(['description'=>$payload,'assigned_to'=>$this->u('staff@cspc.edu.ph')->id]);
        $r=$this->actingAs($this->u('staff@cspc.edu.ph'))->get("/concerns/{$c->id}");
        $raw=str_contains($r->getContent(),'<script>alert(1)</script>');
        $escaped=str_contains($r->getContent(),'&lt;script&gt;');
        $this->line("[xss] raw script present: ".($raw?'YES (VULN)':'no').", escaped: ".($escaped?'yes':'no'));
        $this->assertFalse($raw,'Description must be HTML-escaped');
    }

    // ========== F. Mass assignment ==========
    public function test_student_cannot_mass_assign_user_id_on_create(): void
    {
        // student tries to submit a concern AS another user
        $victim=$this->u('student2@my.cspc.edu.ph');
        $this->actingAs($this->u('student@my.cspc.edu.ph'))->post("/concerns",[
            'category'=>'Academic','department'=>'College of Computer Studies',
            'description'=>'forged submission pretending to be someone else',
            'user_id'=>$victim->id, // attempt to spoof owner
        ]);
        $c=Concern::where('description','forged submission pretending to be someone else')->first();
        $this->line("[mass-assign] concern owner id=".($c?$c->user_id:'n/a')." (want ".$this->u('student@my.cspc.edu.ph')->id.", not {$victim->id})");
        $this->assertNotNull($c);
        $this->assertEquals($this->u('student@my.cspc.edu.ph')->id,$c->user_id,'Owner must be the authenticated user, not spoofed');
    }
}