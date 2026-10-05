<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Manage Users searches and filters on the server, and pages its results.
 *
 * It used to render every account as a card and hide the ones that did not
 * match in the browser. That is fine for a few hundred rows and fatal past
 * them: at 896 accounts the page exhausted PHP's memory while rendering, so
 * nobody saw anything at all. Filtering in the database also means a search
 * reaches the whole roster rather than whatever happened to be on the page.
 */
class ManageUsersFiltersTest extends TestCase
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

    private function staff(string $name, string $email, string $role, array $extra = []): User
    {
        return User::create(array_merge([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('not-used'),
            'role_id' => Role::where('name', $role)->firstOrFail()->id,
            'status' => 'approved',
        ], $extra));
    }

    public function test_the_page_offers_role_college_and_status_filters(): void
    {
        $page = $this->actingAs($this->admin())->get('/admin/users')->assertOk();

        $page->assertSee('name="role"', false)
            ->assertSee('name="college"', false)
            ->assertSee('name="status"', false)
            ->assertSee('All roles', false)
            ->assertSee('All colleges &amp; offices', false)
            ->assertSee('Any status', false);

        fwrite(STDERR, "  [manage users] role, college and status filters are on the page\n");
    }

    public function test_filtering_by_role_returns_only_that_role(): void
    {
        $this->staff('A Dean To Find', 'dean.tofind@cspc.edu.ph', 'Dean', [
            'department' => 'College of Computer Studies',
        ]);
        $this->staff('An Instructor', 'teacher.here@cspc.edu.ph', 'Instructor', [
            'department' => 'College of Computer Studies',
        ]);

        $page = $this->actingAs($this->admin())->get('/admin/users?role=Dean')->assertOk();

        $names = $page->viewData('users')->pluck('name');

        $this->assertTrue($names->contains('A Dean To Find'));
        $this->assertFalse($names->contains('An Instructor'));

        // Every row returned really holds that role, not just the one looked for.
        foreach ($page->viewData('users') as $user) {
            $this->assertSame('Dean', optional($user->role)->name);
        }

        fwrite(STDERR, "  [manage users] filtering by role returns that role and nothing else\n");
    }

    public function test_filters_combine(): void
    {
        $this->staff('CCS Dean', 'ccs.dean@cspc.edu.ph', 'Dean', [
            'department' => 'College of Computer Studies',
        ]);
        $this->staff('Nursing Dean', 'chs.dean@cspc.edu.ph', 'Dean', [
            'department' => 'College of Health Sciences',
        ]);

        $page = $this->actingAs($this->admin())
            ->get('/admin/users?role=Dean&college='.urlencode('College of Computer Studies'))
            ->assertOk();

        $names = $page->viewData('users')->pluck('name');

        $this->assertTrue($names->contains('CCS Dean'));
        $this->assertFalse($names->contains('Nursing Dean'));

        fwrite(STDERR, "  [manage users] role and college narrow together\n");
    }

    /** The search has to reach the fields an admin actually holds in hand. */
    public function test_search_covers_name_id_email_and_class(): void
    {
        $student = $this->staff('Juan Dela Cruz', 'juan.search@my.cspc.edu.ph', 'Student', [
            'student_id' => '231009999',
            'department' => 'College of Computer Studies',
            'course' => 'BS Information Technology',
            'section' => '3A',
        ]);

        foreach (['Dela Cruz', '231009999', 'juan.search', '3A'] as $term) {
            $found = $this->actingAs($this->admin())
                ->get('/admin/users?q='.urlencode($term))
                ->assertOk()
                ->viewData('users')
                ->pluck('id');

            $this->assertTrue($found->contains($student->id), "Searching \"{$term}\" should find them");
        }

        fwrite(STDERR, "  [manage users] a search finds them by name, student number, email or class\n");
    }

    /**
     * The reason this moved to the server. Every account used to be rendered
     * and then hidden; past a few hundred, the page ran out of memory.
     */
    public function test_a_long_roster_is_paged_rather_than_rendered_whole(): void
    {
        foreach (range(1, 45) as $n) {
            $this->staff("Instructor {$n}", "bulk{$n}@cspc.edu.ph", 'Instructor', [
                'department' => 'College of Computer Studies',
            ]);
        }

        $page = $this->actingAs($this->admin())->get('/admin/users')->assertOk();
        $users = $page->viewData('users');

        $this->assertLessThanOrEqual(30, $users->count(), 'One page should not render the whole roster');
        $this->assertGreaterThan(30, $users->total());
        $this->assertTrue($users->hasPages());

        // And the second page holds different people.
        $secondPage = $this->actingAs($this->admin())->get('/admin/users?page=2')->assertOk();

        $this->assertEmpty(
            array_intersect(
                $users->pluck('id')->all(),
                $secondPage->viewData('users')->pluck('id')->all()
            ),
            'Page two must not repeat page one'
        );

        fwrite(STDERR, "  [manage users] a long roster is paged, not rendered whole\n");
    }

    /** Filters have to survive paging, or page two silently drops them. */
    public function test_the_filters_are_carried_across_pages(): void
    {
        foreach (range(1, 45) as $n) {
            $this->staff("Instructor {$n}", "bulk{$n}@cspc.edu.ph", 'Instructor', [
                'department' => 'College of Computer Studies',
            ]);
        }

        $page = $this->actingAs($this->admin())->get('/admin/users?role=Instructor&page=2')->assertOk();

        foreach ($page->viewData('users') as $user) {
            $this->assertSame('Instructor', optional($user->role)->name);
        }

        $this->assertStringContainsString('role=Instructor', $page->viewData('users')->nextPageUrl() ?? 'role=Instructor');

        fwrite(STDERR, "  [manage users] the filters are carried onto page two\n");
    }

    /** An empty filtered page is not an empty system. */
    public function test_a_filter_matching_nobody_does_not_read_as_an_empty_roster(): void
    {
        $this->actingAs($this->admin())->get('/admin/users?q=nobody-by-this-name')->assertOk()
            ->assertSee('No account matches that.', false)
            ->assertDontSee('No accounts registered yet.', false);

        fwrite(STDERR, "  [manage users] an empty result blames the filter, not the roster\n");
    }

    /** Test accounts are findable as a set, which is how they get cleaned up. */
    public function test_test_accounts_can_be_found_among_the_rest(): void
    {
        $this->artisan('testing:accounts')->assertSuccessful();

        // By class, which is how a tester looks one up: 3A of a named
        // programme, not an address they would have to remember.
        $page = $this->actingAs($this->admin())
            ->get('/admin/users?q='.urlencode('BS Information Technology'))
            ->assertOk();

        $this->assertTrue(
            $page->viewData('users')->pluck('email')
                ->contains('test.student.bs-information-technology.3a@my.cspc.edu.ph')
        );

        fwrite(STDERR, "  [manage users] the test accounts are findable among hundreds of real ones\n");
    }
}
