<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Concern;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;

class VisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, UserSeeder::class]);
    }
    private function u(string $e): User { return User::where('email',$e)->firstOrFail(); }

    private function makeConcern(array $o = []): Concern
    {
        return Concern::create(array_merge([
            'user_id' => $this->u('student@my.cspc.edu.ph')->id,
            'category' => 'Academic',
            'department' => 'College of Computer Studies',
            'description' => 'x',
            'urgency' => null,
            'status' => 'submitted',
            'is_anonymous' => false,
        ], $o));
    }

    /** PRIVACY: anonymous submitter name hidden on dashboard */
    public function test_dashboard_hides_anonymous_name(): void
    {
        $staff = $this->u('staff@cspc.edu.ph');
        $this->makeConcern(['category'=>'Academic','is_anonymous'=>true,'assigned_to'=>$staff->id]);
        $resp = $this->actingAs($staff)->get('/dashboard');
        $resp->assertOk();
        $leak = str_contains($resp->getContent(), 'John Student');
        fwrite(STDERR, "  [privacy] dashboard shows anon name: ".($leak?'YES (LEAK)':'NO')."\n");
        $this->assertFalse($leak, 'Anonymous name leaked on dashboard');
        $resp->assertSee('Anonymous');
    }

    /** LEAST-PRIVILEGE: admin does NOT see a confidential counselor (Mental Health) case */
    public function test_admin_cannot_see_mental_health_case(): void
    {
        $mh = $this->makeConcern(['category'=>'Mental Health / Personal']);
        $admin = $this->u('admin@cspc.edu.ph');
        $visible = Concern::whereKey($mh->id)->visibleTo($admin)->exists();
        fwrite(STDERR, "  [least-priv] admin sees Mental Health case: ".($visible?'YES (BAD)':'NO')."\n");
        $this->assertFalse($visible, 'Admin should not see confidential counselor cases');
        // and the show page should 403
        $this->actingAs($admin)->get("/concerns/{$mh->id}")->assertForbidden();
    }

    /** Admin DOES see Administrative concerns */
    public function test_admin_does_not_see_administrative_concerns(): void
    {
        // Administrative moved to the Registrar, and Facilities to General
        // Services -- the offices that can act on them. 'Admin' here means the
        // people who administer the SYSTEM (accounts, roles, bans), so they
        // now have NO category of their own: a standing window into students'
        // concerns is exactly the privilege least-privilege withholds. They
        // still see anything explicitly referred or assigned to them.
        $a = $this->makeConcern(['category'=>'Administrative']);
        $admin = $this->u('admin@cspc.edu.ph');
        $this->assertFalse(Concern::whereKey($a->id)->visibleTo($admin)->exists());
        fwrite(STDERR, "  [least-priv] sysadmin sees Administrative case: NO\n");
    }

    public function test_the_registrar_sees_administrative_concerns(): void
    {
        $a = $this->makeConcern(['category'=>'Administrative']);
        $registrar = \App\Models\User::factory()->create([
            'role_id' => \App\Models\Role::where('name', 'Registrar')->value('id'),
            'department' => 'Student Registration and Records',
        ]);
        $this->assertTrue(Concern::whereKey($a->id)->visibleTo($registrar)->exists());
        fwrite(STDERR, "  [routing] registrar sees Administrative case: YES\n");
    }

    /** Counselor sees Mental Health automatically */
    public function test_counselor_sees_mental_health(): void
    {
        $mh = $this->makeConcern(['category'=>'Mental Health / Personal']);
        $c = $this->u('counselor@cspc.edu.ph');
        $this->assertTrue(Concern::whereKey($mh->id)->visibleTo($c)->exists());
        fwrite(STDERR, "  [counselor] sees Mental Health: YES\n");
    }

    /** REFERRAL: staff refers an Academic case to Admin -> admin can now see it */
    public function test_referral_to_admin_grants_visibility(): void
    {
        $staff = $this->u('staff@cspc.edu.ph');
        $admin = $this->u('admin@cspc.edu.ph');
        $c = $this->makeConcern(['category'=>'Academic','assigned_to'=>$staff->id]);

        // before referral, admin cannot see this Academic case
        $this->assertFalse(Concern::whereKey($c->id)->visibleTo($admin)->exists());

        // staff refers it to Admin via the update endpoint
        $this->actingAs($staff)->patch("/concerns/{$c->id}", [
            'status' => 'referred',
            'referred_to' => 'Admin',
        ]);
        $c->refresh();
        fwrite(STDERR, "  [referral] status={$c->status} referred_to=".var_export($c->referred_to,true)."\n");

        $this->assertEquals('Admin', $c->referred_to);
        $this->assertTrue(Concern::whereKey($c->id)->visibleTo($admin)->exists(),
            'Admin should see a case referred to Admin');
    }

    /** REFERRAL VALIDATION: referring with no destination is rejected */
    public function test_referral_requires_destination(): void
    {
        $staff = $this->u('staff@cspc.edu.ph');
        $c = $this->makeConcern(['category'=>'Academic','assigned_to'=>$staff->id]);
        $resp = $this->actingAs($staff)->patch("/concerns/{$c->id}", [
            'status' => 'referred',
            'referred_to' => '',
        ]);
        $resp->assertSessionHasErrors('referred_to');
        fwrite(STDERR, "  [referral] empty destination rejected: YES\n");
    }

    /** Student still only sees their own */
    public function test_student_sees_only_own(): void
    {
        $mine = $this->makeConcern();
        $other = $this->makeConcern(['user_id'=>$this->u('student2@my.cspc.edu.ph')->id]);
        $me = $this->u('student@my.cspc.edu.ph');
        $this->assertTrue(Concern::whereKey($mine->id)->visibleTo($me)->exists());
        $this->assertFalse(Concern::whereKey($other->id)->visibleTo($me)->exists());
        fwrite(STDERR, "  [student] sees only own concerns: YES\n");
    }
}