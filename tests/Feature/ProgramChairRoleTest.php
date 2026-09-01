<?php

namespace Tests\Feature;

use App\Models\Concern;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The Program Chair sits between an instructor and the dean: it owns one
 * degree programme, so an academic concern an instructor cannot settle alone
 * has somewhere to go that is not yet the whole college.
 *
 * A new role has to be registered in five places to actually work. These tests
 * pin all five, because missing one fails quietly -- the role exists, but
 * nobody can be referred to it, or it can see nothing, or a concern filed
 * about a chair never gets the conflict-of-interest flag.
 */
class ProgramChairRoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, UserSeeder::class]);
    }

    private function chair(string $college = 'College of Computer Studies'): User
    {
        return User::create([
            'name' => 'Programme Chair',
            'email' => 'chair.test@cspc.edu.ph',
            'password' => Hash::make('password'),
            'role_id' => Role::where('name', 'Program Chair')->firstOrFail()->id,
            'department' => $college,
            // UserSeeder stamps these on every account it creates; a chair made
            // directly in a test has to match, or the auth middleware treats it
            // as an unapproved account and bounces every request to /login.
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);
    }

    private function makeConcern(array $overrides = []): Concern
    {
        return Concern::create(array_merge([
            'user_id' => User::where('email', 'student@my.cspc.edu.ph')->firstOrFail()->id,
            'category' => 'Academic',
            'department' => 'College of Computer Studies',
            'description' => 'The grading for our subject was never released.',
            'urgency' => null,
            'status' => 'submitted',
            'is_anonymous' => false,
        ], $overrides));
    }

    /** The role is seeded and counted as staff. */
    public function test_role_exists_and_is_an_employee_role(): void
    {
        $this->assertNotNull(Role::where('name', 'Program Chair')->first());
        $this->assertContains('Program Chair', User::EMPLOYEE_ROLES);
        $this->assertTrue($this->chair()->isEmployee());

        fwrite(STDERR, "  [role] seeded and treated as staff: YES\n");
    }

    /** A chair works the same academic queue as instructors and deans. */
    public function test_chair_sees_the_shared_academic_queue(): void
    {
        $chair = $this->chair();
        $concern = $this->makeConcern();

        $this->assertTrue(
            Concern::whereKey($concern->id)->visibleTo($chair)->exists(),
            'A submitted Academic concern should be visible to a Program Chair'
        );

        // ...but not somebody else's mental health case.
        $confidential = $this->makeConcern(['category' => 'Mental Health / Personal']);
        $this->assertFalse(
            Concern::whereKey($confidential->id)->visibleTo($chair)->exists(),
            'A Program Chair must not see Mental Health concerns'
        );

        fwrite(STDERR, "  [scope] sees academic queue, not confidential cases: YES\n");
    }

    /** An instructor can escalate to the chair, and ownership moves. */
    public function test_instructor_can_refer_to_the_chair(): void
    {
        $chair = $this->chair();
        $staff = User::where('email', 'ccs.instructor@cspc.edu.ph')->firstOrFail();
        $concern = $this->makeConcern(['assigned_to' => $staff->id]);

        $this->actingAs($staff)->patch("/concerns/{$concern->id}", [
            'status' => 'referred',
            'referred_to' => 'Program Chair',
            'urgency' => 'Medium',
        ])->assertRedirect();

        $concern->refresh();
        $this->assertSame('referred', $concern->status);
        $this->assertSame('Program Chair', $concern->referred_to);
        $this->assertSame($chair->id, $concern->assigned_to);

        fwrite(STDERR, "  [refer] instructor -> chair, assigned_to={$concern->assigned_to}\n");
    }

    /** The chair can then act on it, all the way to resolved. */
    public function test_chair_can_resolve_what_was_escalated(): void
    {
        $chair = $this->chair();
        $concern = $this->makeConcern([
            'assigned_to' => $chair->id,
            'status' => 'referred',
            'referred_to' => 'Program Chair',
        ]);

        $this->actingAs($chair)->patch("/concerns/{$concern->id}", [
            'status' => 'resolved',
            'urgency' => 'Medium',
            'resolution_notes' => 'Spoke with the instructor; grades released.',
        ])->assertRedirect();

        $this->assertSame('resolved', $concern->refresh()->status);

        fwrite(STDERR, "  [act] chair resolved the escalated concern: YES\n");
    }

    /** The destination is offered in the UI, not just accepted by the server. */
    public function test_chair_is_offered_in_the_referral_dropdown(): void
    {
        $chair = $this->chair();
        $staff = User::where('email', 'ccs.instructor@cspc.edu.ph')->firstOrFail();
        $concern = $this->makeConcern(['assigned_to' => $staff->id]);

        $resp = $this->actingAs($staff)->get("/concerns/{$concern->id}");
        $resp->assertOk();
        $resp->assertSee('value="Program Chair"', false);
        // And the named person appears in the people picker for that office.
        $resp->assertSee('value="'.$chair->id.'" data-role="Program Chair"', false);

        fwrite(STDERR, "  [ui] offered as a destination and as a named person: YES\n");
    }

    /** A concern filed ABOUT a chair walls them off, like any other staff. */
    public function test_a_concern_about_a_chair_is_hidden_from_them(): void
    {
        $chair = $this->chair();
        $concern = $this->makeConcern(['about_staff_id' => $chair->id]);

        $this->assertFalse(
            Concern::whereKey($concern->id)->visibleTo($chair)->exists(),
            'The conflict-of-interest wall must cover Program Chairs too'
        );
        $this->actingAs($chair)->get("/concerns/{$concern->id}")->assertForbidden();

        fwrite(STDERR, "  [wall] chair cannot see a concern about themselves: YES\n");
    }
}
