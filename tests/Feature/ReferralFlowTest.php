<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Concern;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;

class ReferralFlowTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void { parent::setUp(); $this->seed([RoleSeeder::class, UserSeeder::class]); }
    private function u(string $e): User { return User::where('email',$e)->firstOrFail(); }

    public function test_referral_transfers_ownership_and_counselor_can_resolve(): void
    {
        $staff = $this->u('staff@cspc.edu');
        $counselor = $this->u('counselor@cspc.edu');

        // staff has an academic concern assigned
        $c = Concern::create([
            'user_id'=>$this->u('student@cspc.edu')->id,'category'=>'Academic',
            'department'=>'College of Computer Studies','description'=>'x','urgency'=>'Low',
            'status'=>'submitted','is_anonymous'=>false,'assigned_to'=>$staff->id,
        ]);

        // staff refers to Guidance Counselor
        $this->actingAs($staff)->patch("/concerns/{$c->id}", [
            'status'=>'referred','referred_to'=>'Guidance Counselor','urgency'=>'Low',
        ]);
        $c->refresh();
        fwrite(STDERR, "\n  [transfer] assigned_to now = ".$c->assigned_to." (counselor id=".$counselor->id."), referred_to=".$c->referred_to."\n");
        $this->assertEquals($counselor->id, $c->assigned_to, 'Ownership should move to the counselor');
        $this->assertEquals('Guidance Counselor', $c->referred_to);

        // counselor can now resolve it
        $resp = $this->actingAs($counselor)->patch("/concerns/{$c->id}", [
            'status'=>'resolved','urgency'=>'Low','resolution_notes'=>'handled',
        ]);
        $c->refresh();
        fwrite(STDERR, "  [resolve] counselor set status=".$c->status." (resp ".$resp->getStatusCode().")\n");
        $this->assertEquals('resolved', $c->status, 'Counselor should be able to resolve referred concern');
    }

    public function test_show_page_displays_referral_destination(): void
    {
        $staff = $this->u('staff@cspc.edu');
        $c = Concern::create([
            'user_id'=>$this->u('student@cspc.edu')->id,'category'=>'Academic',
            'department'=>'College of Computer Studies','description'=>'x','urgency'=>'Low',
            'status'=>'referred','referred_to'=>'Guidance Counselor','is_anonymous'=>false,
            'assigned_to'=>$this->u('counselor@cspc.edu')->id,
        ]);
        $this->actingAs($this->u('counselor@cspc.edu'))->get("/concerns/{$c->id}")
            ->assertOk()
            ->assertSee('Guidance Counselor');
        fwrite(STDERR, "  [display] show page shows 'Referred To: Guidance Counselor': YES\n");
    }
}