<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Concern;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;

class ReferralLifecycleTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void { parent::setUp(); $this->seed([RoleSeeder::class, UserSeeder::class]); }
    private function u(string $e): User { return User::where('email',$e)->firstOrFail(); }

    private function academicAssignedToStaff(): Concern
    {
        return Concern::create([
            'user_id'=>$this->u('student@my.cspc.edu.ph')->id,'category'=>'Academic',
            'department'=>'College of Computer Studies','description'=>'x','urgency'=>null,
            'status'=>'submitted','is_anonymous'=>false,'assigned_to'=>$this->u('staff@cspc.edu.ph')->id,
        ]);
    }

    /** ISSUE 2: staff can refer to counselor without a false 'Unauthorized' */
    public function test_staff_can_refer_without_unauthorized(): void
    {
        $c = $this->academicAssignedToStaff();
        $resp = $this->actingAs($this->u('staff@cspc.edu.ph'))->patch("/concerns/{$c->id}", [
            'status'=>'referred','referred_to'=>'Guidance Counselor','urgency'=>'Low',
        ]);
        fwrite(STDERR, "\n  [refer] staff refer response: ".$resp->getStatusCode()." (expect 302, not 403)\n");
        $this->assertNotEquals(403, $resp->getStatusCode());
        $c->refresh();
        $this->assertEquals('referred', $c->status);
        $this->assertEquals($this->u('counselor@cspc.edu.ph')->id, $c->assigned_to);
    }

    /** Counselor resolves; ISSUE 4a: resolved concern is locked from further edits */
    public function test_resolved_concern_is_locked(): void
    {
        $c = $this->academicAssignedToStaff();
        // refer to counselor
        $this->actingAs($this->u('staff@cspc.edu.ph'))->patch("/concerns/{$c->id}", [
            'status'=>'referred','referred_to'=>'Guidance Counselor','urgency'=>'Low',
        ]);
        // counselor resolves
        $this->actingAs($this->u('counselor@cspc.edu.ph'))->patch("/concerns/{$c->id}", [
            'status'=>'resolved','urgency'=>'Low','resolution_notes'=>'done',
        ]);
        $c->refresh();
        $this->assertEquals('resolved', $c->status);
        // any further edit attempt must be blocked
        $resp = $this->actingAs($this->u('counselor@cspc.edu.ph'))->patch("/concerns/{$c->id}", [
            'status'=>'in_progress','urgency'=>'High',
        ]);
        fwrite(STDERR, "  [locked] edit-after-resolve status: ".$resp->getStatusCode()." (expect 302 redirect + error)\n");
        $resp->assertRedirect();
        $this->assertStringContainsString('already resolved', session('error'));
        $this->assertEquals('resolved', $c->refresh()->status, 'Resolved concern must not change');
    }

    /** ISSUE 4b: after resolution, the referred Academic concern leaves counselor's view */
    public function test_counselor_keeps_handled_concern_as_history(): void
    {
        $c = $this->academicAssignedToStaff();
        $this->actingAs($this->u('staff@cspc.edu.ph'))->patch("/concerns/{$c->id}", [
            'status'=>'referred','referred_to'=>'Guidance Counselor','urgency'=>'Low',
        ]);
        $counselor = $this->u('counselor@cspc.edu.ph');
        // while open: visible
        $openVisible = Concern::whereKey($c->id)->visibleTo($counselor)->exists();
        // resolve
        $this->actingAs($counselor)->patch("/concerns/{$c->id}", [
            'status'=>'resolved','urgency'=>'Low','resolution_notes'=>'done',
        ]);
        $resolvedVisible = Concern::whereKey($c->id)->visibleTo($counselor)->exists();
        fwrite(STDERR, "  [scope] counselor sees while open: ".($openVisible?'YES':'NO').", after resolve (handled by her -> history): ".($resolvedVisible?'YES':'NO')."\n");
        $this->assertTrue($openVisible, 'Counselor should see the referred concern while open');
        // Policy: a concern she personally handled stays as history/reference.
        $this->assertTrue($resolvedVisible, 'Counselor keeps concerns she handled, even after resolve');
        // and she can still OPEN it (read-only, since resolved)
        $this->actingAs($counselor)->get("/concerns/{$c->id}")->assertOk();
    }

    /** Counselor's OWN domain (Mental Health) stays visible even after resolved */
    public function test_counselor_keeps_own_domain_after_resolve(): void
    {
        $c = Concern::create([
            'user_id'=>$this->u('student@my.cspc.edu.ph')->id,'category'=>'Mental Health / Personal',
            'department'=>'Guidance Office','description'=>'x','urgency'=>'Low',
            'status'=>'resolved','is_anonymous'=>false,'assigned_to'=>$this->u('counselor@cspc.edu.ph')->id,
        ]);
        $visible = Concern::whereKey($c->id)->visibleTo($this->u('counselor@cspc.edu.ph'))->exists();
        fwrite(STDERR, "  [domain] counselor sees own resolved Mental Health case: ".($visible?'YES':'NO')."\n");
        $this->assertTrue($visible, 'Counselor should always see their own-domain cases');
    }
}