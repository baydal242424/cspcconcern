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
 * An office head handing a routine job to their own staff.
 *
 * This is the case a guard nearly blocked. Referring a concern to the office
 * that already holds it is normally refused as a no-op -- it would clutter the
 * timeline with hand-offs that change nothing. But "General Services -> General
 * Services" is a real hand-off when a NAMED person is chosen: the unit head
 * keeping the serious jobs and passing the routine ones to a maintenance
 * staffer is delegation, not a no-op.
 */
class DelegateWithinOfficeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, UserSeeder::class]);
    }

    private function gsuStaff(string $email, string $name): User
    {
        return User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('not-used'),
            'role_id' => Role::where('name', 'General Services')->firstOrFail()->id,
            'department' => 'General Services Unit',
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);
    }

    private function facilitiesConcern(User $head): Concern
    {
        return Concern::create([
            'user_id' => User::where('email', 'student@my.cspc.edu.ph')->firstOrFail()->id,
            'category' => 'Facilities / Equipment',
            'department' => 'College of Computer Studies',
            'description' => 'The tap in the second floor comfort room will not close.',
            'status' => 'in_progress',
            'is_anonymous' => false,
            'assigned_to' => $head->id,
        ]);
    }

    /** The head can hand a routine job to a named colleague in the same office. */
    public function test_head_can_delegate_to_their_own_staff(): void
    {
        $head = User::where('email', 'gsu@cspc.edu.ph')->firstOrFail();
        $staffer = $this->gsuStaff('gsu.maintenance@cspc.edu.ph', 'Maintenance Staffer');
        $concern = $this->facilitiesConcern($head);

        $this->actingAs($head)->patch("/concerns/{$concern->id}", [
            'status' => 'referred',
            'referred_to' => 'General Services',
            'referred_to_user_id' => $staffer->id,
            'urgency' => 'Low',
        ])->assertRedirect();

        $concern->refresh();
        $this->assertSame($staffer->id, $concern->assigned_to);
        $this->assertSame('referred', $concern->status);

        fwrite(STDERR, "  [delegate] head -> own staff, now assigned to id={$concern->assigned_to}\n");
    }

    /** The staffer can then finish it themselves. */
    public function test_the_staffer_can_resolve_what_was_delegated(): void
    {
        $head = User::where('email', 'gsu@cspc.edu.ph')->firstOrFail();
        $staffer = $this->gsuStaff('gsu.maintenance@cspc.edu.ph', 'Maintenance Staffer');
        $concern = $this->facilitiesConcern($head);
        $concern->forceFill([
            'assigned_to' => $staffer->id,
            'status' => 'referred',
            'referred_to' => 'General Services',
        ])->save();

        $this->actingAs($staffer)->patch("/concerns/{$concern->id}", [
            'status' => 'resolved',
            'urgency' => 'Low',
            'resolution_notes' => 'Washer replaced.',
        ])->assertRedirect();

        $this->assertSame('resolved', $concern->refresh()->status);

        fwrite(STDERR, "  [delegate] staffer resolved it: YES\n");
    }

    /** The picker offers the colleague, and not the head doing the referring. */
    public function test_picker_offers_colleagues_but_not_yourself(): void
    {
        $head = User::where('email', 'gsu@cspc.edu.ph')->firstOrFail();
        $staffer = $this->gsuStaff('gsu.maintenance@cspc.edu.ph', 'Maintenance Staffer');
        $concern = $this->facilitiesConcern($head);

        $resp = $this->actingAs($head)->get("/concerns/{$concern->id}");
        $resp->assertOk();
        $resp->assertSee('value="'.$staffer->id.'" data-role="General Services"', false);
        $resp->assertDontSee('value="'.$head->id.'" data-role=', false);

        fwrite(STDERR, "  [picker] colleague offered, self excluded: YES\n");
    }

    /**
     * Without naming somebody, office-to-same-office is still refused. That
     * guard is what stops a timeline filling with hand-offs that moved nothing.
     */
    public function test_referring_to_your_own_office_without_naming_anyone_is_refused(): void
    {
        $head = User::where('email', 'gsu@cspc.edu.ph')->firstOrFail();
        $this->gsuStaff('gsu.maintenance@cspc.edu.ph', 'Maintenance Staffer');
        $concern = $this->facilitiesConcern($head);

        $this->actingAs($head)->patch("/concerns/{$concern->id}", [
            'status' => 'referred',
            'referred_to' => 'General Services',
            'urgency' => 'Low',
        ])->assertSessionHasErrors('referred_to');

        $this->assertSame('in_progress', $concern->refresh()->status);

        fwrite(STDERR, "  [guard] same-office referral with nobody named: refused\n");
    }
}
