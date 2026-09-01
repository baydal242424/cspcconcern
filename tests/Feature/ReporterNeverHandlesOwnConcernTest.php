<?php

namespace Tests\Feature;

use App\Models\Concern;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Staff file concerns as well as handle them. Nothing stopped the system
 * handing a concern back to the person who reported it: findHandler() excluded
 * the person a concern was ABOUT, but never the person who FILED it. So a dean
 * reporting a facilities problem could be assigned their own complaint the
 * moment somebody referred it to Dean -- and could then write the
 * resolution notes on it and close it.
 *
 * The conflict-of-interest wall has two sides. These tests pin the reporter's.
 */
class ReporterNeverHandlesOwnConcernTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, UserSeeder::class]);
    }

    private function u(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    /** Referring to a role the reporter holds must not return it to them. */
    public function test_referral_never_lands_back_on_the_reporter(): void
    {
        // A Dean who is also the reporter.
        $dean = $this->u('ccs@cspc.edu.ph');
        $this->assertSame('Dean', $dean->role->name);

        $concern = Concern::create([
            'user_id' => $dean->id,
            'category' => 'Academic',
            'department' => 'College of Computer Studies',
            'description' => 'Filed by a dean about a matter in their own college.',
            'status' => 'submitted',
            'is_anonymous' => false,
            'assigned_to' => $this->u('staff@cspc.edu.ph')->id,
        ]);

        $this->actingAs($this->u('staff@cspc.edu.ph'))->patch("/concerns/{$concern->id}", [
            'status' => 'referred',
            'referred_to' => 'Dean',
            'urgency' => 'Medium',
        ]);

        $concern->refresh();
        $this->assertNotSame(
            $dean->id,
            $concern->assigned_to,
            'A concern must never be assigned to the person who reported it'
        );

        fwrite(STDERR, "  [wall] reporter (dean id={$dean->id}) did not receive own concern; assigned_to={$concern->assigned_to}\n");
    }

    /** The people picker must not offer the reporter as a recipient. */
    public function test_picker_does_not_offer_the_reporter(): void
    {
        $dean = $this->u('ccs@cspc.edu.ph');
        $staff = $this->u('staff@cspc.edu.ph');

        $concern = Concern::create([
            'user_id' => $dean->id,
            'category' => 'Academic',
            'department' => 'College of Computer Studies',
            'description' => 'Filed by a dean, viewed by the assigned instructor.',
            'status' => 'submitted',
            'is_anonymous' => false,
            'assigned_to' => $staff->id,
        ]);

        $resp = $this->actingAs($staff)->get("/concerns/{$concern->id}");
        $resp->assertOk();
        $resp->assertDontSee('value="'.$dean->id.'" data-role=', false);

        fwrite(STDERR, "  [picker] reporter not offered as a named recipient: YES\n");
    }

    /** A forged post naming the reporter is refused server-side. */
    public function test_forged_referral_to_the_reporter_is_rejected(): void
    {
        $dean = $this->u('ccs@cspc.edu.ph');
        $staff = $this->u('staff@cspc.edu.ph');

        $concern = Concern::create([
            'user_id' => $dean->id,
            'category' => 'Academic',
            'department' => 'College of Computer Studies',
            'description' => 'Filed by a dean; someone tries to hand it back.',
            'status' => 'submitted',
            'is_anonymous' => false,
            'assigned_to' => $staff->id,
        ]);

        $this->actingAs($staff)->patch("/concerns/{$concern->id}", [
            'status' => 'referred',
            'referred_to' => 'Dean',
            'referred_to_user_id' => $dean->id,
            'urgency' => 'Medium',
        ])->assertSessionHasErrors('referred_to_user_id');

        $concern->refresh();
        $this->assertNotSame($dean->id, $concern->assigned_to);
        $this->assertSame('submitted', $concern->status);

        fwrite(STDERR, "  [forge] naming the reporter directly is refused: YES\n");
    }

    /** Auto-routing at submission must not assign a concern to its reporter. */
    public function test_intake_routing_never_assigns_to_the_reporter(): void
    {
        // General Services files a Facilities concern -- the category that
        // routes straight back to them, and an office with exactly one holder,
        // so excluding the reporter empties the destination role entirely.
        $gsu = $this->u('gsu@cspc.edu.ph');
        $this->assertSame('General Services', $gsu->role->name);

        $this->actingAs($gsu)->post('/concerns', [
            'category' => 'Facilities / Equipment',
            'description' => 'A maintenance problem reported by the maintenance office itself.',
            'is_anonymous' => 0,
        ]);

        $concern = Concern::where('user_id', $gsu->id)->latest('id')->first();
        $this->assertNotNull($concern, 'The concern should have been created');
        $this->assertNotSame(
            $gsu->id,
            $concern->assigned_to,
            'Routing must not assign a concern to the person who filed it'
        );
        // ...and it must not simply give up either. Excluding the reporter
        // empties a one-person office, which has to fall through to the
        // escalation chain rather than leaving the concern unassigned and
        // visible to nobody but the person who reported it.
        $this->assertNotNull(
            $concern->assigned_to,
            'A concern the reporter cannot handle must still reach somebody'
        );

        fwrite(STDERR, "  [intake] self-filed concern routed away from reporter; assigned_to=".var_export($concern->assigned_to, true)."\n");
    }
}
