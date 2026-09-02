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
 * A concern about the Administration.
 *
 * Every other office has somebody above it. The Admin did not: a complaint
 * about a system administrator was excluded from them by the conflict-of-
 * interest rule and then fell to a college dean, who has no standing over
 * them -- or, with no eligible dean, to nobody at all. The VPAA is that
 * missing rung.
 */
class VpaaEscalationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, UserSeeder::class]);
    }

    private function vpaa(): User
    {
        return User::create([
            'name' => 'Dr. Jocelyn O. Jintalan',
            'email' => 'vpaa.test@cspc.edu.ph',
            'password' => Hash::make('not-used'),
            'role_id' => Role::where('name', 'Vice President for Academic Affairs')->firstOrFail()->id,
            'department' => 'Office of the Vice President for Academic Affairs',
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);
    }

    private function student(): User
    {
        return User::where('email', 'student@my.cspc.edu.ph')->firstOrFail();
    }

    /** A complaint about the Admin goes up to the VPAA, not sideways to a dean. */
    public function test_a_concern_about_the_admin_escalates_to_the_vpaa(): void
    {
        $vpaa = $this->vpaa();
        $admin = User::where('email', 'admin@cspc.edu.ph')->firstOrFail();

        $this->actingAs($this->student())->post('/concerns', [
            'category' => 'Administrative',
            'description' => 'A complaint about how the system administrator handled my account.',
            'about_staff_id' => $admin->id,
        ]);

        $concern = Concern::latest('id')->firstOrFail();

        $this->assertSame($vpaa->id, $concern->assigned_to, 'it should reach the VPAA');
        $this->assertNotSame($admin->id, $concern->assigned_to);

        fwrite(STDERR, "  [escalate] concern about the Admin -> VPAA (id={$concern->assigned_to})\n");
    }

    /** With no VPAA it still lands somewhere rather than nowhere. */
    public function test_without_a_vpaa_it_still_reaches_somebody(): void
    {
        $admin = User::where('email', 'admin@cspc.edu.ph')->firstOrFail();

        $this->actingAs($this->student())->post('/concerns', [
            'category' => 'Administrative',
            'description' => 'A complaint about the administrator, filed before a VPAA exists.',
            'about_staff_id' => $admin->id,
        ]);

        $concern = Concern::latest('id')->firstOrFail();

        $this->assertNotNull($concern->assigned_to, 'it must not be left unassigned');
        $this->assertNotSame($admin->id, $concern->assigned_to);

        fwrite(STDERR, "  [fallback] no VPAA yet -> still assigned (id={$concern->assigned_to})\n");
    }

    /** An ordinary Administrative concern still goes to the Admin. */
    public function test_an_ordinary_administrative_concern_still_goes_to_admin(): void
    {
        $this->vpaa();

        $this->actingAs($this->student())->post('/concerns', [
            'category' => 'Administrative',
            'description' => 'I need a copy of my registration record for a scholarship.',
        ]);

        $concern = Concern::latest('id')->firstOrFail();

        $this->assertSame('Admin', optional(optional($concern->assignedUser)->role)->name);

        fwrite(STDERR, "  [normal] ordinary Administrative concern still goes to Admin\n");
    }

    /** She oversees; she does not get a standing view of every concern. */
    public function test_the_vpaa_sees_only_what_reaches_her(): void
    {
        $vpaa = $this->vpaa();

        $hers = Concern::create([
            'user_id' => $this->student()->id,
            'category' => 'Administrative',
            'department' => 'College of Computer Studies',
            'description' => 'Escalated to the VPAA.',
            'status' => 'submitted',
            'is_anonymous' => false,
            'assigned_to' => $vpaa->id,
        ]);

        $notHers = Concern::create([
            'user_id' => $this->student()->id,
            'category' => 'Mental Health / Personal',
            'department' => 'College of Computer Studies',
            'description' => 'A counselling matter that is none of her business.',
            'status' => 'submitted',
            'is_anonymous' => false,
        ]);

        $visible = Concern::visibleTo($vpaa)->pluck('id');

        $this->assertTrue($visible->contains($hers->id));
        $this->assertFalse($visible->contains($notHers->id));

        fwrite(STDERR, "  [scope] sees what is escalated to her, nothing else\n");
    }

    /** Staff can refer to her by name. */
    public function test_she_is_offered_as_a_referral_destination(): void
    {
        $vpaa = $this->vpaa();
        $staff = User::where('email', 'staff@cspc.edu.ph')->firstOrFail();

        $concern = Concern::create([
            'user_id' => $this->student()->id,
            'category' => 'Academic',
            'department' => 'College of Computer Studies',
            'description' => 'Something needing the academic vice president.',
            'status' => 'in_progress',
            'is_anonymous' => false,
            'assigned_to' => $staff->id,
        ]);

        $resp = $this->actingAs($staff)->get("/concerns/{$concern->id}");
        $resp->assertOk();
        $resp->assertSee('Vice President for Academic Affairs', false);
        $resp->assertSee('value="'.$vpaa->id.'"', false);

        fwrite(STDERR, "  [referral] offered as a destination and by name\n");
    }
}
