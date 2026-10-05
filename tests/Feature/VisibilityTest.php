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

    /**
     * The administrative office. UserSeeder does not create one -- Staff Admin
     * was split out of Admin and starts empty on purpose, because who
     * administers what is a decision for a person.
     */
    private function staffAdmin(): User
    {
        return User::factory()->create([
            'name' => 'Office Administrator',
            'role_id' => \App\Models\Role::where('name', 'Staff Admin')->value('id'),
            'department' => 'Student Registration and Records',
            'status' => 'approved',
        ]);
    }

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
        // Against an Admin: the dashboard is Admin-only, so asserting privacy
        // against a staff account only proved that a 403 contains no names.
        $admin = $this->u('admin@cspc.edu.ph');
        // Assigned to the admin. Since Admin was split, a System Admin has no
        // standing view of any category -- only what was handed to them -- so
        // anything else would leave the Recent list empty and pass on nothing.
        $this->makeConcern(['category'=>'Administrative','is_anonymous'=>true,'assigned_to'=>$admin->id]);
        $resp = $this->actingAs($admin)->get('/dashboard');
        $resp->assertOk();
        $leak = str_contains($resp->getContent(), 'John Student');
        fwrite(STDERR, "  [privacy] dashboard shows anon name: ".($leak?'YES (LEAK)':'NO')."\n");
        $this->assertFalse($leak, 'Anonymous name leaked on dashboard');
        $resp->assertSee('Anonymous');
    }

    /** LEAST-PRIVILEGE: admin does NOT see a confidential counselor (Mental Health) case */
    public function test_admin_cannot_see_mental_health_case(): void
    {
        $mh = $this->makeConcern(['category'=>'Mental Health']);
        $admin = $this->u('admin@cspc.edu.ph');
        $visible = Concern::whereKey($mh->id)->visibleTo($admin)->exists();
        fwrite(STDERR, "  [least-priv] admin sees Mental Health case: ".($visible?'YES (BAD)':'NO')."\n");
        $this->assertFalse($visible, 'Admin should not see confidential counselor cases');
        // and the show page should 403
        $this->actingAs($admin)->get("/concerns/{$mh->id}")->assertForbidden();
    }

    /** The System Admin DOES see reports that the system is broken */
    public function test_the_system_admin_sees_reports_about_the_system(): void
    {
        // The category routes to them, so they have to be able to read it -- a
        // concern assigned to somebody who cannot open it is worse than an
        // unassigned one, because the queue looks handled.
        //
        // The window moved here with the category. The Administrative Office
        // held it while the category meant enrolment, records and fees; it now
        // means "this website is broken", which that office cannot act on.
        $a = $this->makeConcern(['category'=>'Administrative']);

        $this->assertTrue(
            Concern::whereKey($a->id)->visibleTo($this->u('admin@cspc.edu.ph'))->exists(),
            'the people who run the system must be able to read what routes to them'
        );

        // And the Administrative Office does not. It held this window while
        // the category meant enrolment, records and fees; a broken page is not
        // theirs to fix, and a standing view of it would be a window into
        // students' reports they have no reason to read.
        $this->assertFalse(
            Concern::whereKey($a->id)->visibleTo($this->staffAdmin())->exists(),
            'the Administrative Office has no standing view of system reports'
        );

        fwrite(STDERR, "  [routing] the System Admin reads system reports, the office does not: YES\n");
    }

    /**
     * The widening above is limited to that one category. Everything an Admin
     * has no business reading by default still needs an explicit referral.
     */
    public function test_admin_still_sees_no_other_category(): void
    {
        $admin = $this->u('admin@cspc.edu.ph');

        foreach (['Mental Health', 'Bullying', 'Academic', 'Facilities'] as $category) {
            $c = $this->makeConcern(['category' => $category]);
            $this->assertFalse(
                Concern::whereKey($c->id)->visibleTo($admin)->exists(),
                "An Admin must not see an untouched {$category} concern"
            );
        }

        fwrite(STDERR, "  [least-priv] admin sees no other category: confirmed\n");
    }

    /** Counselor sees Mental Health automatically */
    public function test_counselor_sees_mental_health(): void
    {
        $mh = $this->makeConcern(['category'=>'Mental Health']);
        $c = $this->u('counselor@cspc.edu.ph');
        $this->assertTrue(Concern::whereKey($mh->id)->visibleTo($c)->exists());
        fwrite(STDERR, "  [counselor] sees Mental Health: YES\n");
    }

    /**
     * REFERRAL: staff refers an Academic case on -> the receiver can now see it
     *
     * This used to refer to the Administrative Office, which is no longer
     * offered as a destination: with the Administrative category now meaning
     * "this website is broken" and going to the System Admin, that office owns
     * no queue, and the two read as the same thing in the dropdown. The
     * guarantee is unchanged -- a referral is what opens a case to an office
     * that had no standing view of it.
     */
    public function test_a_referral_grants_the_receiver_visibility(): void
    {
        $staff = $this->u('staff@cspc.edu.ph');
        $admin = $this->u('admin@cspc.edu.ph');
        $c = $this->makeConcern(['category'=>'Academic','assigned_to'=>$staff->id]);

        // before referral, they cannot see this Academic case
        $this->assertFalse(Concern::whereKey($c->id)->visibleTo($admin)->exists());

        // staff refers it to Admin via the update endpoint
        $this->actingAs($staff)->patch("/concerns/{$c->id}", [
            'investigation_notes' => 'Looked into this and spoke with the people involved.',
            'resolution_notes' => 'Recorded what is being done about it.',
            'status' => 'referred',
            'referred_to' => 'System Admin',
        ]);
        $c->refresh();
        fwrite(STDERR, "  [referral] status={$c->status} referred_to=".var_export($c->referred_to,true)."\n");

        $this->assertEquals('System Admin', $c->referred_to);
        $this->assertTrue(Concern::whereKey($c->id)->visibleTo($admin)->exists(),
            'the office a case was referred to should be able to read it');
    }

    /** REFERRAL VALIDATION: referring with no destination is rejected */
    public function test_referral_requires_destination(): void
    {
        $staff = $this->u('staff@cspc.edu.ph');
        $c = $this->makeConcern(['category'=>'Academic','assigned_to'=>$staff->id]);
        $resp = $this->actingAs($staff)->patch("/concerns/{$c->id}", [
            'investigation_notes' => 'Looked into this and spoke with the people involved.',
            'resolution_notes' => 'Recorded what is being done about it.',
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