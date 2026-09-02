<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Concern;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;

class QaAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, UserSeeder::class]);
    }

    private function u(string $email): User { return User::where('email',$email)->firstOrFail(); }

    private function submit(array $overrides = []): Concern
    {
        $this->actingAs($this->u('student@my.cspc.edu.ph'))->post('/concerns', array_merge([
            // description must satisfy the min:20 rule or the POST silently 302s
            // back with errors and no concern is created
            'category'=>'Academic','department'=>'College of Computer Studies',
            'description'=>'qa test concern with a long enough description',
        ], $overrides));
        return Concern::orderByDesc('id')->first();
    }

    public function test_all_departments_are_accepted(): void
    {
        $departments = [
            'College of Engineering and Architecture','College of Computer Studies',
            'College of Health Sciences','College of Tourism, Hospitality and Business Management',
            'College of Technological and Development Education','Guidance Office','SASO',
        ];
        foreach ($departments as $d) {
            $resp = $this->actingAs($this->u('student@my.cspc.edu.ph'))->post('/concerns', [
                'category'=>'Administrative','department'=>$d,
                'description'=>'department acceptance test with a valid description',
            ]);
            $this->assertNull($resp->getSession()->get('errors'), "Department '$d' was rejected.");
        }
    }

    /** Every category routes to the correct role, no matter the department. */
    public function test_category_routing_matrix(): void
    {
        // Each category goes to the office that can actually act on it.
        // Facilities goes to General Services, the office that can actually
        // act on it. Administrative comes back to Admin after the Registrar
        // role was removed: they triage and refer on rather than resolving.
        $expected = [
            'Academic'                 => 'Instructor',
            'Mental Health / Personal' => 'Guidance Counselor',
            'Bullying / Harassment'    => 'Guidance Counselor',
            'Administrative'           => 'Admin',
            'Facilities / Equipment'   => 'General Services',
            'Physical'                 => 'Instructor',
            'Safety'                   => 'Instructor',
            'Others'                   => 'Instructor',
        ];
        // submit each with a DELIBERATELY mismatched department to prove dept can't hijack routing
        foreach ($expected as $cat => $role) {
            $c = $this->submit(['category'=>$cat, 'department'=>'SASO']);
            $actual = $c->assigned_to ? (User::find($c->assigned_to)->role->name ?? '?') : 'UNASSIGNED';
            fwrite(STDERR, "  [route] ".str_pad($cat,26)." (dept=SASO) -> ".str_pad($actual,18)." expected ".$role."\n");
            $this->assertEquals($role, $actual, "$cat routed to $actual, expected $role");
        }
    }

    /** Counselor must SEE a concern routed to them (check the View link, which the index renders). */
    public function test_counselor_sees_assigned_concern(): void
    {
        $c = $this->submit(['category'=>'Mental Health / Personal']);
        $resp = $this->actingAs($this->u('counselor@cspc.edu.ph'))->get('/concerns');
        $resp->assertOk();
        // index renders a "View" link to /concerns/{id}
        $resp->assertSee("/concerns/{$c->id}", false);
        fwrite(STDERR, "  [visibility] counselor sees concern #{$c->id}: YES\n");
    }

    /** Staff must SEE a concern routed to them. */
    public function test_staff_sees_assigned_concern(): void
    {
        $c = $this->submit(['category'=>'Safety']);
        $this->actingAs($this->u('staff@cspc.edu.ph'))->get('/concerns')
             ->assertOk()->assertSee("/concerns/{$c->id}", false);
        fwrite(STDERR, "  [visibility] staff sees Physical/Safety concern #{$c->id}: YES\n");
    }

    /**
     * A student cannot set their own urgency. They used to be left at null and
     * triaged by hand; submissions are now auto-graded from the wording, so the
     * assertion is that what they asked for is not what they get -- not that
     * the field stays empty.
     */
    public function test_student_urgency_ignored(): void
    {
        $urgency = $this->submit(['urgency' => 'Critical'])->urgency;

        $this->assertNotSame('Critical', $urgency, 'A student must not be able to set their own urgency');
        fwrite(STDERR, "  [triage] student asked for Critical, system assigned: ".var_export($urgency, true)."\n");
    }

    public function test_staff_can_set_urgency(): void
    {
        $c = $this->submit(['category'=>'Safety']);
        $this->actingAs($this->u('staff@cspc.edu.ph'))->patch("/concerns/{$c->id}", [
            'status'=>'in_progress','urgency'=>'High',
        ]);
        $this->assertEquals('High', $c->refresh()->urgency);
    }

    public function test_student_cannot_view_others_concern(): void
    {
        $c = $this->submit();
        $this->actingAs($this->u('student2@my.cspc.edu.ph'))->get("/concerns/{$c->id}")->assertForbidden();
    }

    public function test_guest_redirected_to_login(): void
    {
        $this->get('/concerns')->assertRedirect('/login');
    }

    /**
     * Auto-grading means a submitted concern arrives with an urgency, so this
     * no longer proves anything about the form. It still matters for a concern
     * that has none -- seeded rows, and anything predating auto-triage -- which
     * must read "Pending triage" rather than an empty cell.
     */
    public function test_show_renders_pending_triage(): void
    {
        $c = $this->submit();
        $c->forceFill(['urgency' => null])->save();
        $this->actingAs($this->u('student@my.cspc.edu.ph'))->get("/concerns/{$c->id}")
             ->assertOk()->assertSee('Pending triage');
    }
}