<?php

namespace Tests\Feature;

use App\Models\Concern;
use App\Models\Role;
use App\Models\Section;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The dashboard has to show what is STUCK, not only what was filed.
 *
 * Counts of categories and statuses describe the past. An unassigned concern,
 * a staff member waiting for a role, and a class nobody advises are the three
 * things an administrator can act on today -- and none of them appeared
 * anywhere on the page before.
 */
class DashboardCoversTheQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, UserSeeder::class]);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@cspc.edu.ph')->firstOrFail();
    }

    private function student(): User
    {
        return User::where('email', 'student@my.cspc.edu.ph')->firstOrFail();
    }

    private function concern(array $overrides = []): Concern
    {
        return Concern::create(array_merge([
            'user_id' => $this->student()->id,
            'category' => 'Academic',
            'department' => 'College of Computer Studies',
            'description' => 'A concern for the dashboard to count.',
            'status' => 'submitted',
            'is_anonymous' => false,
        ], $overrides));
    }

    public function test_it_counts_what_is_stuck(): void
    {
        // Open and assigned to nobody: visible to its reporter and no one else.
        $this->concern(['assigned_to' => null, 'status' => 'submitted']);

        // Staff who asked for a role and are still waiting.
        User::create([
            'name' => 'Waiting For A Role',
            'email' => 'waiting@cspc.edu.ph',
            'password' => Hash::make('not-used'),
            'role_id' => Role::where('name', 'Faculty/Staff')->firstOrFail()->id,
            'requested_role_id' => Role::where('name', 'Instructor')->firstOrFail()->id,
            'role_requested_at' => now(),
            'department' => 'College of Computer Studies',
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);

        // A class on record that nobody advises.
        $term = Section::currentTerm();
        Section::create([
            'course' => 'BS Information Technology',
            'section' => '2B',
            'school_year' => $term['school_year'],
            'semester' => $term['semester'],
            'adviser_id' => null,
        ]);

        $page = $this->actingAs($this->admin())->get('/dashboard')->assertOk();

        $this->assertSame(1, $page->viewData('unassignedOpen'));
        $this->assertSame(1, $page->viewData('pendingRoleRequests'));
        $this->assertSame(1, $page->viewData('classesWithoutAdviser'));

        $page->assertSee('Needs Attention', false)
            ->assertSee('Unassigned concerns', false)
            ->assertSee('Staff waiting for a role', false)
            ->assertSee('Classes with no adviser', false);

        fwrite(STDERR, "  [dashboard] unassigned work, waiting staff and unadvised classes are on the page\n");
    }

    public function test_it_shows_referrals_and_escalations(): void
    {
        $this->concern(['status' => 'referred', 'referred_to' => 'Guidance Counselor']);
        $this->concern(['status' => 'referred', 'referred_to' => 'Guidance Counselor']);
        $this->concern(['status' => 'referred', 'referred_to' => 'Dean']);
        $this->concern(['about_staff_id' => $this->admin()->id]);
        $this->concern(['skip_adviser' => true]);

        $page = $this->actingAs($this->admin())->get('/dashboard')->assertOk();

        $this->assertSame(3, $page->viewData('referredOpen'));
        $this->assertSame(['Guidance Counselor' => 2, 'Dean' => 1], $page->viewData('referralsByOffice'));
        $this->assertSame(1, $page->viewData('aboutStaffCount'));
        $this->assertSame(1, $page->viewData('adviserBypassed'));

        $page->assertSee('Referrals &amp; Escalations', false)
            ->assertSee('Filed past the class adviser', false)
            ->assertSee('Guidance Counselor', false);

        fwrite(STDERR, "  [dashboard] referrals by office, named staff and adviser bypasses are on the page\n");
    }

    /**
     * A teacher is not "staff".
     *
     * The tile counted anyone the student named under one heading that said
     * "staff member" -- the word this system reserves for the deans, chairs,
     * counsellors and offices in the second picker. The person named is most
     * often the teacher who advises their own class, so the single number read
     * as a complaint about a stranger.
     */
    public function test_it_separates_a_named_teacher_from_a_named_officer(): void
    {
        $adviser = User::create([
            'name' => 'The Class Adviser',
            'email' => 'the.adviser@cspc.edu.ph',
            'password' => Hash::make('not-used'),
            'role_id' => Role::where('name', 'Instructor')->firstOrFail()->id,
            'status' => 'approved',
            'department' => 'College of Computer Studies',
        ]);

        $dean = User::create([
            'name' => 'The Dean',
            'email' => 'the.dean@cspc.edu.ph',
            'password' => Hash::make('not-used'),
            'role_id' => Role::where('name', 'Dean')->firstOrFail()->id,
            'status' => 'approved',
            'department' => 'College of Computer Studies',
        ]);

        $this->concern(['about_staff_id' => $adviser->id]);
        $this->concern(['about_staff_id' => $adviser->id]);
        $this->concern(['about_staff_id' => $dean->id]);

        $page = $this->actingAs($this->admin())->get('/dashboard')->assertOk();

        $this->assertSame(2, $page->viewData('aboutTeacherCount'));
        $this->assertSame(1, $page->viewData('aboutOfficerCount'));

        $page->assertSee('Filed about a teacher', false)
            ->assertSee('Filed about an officer', false)
            ->assertDontSee('Filed about a staff member', false);

        fwrite(STDERR, "  [dashboard] a named teacher is counted apart from a named officer\n");
    }

    /**
     * The average time to resolve was taken off the page.
     *
     * An average over every resolved concern hides the one that matters: two
     * settled in an hour and one left for three days average out to something
     * comfortable, and the figure gives nobody anything to do. Resolved
     * concerns are still timestamped, so a more useful measure -- the oldest
     * concern still waiting -- can be built on the same data later.
     */
    public function test_it_no_longer_reports_an_average_time_to_resolve(): void
    {
        $this->concern(['status' => 'resolved'])
            ->forceFill(['created_at' => now()->subHours(6), 'resolved_at' => now()->subHours(2)])
            ->save();

        $page = $this->actingAs($this->admin())->get('/dashboard')->assertOk();

        $page->assertDontSee('Average time to resolve', false);
        $this->assertArrayNotHasKey('averageResolutionHours', $page->viewData());

        // The timestamps it was built from are untouched.
        $this->assertNotNull(Concern::whereNotNull('resolved_at')->first());

        fwrite(STDERR, "  [dashboard] the average time to resolve is gone, its timestamps are not\n");
    }

    /** Nothing filed yet should read as empty, not as a broken page. */
    public function test_an_empty_system_still_renders(): void
    {
        $page = $this->actingAs($this->admin())->get('/dashboard')->assertOk();

        $page->assertSee('No concern has been referred to another office yet.', false);

        fwrite(STDERR, "  [dashboard] an empty system renders without errors\n");
    }
}
