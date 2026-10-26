<?php

namespace Tests\Feature;

use App\Models\Concern;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A second dropdown, inside the category.
 *
 * The category decides which office reads a concern and nothing finer.
 * "Academic" covers a disputed grade, a timetable clash and an instructor's
 * conduct alike, so a handler could only tell which by reading the
 * description -- workable for one case, useless for counting a term of them.
 *
 * Routing is untouched: the same office receives it either way. This is what
 * lands on their desk already sorted.
 */
class SubCategoriesTest extends TestCase
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

    private function file(array $overrides = [])
    {
        return $this->actingAs($this->student())->post('/concerns', array_merge([
            'category' => 'Academic',
            'department' => 'College of Computer Studies',
            'description' => 'A description long enough to satisfy the rule.',
        ], $overrides));
    }

    /** Every category that should break down, does. */
    public function test_each_category_offers_its_own_list(): void
    {
        foreach (Concern::CATEGORIES as $category) {
            $options = Concern::subcategoriesFor($category);

            if ($category === 'Others') {
                // It is the category for what the list does not cover, and it
                // already asks the student to say what it is.
                $this->assertSame([], $options, 'Others should not break down');
                continue;
            }

            $this->assertNotEmpty($options, $category.' should offer sub-categories');
        }

        $this->assertContains('Grades', Concern::subcategoriesFor('Academic'));

        fwrite(STDERR, "  [sub] every category except Others breaks down\n");
    }

    /** The form carries the whole map, so the second dropdown can be built. */
    public function test_the_form_carries_the_map(): void
    {
        $html = $this->actingAs($this->student())->get('/concerns/create')
            ->assertOk()->getContent();

        $this->assertStringContainsString('id="subcategory-group"', $html);
        $this->assertStringContainsString('name="subcategory"', $html);
        $this->assertStringContainsString('const SUBCATEGORIES =', $html);
        $this->assertStringContainsString('Grades', $html);

        // Hidden until a category is chosen: its options depend on that.
        $this->assertStringContainsString('id="subcategory-group" style="display:none;"', $html);

        fwrite(STDERR, "  [sub] the form carries the map and starts hidden\n");
    }

    /** Filing one records it. */
    public function test_it_is_stored(): void
    {
        $this->file(['subcategory' => 'Grades'])->assertSessionHasNoErrors();

        $this->assertSame('Grades', Concern::latest('id')->first()->subcategory);

        fwrite(STDERR, "  [sub] a chosen sub-category is stored\n");
    }

    /**
     * It has to belong to the category it was filed under.
     *
     * A dropdown is a suggestion. "Grades" on a broken aircon would be
     * counted as an academic complaint for the rest of the year, and no
     * amount of reading the description undoes a number.
     */
    public function test_a_mismatched_one_is_refused(): void
    {
        $this->file(['category' => 'Facilities', 'subcategory' => 'Grades'])
            ->assertSessionHasErrors('subcategory');

        $this->assertSame(0, Concern::count());

        fwrite(STDERR, "  [sub] a sub-category from another category is refused\n");
    }

    /** Others takes none, because it has its own question. */
    public function test_others_takes_none(): void
    {
        $this->file(['category' => 'Others', 'other_category' => 'Lost locker key', 'subcategory' => 'Grades'])
            ->assertSessionHasErrors('subcategory');

        fwrite(STDERR, "  [sub] Others takes no sub-category\n");
    }

    /** Leaving it out is allowed, and nothing else changes. */
    public function test_it_is_optional_and_changes_no_routing(): void
    {
        $this->file()->assertSessionHasNoErrors();

        $without = Concern::latest('id')->first();
        $this->assertNull($without->subcategory);

        $this->file(['subcategory' => 'Grades'])->assertSessionHasNoErrors();

        $with = Concern::latest('id')->first();

        $this->assertSame(
            optional(optional($without->assignedUser)->role)->name,
            optional(optional($with->assignedUser)->role)->name,
            'the sub-category must not change who handles it'
        );

        fwrite(STDERR, "  [sub] optional, and it changes no routing\n");
    }

    /** It shows on the concern, and the search finds it. */
    public function test_it_is_shown_and_searchable(): void
    {
        $this->file(['subcategory' => 'Class schedule'])->assertSessionHasNoErrors();
        $concern = Concern::latest('id')->first();

        $this->actingAs($this->student())->get("/concerns/{$concern->id}")
            ->assertOk()->assertSee('Class schedule');

        // Searched even though the word is nowhere in the description.
        $this->actingAs($this->student())->get('/concerns?q='.urlencode('schedule'))
            ->assertOk()->assertSee('/concerns/'.$concern->id.'"', false);

        fwrite(STDERR, "  [sub] shown on the concern, and found by search\n");
    }
}
