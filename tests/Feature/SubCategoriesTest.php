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
            'subcategory' => 'Grades',
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

    /**
     * It has to be answered, wherever the category offers a list.
     *
     * Which is every category but "Others", the one that breaks down into
     * nothing. Requiring it is only fair because every list now ends in
     * its own catch-all -- "Other academic concern", "Other hazard" -- so
     * a student whose situation is not on the list is never cornered into
     * picking something untrue about their own report.
     */
    public function test_it_must_be_answered(): void
    {
        $this->file(['subcategory' => ''])->assertSessionHasErrors('subcategory');

        $this->assertNull(Concern::latest('id')->first(), 'it was filed without one');

        fwrite(STDERR, "  [sub] it must be answered\n");
    }

    /** Including by way of the catch-all, which every list carries. */
    public function test_every_list_ends_in_a_catch_all(): void
    {
        foreach (Concern::CATEGORIES as $category) {
            $options = Concern::subcategoriesFor($category);

            if ($options === []) {
                continue;
            }

            $last = end($options);

            $this->assertStringStartsWith('Other', $last,
                $category.' has no catch-all, so requiring an answer corners anybody it does not cover');

            // Named for its category, not a bare "Other": in a list of
            // five it is read as part of the same question, and "Other"
            // alone after four hazards reads as "other what?".
            $this->assertNotSame('Other', $last, $category.' has a bare "Other"');
        }

        fwrite(STDERR, "  [sub] every list ends in a catch-all named for its category\n");
    }

    /** And answering it changes nothing about who handles the concern. */
    public function test_it_changes_no_routing(): void
    {
        $this->file(['subcategory' => 'Grades'])->assertSessionHasNoErrors();
        $first = Concern::latest('id')->first();

        $this->file(['subcategory' => 'Other academic concern', 'description' => 'A different description entirely, long enough.'])
            ->assertSessionHasNoErrors();
        $second = Concern::latest('id')->first();

        $this->assertNotSame($first->id, $second->id);

        $this->assertSame(
            optional(optional($first->assignedUser)->role)->name,
            optional(optional($second->assignedUser)->role)->name,
            'the sub-category must not change who handles it'
        );

        fwrite(STDERR, "  [sub] it changes no routing\n");
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
