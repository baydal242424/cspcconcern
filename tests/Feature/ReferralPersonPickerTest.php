<?php

namespace Tests\Feature;

use App\Models\Concern;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The "Refer to a specific person" dropdown.
 *
 * A referral used to name an OFFICE only, and findHandler() decided which
 * person in it received the case. Staff can now name the colleague instead --
 * but only when there is somebody to name: an office with nobody eligible is
 * not offered, and a page with no eligible colleague at all does not render
 * the dropdown.
 */
class ReferralPersonPickerTest extends TestCase
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

    private function makeConcern(array $overrides = []): Concern
    {
        return Concern::create(array_merge([
            'user_id' => $this->u('student@my.cspc.edu.ph')->id,
            'category' => 'Academic',
            'department' => 'College of Computer Studies',
            'description' => 'Something happened in class.',
            'urgency' => null,
            'status' => 'submitted',
            'is_anonymous' => false,
        ], $overrides));
    }

    /** The picker is on the page, and lists real colleagues tagged by office. */
    public function test_picker_is_rendered_when_colleagues_exist(): void
    {
        $staff = $this->u('staff@cspc.edu.ph');
        $concern = $this->makeConcern(['assigned_to' => $staff->id]);
        $counselor = $this->u('counselor@cspc.edu.ph');

        $resp = $this->actingAs($staff)->get("/concerns/{$concern->id}");
        $resp->assertOk();

        $resp->assertSee('refer-person-group', false);
        $resp->assertSee('name="referred_to_user_id"', false);
        $resp->assertSee('value="'.$counselor->id.'" data-role="Guidance Counselor"', false);

        fwrite(STDERR, "  [picker] rendered with named colleagues: YES\n");
    }

    /**
     * A destination with nobody in it is not offered.
     *
     * The list used to be every destination, whoever was looking. An adviser
     * was offered "Adviser", which hands the case back to the role already
     * holding it; and an office with nobody eligible was offered too, where
     * choosing it earned "There is currently no X available to receive this
     * referral" -- a dead end found only after submitting.
     */
    public function test_a_destination_with_nobody_in_it_is_not_offered(): void
    {
        $adviser = User::create([
            'name' => 'The Only Adviser',
            'email' => 'only.adviser@cspc.edu.ph',
            'password' => \Illuminate\Support\Facades\Hash::make('not-used'),
            'role_id' => \App\Models\Role::where('name', 'Adviser')->firstOrFail()->id,
            'status' => 'approved',
            'department' => 'College of Computer Studies',
        ]);

        $concern = $this->makeConcern(['assigned_to' => $adviser->id]);

        $offered = $this->actingAs($adviser)->get("/concerns/{$concern->id}")->assertOk()
            ->viewData('referralDestinations');

        $this->assertArrayNotHasKey(
            'Adviser',
            $offered->all(),
            'the only adviser must not be offered the Adviser destination'
        );

        // Every destination still listed has somebody standing in it.
        $candidates = $this->actingAs($adviser)->get("/concerns/{$concern->id}")
            ->viewData('referralCandidates');

        foreach ($offered as $role => $label) {
            $this->assertTrue(
                $candidates->has($role) && $candidates->get($role)->isNotEmpty(),
                "{$role} is offered with nobody in it"
            );
        }

        // And the ones that do have somebody are still there.
        $this->assertArrayHasKey('Guidance Counselor', $offered->all());

        fwrite(STDERR, "  [picker] only destinations with somebody eligible are offered\n");
    }

    /**
     * One person in an office means no choice to make.
     *
     * A dropdown with one real option asks a question with one answer: picking
     * them and letting the system choose reach the same desk. The page puts
     * the control away and names the recipient instead, and brings it back the
     * moment a second person exists -- it counts the options rather than
     * naming any office, so adding a second chair is all it takes.
     *
     * The counting runs in the browser and was driven there. This pins what
     * the page has to hand it.
     */
    public function test_the_page_can_tell_a_sole_recipient_from_a_choice(): void
    {
        $staff = $this->u('staff@cspc.edu.ph');
        $concern = $this->makeConcern(['assigned_to' => $staff->id]);

        $html = $this->actingAs($staff)->get("/concerns/{$concern->id}")->assertOk()->getContent();

        // The line that replaces the dropdown...
        $this->assertStringContainsString('id="refer-sole-recipient"', $html);

        // ...and the rule that decides between them.
        $this->assertStringContainsString('var soleRecipient = referring && matches === 1;', $html);
        $this->assertStringContainsString('var show = referring && matches > 1;', $html);

        // Every candidate still carries the office it belongs to, which is
        // what the counting reads.
        $this->assertStringContainsString('data-role="Guidance Counselor"', $html);

        fwrite(STDERR, "  [picker] the page can tell one recipient from a real choice\n");
    }

    /** No eligible colleague anywhere -> the dropdown is not rendered at all. */
    public function test_picker_is_hidden_when_no_other_staff_exist(): void
    {
        // A lone Admin on an otherwise staff-less system: every other employee
        // account is removed, so there is nobody left to refer to.
        $admin = $this->u('admin@cspc.edu.ph');
        Concern::query()->delete();
        User::employees()->where('id', '!=', $admin->id)->delete();

        $concern = $this->makeConcern(['assigned_to' => $admin->id]);

        $resp = $this->actingAs($admin)->get("/concerns/{$concern->id}");
        $resp->assertOk();

        // The office dropdown still stands; only the people picker is gone.
        $resp->assertSee('name="referred_to"', false);
        $resp->assertDontSee('name="referred_to_user_id"', false);

        fwrite(STDERR, "  [picker] suppressed when nobody to refer to: YES\n");
    }

    /** Picking a person hands the concern to THAT person, not findHandler's choice. */
    public function test_named_person_receives_the_referral(): void
    {
        $staff = $this->u('staff@cspc.edu.ph');
        $concern = $this->makeConcern(['assigned_to' => $staff->id]);

        // Not the seeded first pick for "Guidance Counselor" -- the point is
        // that the explicit choice overrides the automatic one.
        $chosen = $this->u('mkiarasapinoso@cspc.edu.ph');

        $resp = $this->actingAs($staff)->patch("/concerns/{$concern->id}", [
            'investigation_notes' => 'Looked into this and spoke with the people involved.',
            'resolution_notes' => 'Recorded what is being done about it.',
            'status' => 'referred',
            'referred_to' => 'Guidance Counselor',
            'referred_to_user_id' => $chosen->id,
            'urgency' => 'Medium',
        ]);
        $resp->assertRedirect();

        $concern->refresh();
        $this->assertSame('referred', $concern->status);
        $this->assertSame($chosen->id, $concern->assigned_to);
        $this->assertTrue(
            $concern->auditLogs()->where('description', 'like', "%{$chosen->name}%")->exists(),
            'The named recipient should appear on the timeline'
        );

        fwrite(STDERR, "  [picker] named recipient assigned_to={$concern->assigned_to} (chose {$chosen->id})\n");
    }

    /** Leaving the picker empty keeps the old office-level behaviour. */
    public function test_office_only_referral_still_works(): void
    {
        $staff = $this->u('staff@cspc.edu.ph');
        $concern = $this->makeConcern(['assigned_to' => $staff->id]);

        $this->actingAs($staff)->patch("/concerns/{$concern->id}", [
            'investigation_notes' => 'Looked into this and spoke with the people involved.',
            'resolution_notes' => 'Recorded what is being done about it.',
            'status' => 'referred',
            'referred_to' => 'Guidance Counselor',
            'referred_to_user_id' => '',
            'urgency' => 'Medium',
        ])->assertRedirect();

        $concern->refresh();
        $this->assertSame('referred', $concern->status);
        $this->assertSame(
            'Guidance Counselor',
            optional(optional($concern->assignedUser)->role)->name
        );

        fwrite(STDERR, "  [picker] office-only referral still routes: YES\n");
    }

    /** A forged id is re-checked server-side: a student can never be handed a case. */
    public function test_ineligible_person_is_rejected(): void
    {
        $staff = $this->u('staff@cspc.edu.ph');
        $concern = $this->makeConcern(['assigned_to' => $staff->id]);
        $student = $this->u('student2@my.cspc.edu.ph');

        $this->actingAs($staff)->patch("/concerns/{$concern->id}", [
            'investigation_notes' => 'Looked into this and spoke with the people involved.',
            'resolution_notes' => 'Recorded what is being done about it.',
            'status' => 'referred',
            'referred_to' => 'Guidance Counselor',
            'referred_to_user_id' => $student->id,
            'urgency' => 'Medium',
        ])->assertSessionHasErrors('referred_to_user_id');

        $concern->refresh();
        $this->assertSame('submitted', $concern->status);
        $this->assertSame($staff->id, $concern->assigned_to);

        fwrite(STDERR, "  [picker] forged recipient rejected, concern untouched: YES\n");
    }

    /** The person a concern is ABOUT is never offered, and never accepted. */
    public function test_subject_of_the_concern_is_never_a_candidate(): void
    {
        $staff = $this->u('staff@cspc.edu.ph');
        $subject = $this->u('counselor@cspc.edu.ph');
        $concern = $this->makeConcern([
            'assigned_to' => $staff->id,
            'about_staff_id' => $subject->id,
        ]);

        $resp = $this->actingAs($staff)->get("/concerns/{$concern->id}");
        $resp->assertOk();
        $resp->assertDontSee('value="'.$subject->id.'" data-role=', false);

        $this->actingAs($staff)->patch("/concerns/{$concern->id}", [
            'investigation_notes' => 'Looked into this and spoke with the people involved.',
            'resolution_notes' => 'Recorded what is being done about it.',
            'status' => 'referred',
            'referred_to' => 'Guidance Counselor',
            'referred_to_user_id' => $subject->id,
            'urgency' => 'Medium',
        ])->assertSessionHasErrors('referred_to_user_id');

        $concern->refresh();
        $this->assertNotSame($subject->id, $concern->assigned_to);

        fwrite(STDERR, "  [picker] concern's own subject excluded: YES\n");
    }
}
