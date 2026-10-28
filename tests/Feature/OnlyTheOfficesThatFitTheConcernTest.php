<?php

namespace Tests\Feature;

use App\Http\Controllers\ConcernController;
use App\Models\Concern;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A concern can only be referred to an office with a part in it.
 *
 * Every office was offered on every concern, so a disputed grade could be
 * handed to the Guidance Counselor and a broken aircon to the VPAA. A list
 * where most of the entries are wrong is a list nobody reads: the handler
 * scrolls for the one that fits, and a wrong pick costs the student a
 * fortnight while their case travels to an office that was never going to
 * act on it.
 */
class OnlyTheOfficesThatFitTheConcernTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, UserSeeder::class]);
    }

    private function handler(): User
    {
        return User::where('email', 'staff@cspc.edu.ph')->firstOrFail();
    }

    private function aConcern(string $category, array $overrides = []): Concern
    {
        return Concern::create(array_merge([
            'user_id' => User::where('email', 'student@my.cspc.edu.ph')->firstOrFail()->id,
            'category' => $category,
            'department' => 'College of Computer Studies',
            'description' => 'What happened, described at length by the student.',
            'urgency' => 'Low',
            'status' => 'in_progress',
            'is_anonymous' => false,
            'assigned_to' => $this->handler()->id,
        ], $overrides));
    }

    /** The case that prompted this: a grade is not counselling work. */
    public function test_an_academic_concern_does_not_offer_the_guidance_counselor(): void
    {
        $offices = ConcernController::referralRolesFor('Academic');

        $this->assertNotContains('Guidance Counselor', $offices);
        $this->assertNotContains('General Services', $offices);

        // The teaching staff are no longer a destination at all -- the
        // panel took them off the list. What is left is the academic
        // line a grade actually travels.
        $this->assertNotContains('Instructor', $offices);
        $this->assertContains('Program Chair', $offices);
        $this->assertContains('Adviser', $offices);

        fwrite(STDERR, '  [offices] Academic -> '.implode(', ', $offices)."\n");
    }

    /**
     * A broken aircon does not go to the VPAA.
     *
     * They sit above the ACADEMIC line, which is not the same as above
     * everything. Offered on every category they turned up as a
     * destination for maintenance work.
     */
    public function test_maintenance_does_not_offer_the_vpaa(): void
    {
        foreach (['Facilities', 'Equipment'] as $category) {
            $offices = ConcernController::referralRolesFor($category);

            $this->assertNotContains('Vice President for Academic Affairs', $offices, $category);
            $this->assertContains('General Services', $offices, $category);
        }

        fwrite(STDERR, "  [offices] maintenance does not offer the VPAA\n");
    }

    /**
     * No category is a dead end.
     *
     * The failure mode of narrowing a list: a handler holds a case with
     * nowhere to send it, which is worse than a list that is too long.
     */
    public function test_every_category_can_still_go_somewhere(): void
    {
        foreach (Concern::CATEGORIES as $category) {
            $this->assertNotEmpty(
                ConcernController::referralRolesFor($category),
                $category.' can be referred nowhere'
            );
        }

        fwrite(STDERR, "  [offices] every category can still go somewhere\n");
    }

    /**
     * Going UP is a ladder, not a list.
     *
     * The Dean and the VPAA used to be offered on every concern, so any
     * office could hand a case straight to the top. That is not an
     * escalation path; it is a way round the person above them. A Chair
     * reaches their Dean, a Dean reaches the VPAA, and neither office is
     * reachable from anywhere else.
     */
    public function test_the_top_two_offices_are_reachable_only_by_climbing(): void
    {
        foreach (Concern::CATEGORIES as $category) {
            $offices = ConcernController::referralRolesFor($category);

            $this->assertNotContains('Dean', $offices, $category.' reaches the Dean directly');
            $this->assertNotContains('Vice President for Academic Affairs', $offices,
                $category.' reaches the VPAA directly');
        }

        $chair = User::whereHas('role', fn ($q) => $q->where('name', 'Program Chair'))->first()
            ?? User::factory()->create([
                'role_id' => \App\Models\Role::where('name', 'Program Chair')->value('id'),
                'department' => 'College of Computer Studies',
                'status' => 'approved',
            ]);

        $dean = User::whereHas('role', fn ($q) => $q->where('name', 'Dean'))->first()
            ?? User::factory()->create([
                'role_id' => \App\Models\Role::where('name', 'Dean')->value('id'),
                'department' => 'College of Computer Studies',
                'status' => 'approved',
            ]);

        $this->assertSame(['Dean', 'Faculty/Staff'],
            ConcernController::referralRolesFor('Academic', $chair));

        $this->assertSame(['Vice President for Academic Affairs', 'Faculty/Staff'],
            ConcernController::referralRolesFor('Academic', $dean));

        // The rung they stand on holds whatever the concern is about.
        $this->assertSame(
            ConcernController::referralRolesFor('Academic', $dean),
            ConcernController::referralRolesFor('Facilities', $dean),
            'the ladder changed with the category'
        );

        fwrite(STDERR, "  [offices] the Dean and the VPAA are reached only by climbing\n");
    }

    /**
     * Where a concern lands automatically, it can also be referred.
     *
     * The invariant that keeps the two lists honest. Routing sends an
     * Academic concern to the Adviser; if the referral list for Academic
     * did not include Adviser, the office already holding the case would
     * be one nobody could hand it back to.
     */
    public function test_the_office_a_concern_routes_to_is_a_valid_destination(): void
    {
        foreach (ConcernController::CATEGORY_ROUTING as $category => $routesTo) {
            // Staff Admin is no longer a referral destination at all, by
            // decision recorded on REFERRAL_ROLES.
            if (! in_array($routesTo, ConcernController::REFERRAL_ROLES, true)) {
                continue;
            }

            $this->assertContains(
                $routesTo,
                ConcernController::referralRolesFor($category),
                "{$category} routes to {$routesTo}, which cannot be referred to"
            );
        }

        fwrite(STDERR, "  [offices] every category can reach the office it routes to\n");
    }

    /** "Others" is the bucket for what nobody anticipated, so it keeps everything. */
    public function test_others_keeps_the_full_list(): void
    {
        $offices = ConcernController::referralRolesFor('Others');

        // Everything except the two at the top of the ladder, which are
        // reached by climbing or not at all -- "Others" is the bucket for
        // what nobody anticipated, not a way past the person above you.
        $this->assertSame(
            array_values(array_diff(ConcernController::REFERRAL_ROLES,
                ['Dean', 'Vice President for Academic Affairs'])),
            $offices
        );

        fwrite(STDERR, '  [offices] Others keeps everything below the ladder: '
            .count($offices)." offices\n");
    }

    /**
     * The narrowing is enforced, not merely drawn.
     *
     * A list filtered only in the view is cosmetic: the form can be
     * posted by hand.
     */
    public function test_a_crafted_referral_to_the_wrong_office_is_refused(): void
    {
        $concern = $this->aConcern('Academic');

        $this->actingAs($this->handler())->patch("/concerns/{$concern->id}", [
            'investigation_notes' => 'Looked into the grade dispute.',
            'resolution_notes' => 'Passing it on.',
            'status' => 'referred',
            'referred_to' => 'Guidance Counselor',
            'urgency' => 'Low',
        ])->assertSessionHasErrors('referred_to');

        $this->assertSame('in_progress', $concern->fresh()->status);

        fwrite(STDERR, "  [offices] a crafted referral to the wrong office is refused\n");
    }

    /**
     * The dropdown shows the same list the server will accept.
     *
     * Taken from the page rather than asserted against a hand-written
     * list, so the two cannot drift: whatever is offered is what gets
     * posted back.
     *
     * @return array<int, string>
     */
    private function officesOfferedOn(Concern $concern): array
    {
        // Somebody for an Academic concern to be handed to. The seeded
        // data has nobody in the offices that category now reaches -- the
        // Dean used to stand in for all of them, and the Dean is off the
        // list except by climbing.
        User::factory()->create([
            'name' => 'A College Office',
            'role_id' => \App\Models\Role::where('name', 'Faculty/Staff')->value('id'),
            'department' => 'College of Computer Studies',
            'status' => 'approved',
        ]);

        return $this->actingAs($this->handler())
            ->get(route('concerns.show', $concern))
            ->assertOk()
            ->viewData('referralDestinations')
            ->keys()
            ->all();
    }

    public function test_the_dropdown_matches_what_the_server_accepts(): void
    {
        $concern = $this->aConcern('Academic');

        $offered = $this->officesOfferedOn($concern);
        $allowed = ConcernController::referralRolesFor('Academic');

        $this->assertNotEmpty($offered, 'the dropdown offered nothing at all');

        foreach ($offered as $role) {
            $this->assertContains($role, $allowed, $role.' is offered but would be refused');
        }

        $this->assertNotContains('Guidance Counselor', $offered);

        fwrite(STDERR, '  [offices] the dropdown offers only what the server accepts: '
            .implode(', ', $offered)."\n");
    }

    /**
     * And every office the page does offer actually works.
     *
     * Driven off the dropdown rather than a name chosen here, because a
     * destination can be valid for the category and still have nobody in
     * it -- which is a different refusal, and not the one under test.
     */
    public function test_every_office_the_page_offers_can_receive_the_concern(): void
    {
        $offered = $this->officesOfferedOn($this->aConcern('Academic'));

        $this->assertNotEmpty($offered);

        foreach ($offered as $office) {
            $concern = $this->aConcern('Academic');

            $this->actingAs($this->handler())->patch("/concerns/{$concern->id}", [
                'investigation_notes' => 'Looked into the grade dispute.',
                'resolution_notes' => 'Passing it on.',
                'status' => 'referred',
                'referred_to' => $office,
                'urgency' => 'Low',
            ])->assertSessionHasNoErrors();

            $this->assertSame('referred', $concern->fresh()->status, $office.' could not receive it');
            $this->assertSame($office, $concern->fresh()->referred_to);
        }

        fwrite(STDERR, '  [offices] all '.count($offered)." offered offices can receive the concern\n");
    }
}
