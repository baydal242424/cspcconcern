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
 * Filters on the concern list, for every role.
 *
 * The property that matters is that they only ever NARROW. They are applied
 * after scopeVisibleTo(), so no combination of category, status or search term
 * can reach a concern the role could not already open -- including by typing
 * its number.
 */
class ConcernFiltersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, UserSeeder::class]);
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
            'description' => 'A concern for the list to show.',
            'status' => 'submitted',
            'is_anonymous' => false,
        ], $overrides));
    }

    public function test_filters_narrow_the_list(): void
    {
        $academic = $this->concern(['category' => 'Academic', 'description' => 'Grades were not released on time.']);
        $facilities = $this->concern(['category' => 'Facilities', 'description' => 'The laboratory aircon is broken.']);

        $student = $this->student();

        // The list shows the category, not the description, so the rows are
        // checked by id rather than by text from the concern body.
        $byCategory = $this->actingAs($student)->get('/concerns?category=Facilities')->assertOk();
        $this->assertSame([$facilities->id], $byCategory->viewData('concerns')->pluck('id')->all());

        // Search reads the description even though the list does not print it.
        $bySearch = $this->actingAs($student)->get('/concerns?q=aircon')->assertOk();
        $this->assertSame([$facilities->id], $bySearch->viewData('concerns')->pluck('id')->all());

        // And by concern number, with or without the hash.
        $byNumber = $this->actingAs($student)->get('/concerns?q=%23'.$academic->id)->assertOk();
        $this->assertSame([$academic->id], $byNumber->viewData('concerns')->pluck('id')->all());

        fwrite(STDERR, "  [filters] category, description and concern number all narrow the list\n");
    }

    /** Asking for a resolved status overrides the hide-resolved default. */
    public function test_filtering_to_a_finished_status_shows_finished_concerns(): void
    {
        $this->concern(['status' => 'resolved'])->forceFill(['resolved_at' => now()])->save();
        $this->concern(['status' => 'submitted']);

        $student = $this->student();

        // The default list hides it.
        $default = $this->actingAs($student)->get('/concerns')->assertOk();
        $this->assertSame(1, $default->viewData('concerns')->total());

        // Asking for it by name brings it back without ?show_resolved=1.
        $resolved = $this->actingAs($student)->get('/concerns?status=resolved')->assertOk();
        $this->assertSame(1, $resolved->viewData('concerns')->total());
        $this->assertSame('resolved', $resolved->viewData('concerns')->first()->status);

        fwrite(STDERR, "  [filters] filtering to Resolved returns resolved concerns, not an empty list\n");
    }

    public function test_a_date_range_finds_past_concerns(): void
    {
        $lastTerm = $this->concern(['description' => 'Filed last term.']);
        $lastTerm->forceFill(['created_at' => now()->subMonths(6)])->save();

        $this->concern(['description' => 'Filed this week.']);

        $page = $this->actingAs($this->student())
            ->get('/concerns?from='.now()->subMonths(7)->format('Y-m-d').'&to='.now()->subMonths(5)->format('Y-m-d'))
            ->assertOk();

        $this->assertSame([$lastTerm->id], $page->viewData('concerns')->pluck('id')->all());

        fwrite(STDERR, "  [filters] a date range reaches back to an earlier term\n");
    }

    public function test_the_order_can_be_reversed(): void
    {
        $first = $this->concern();
        $first->forceFill(['created_at' => now()->subDays(3)])->save();
        $second = $this->concern();

        $oldest = $this->actingAs($this->student())->get('/concerns?sort=oldest')->assertOk();
        $this->assertSame([$first->id, $second->id], $oldest->viewData('concerns')->pluck('id')->all());

        $newest = $this->actingAs($this->student())->get('/concerns')->assertOk();
        $this->assertSame([$second->id, $first->id], $newest->viewData('concerns')->pluck('id')->all());

        fwrite(STDERR, "  [filters] oldest-first reverses the order\n");
    }

    /**
     * Filters run after visibleTo(), so they cannot be used to fish. An
     * Instructor filtering for Harassment gets their own visible rows only.
     */
    public function test_a_filter_cannot_reach_past_what_the_role_may_read(): void
    {
        $counselling = $this->concern([
            'category' => 'Harassment',
            'description' => 'A counselling matter nobody else should read.',
        ]);

        $instructor = User::create([
            'name' => 'An Instructor',
            'email' => 'teacher2@cspc.edu.ph',
            'password' => Hash::make('not-used'),
            'role_id' => Role::where('name', 'Instructor')->firstOrFail()->id,
            'status' => 'approved',
            'department' => 'College of Computer Studies',
        ]);

        $byCategory = $this->actingAs($instructor)->get('/concerns?category=Harassment')->assertOk();
        $this->assertSame(0, $byCategory->viewData('concerns')->total());
        $byCategory->assertDontSee('nobody else should read', false);

        // And the search box cannot pull one back by its number either.
        $byId = $this->actingAs($instructor)->get('/concerns?q=%23'.$counselling->id)->assertOk();
        $this->assertSame(0, $byId->viewData('concerns')->total());

        fwrite(STDERR, "  [filters] a filter narrows what a role sees, it never widens it\n");
    }

    /** A junk query string is a narrowing that matches nothing, not a 500. */
    public function test_an_unknown_filter_value_is_ignored(): void
    {
        $this->concern();

        $page = $this->actingAs($this->student())
            ->get('/concerns?category=NotACategory&status=nonsense&urgency=Extreme&from=not-a-date&sort=sideways')
            ->assertOk();

        $this->assertSame(1, $page->viewData('concerns')->total());
        $this->assertSame(0, $page->viewData('activeFilterCount'));
        $this->assertSame('newest', $page->viewData('filters')['sort']);

        fwrite(STDERR, "  [filters] unknown filter values are dropped rather than erroring\n");
    }

    public function test_an_empty_filtered_list_says_it_is_the_filters(): void
    {
        $this->concern(['category' => 'Academic']);

        $this->actingAs($this->student())->get('/concerns?category=Equipment')->assertOk()
            ->assertSee('No concerns match those filters.', false)
            ->assertDontSee("You haven't submitted any concerns yet", false);

        fwrite(STDERR, "  [filters] an empty result blames the filters, not the student's history\n");
    }

    /**
     * The dashboard stays administrator-only, per the panel's ruling. A
     * student's route to their own concerns is the list, which the filters
     * above are for.
     */
    public function test_the_dashboard_is_not_open_to_students(): void
    {
        $this->actingAs($this->student())->get('/dashboard')->assertForbidden();

        $admin = User::where('email', 'admin@cspc.edu.ph')->firstOrFail();
        $this->actingAs($admin)->get('/dashboard')->assertOk()->assertSee('Trending Dashboard', false);

        // And nothing offers it to them in the navigation.
        $this->actingAs($this->student())->get('/concerns')->assertOk()
            ->assertDontSee('My Activity', false);

        fwrite(STDERR, "  [filters] the dashboard remains administrator-only\n");
    }
}
