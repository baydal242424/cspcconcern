<?php

namespace Tests\Feature;

use App\Models\Concern;
use App\Models\ConcernCategory;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Categories are rows, so the office can add one.
 *
 * They were eleven names in a PHP constant with four more constants hanging
 * off them -- the label, the sub-categories, the handling role, the offices
 * it could be referred to. A twelfth meant editing five lists in two files
 * and shipping a release, which in practice means it does not happen: a
 * college that decides to start taking complaints about the canteen waits
 * for whoever still has the repository, or files them all under "Others"
 * and loses the ability to route them anywhere.
 *
 * The eleven are seeded from those same constants, so there is one list and
 * not two.
 */
class TheOfficeCanAddACategoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, UserSeeder::class]);
        ConcernCategory::forget();
    }

    private function admin(): User
    {
        return User::whereHas('role', fn ($q) => $q->where('name', 'System Admin'))->firstOrFail();
    }

    private function student(): User
    {
        return User::where('email', 'student@my.cspc.edu.ph')->firstOrFail();
    }

    /** The eleven arrive from the constants, not retyped. */
    public function test_the_shipped_categories_are_seeded_from_the_constants(): void
    {
        // Every shipped name is present. Not an exact match, and not the
        // first eleven either: the table is the live list, the office has
        // added to it, and "Others" is pinned to the bottom so an added
        // category sits above the bucket for everything unnamed rather
        // than after it. Order is a display decision; what this test is
        // about is that nothing was dropped or retyped on the way in.
        foreach (Concern::CATEGORIES as $shipped) {
            $this->assertContains($shipped, ConcernCategory::names(), "{$shipped} survived the seeding");
        }

        foreach (Concern::CATEGORIES as $name) {
            $this->assertSame(
                Concern::CATEGORY_LABELS[$name] ?? $name,
                ConcernCategory::labelFor($name),
                "label for {$name}"
            );
        }

        // Every one of them is marked as shipped, so none can be deleted
        // out from under the concerns filed against it.
        $this->assertCount(
            count(Concern::CATEGORIES),
            ConcernCategory::cached()->where('is_builtin', true)
        );

        fwrite(STDERR, "  [categories] the shipped eleven are seeded from the constants\n");
    }

    /**
     * The second dropdown is fed plain strings, not stored rows.
     *
     * This is the test that was missing. Everything else here checks
     * subcategoriesFor(), which returns names -- but the filing form's
     * script is handed subcategoryMap(), and that reached into the column
     * directly. When a sub-category became {name, route} the map started
     * handing whole rows to the page, and every option in the second
     * dropdown read "[object Object]".
     *
     * Nothing errored. The page rendered, the JSON was valid, the suite
     * was green, and the list was unusable for every category and every
     * student. So this asserts on what actually reaches the browser.
     */
    public function test_the_filing_form_is_handed_sub_category_names(): void
    {
        $map = Concern::subcategoryMap();

        $this->assertNotEmpty($map);

        foreach ($map as $category => $subs) {
            foreach ($subs as $sub) {
                $this->assertIsString($sub, "{$category} must offer names, not rows");
            }
        }

        // And in the page itself, as the script receives it.
        $html = $this->actingAs($this->student())->get('/concerns/create')->assertOk()->getContent();

        $this->assertStringNotContainsString('[object Object]', $html);
        $this->assertStringContainsString('"Grades"', $html);
        $this->assertStringNotContainsString('{"name":"Grades"', $html);

        fwrite(STDERR, "  [categories] the filing form is handed names, not rows\n");
    }

    /** The website fault is on file but off the form, as it already was. */
    public function test_the_website_fault_keeps_its_row_but_not_its_place_in_the_picker(): void
    {
        $this->assertContains(Concern::WEBSITE_FAULT, ConcernCategory::names());
        $this->assertNotContains(Concern::WEBSITE_FAULT, ConcernCategory::offered());
        $this->assertSame('ICT Unit', ConcernCategory::routingFor(Concern::WEBSITE_FAULT));

        fwrite(STDERR, "  [categories] the website fault keeps its row and its routing\n");
    }

    /** An admin adds one, and it is on the filing form. */
    public function test_an_admin_adds_a_category_and_a_student_can_file_under_it(): void
    {
        $this->actingAs($this->admin())->post('/admin/categories', [
            'name' => 'Canteen',
            'label' => 'Canteen & Food Services',
            'routing_role' => 'General Services',
            'subcategories' => "Food quality\nPrices\nCleanliness",
        ])->assertRedirect();

        ConcernCategory::forget();

        $this->assertContains('Canteen', ConcernCategory::offered());
        $this->assertSame('Canteen & Food Services', ConcernCategory::labelFor('Canteen'));
        $this->assertSame(
            ['Food quality', 'Prices', 'Cleanliness'],
            ConcernCategory::subcategoriesFor('Canteen')
        );

        // On the form, under its label.
        $this->flushSession();
        $this->actingAs($this->student())->get('/concerns/create')->assertOk()
            ->assertSee('value="Canteen"', false)
            ->assertSee(e('Canteen & Food Services'), false);

        // And a student can actually file one, which is the whole point:
        // the server validates against the same list the form offers.
        $this->flushSession();
        $this->actingAs($this->student())->post('/concerns', [
            'category' => 'Canteen',
            'subcategory' => 'Food quality',
            'description' => 'The rice at the canteen was cold again this morning.',
        ])->assertRedirect();

        $concern = Concern::latest('id')->firstOrFail();

        $this->assertSame('Canteen', $concern->category);
        $this->assertSame('Food quality', $concern->subcategory);

        // Routed to the office the admin chose, not to a default.
        $this->assertNotNull($concern->assigned_to, 'a new category still lands on a desk');
        $this->assertSame(
            'General Services',
            optional(optional($concern->assignedUser)->role)->name
        );

        fwrite(STDERR, "  [categories] an added category is offered, filed and routed\n");
    }

    /** A sub-category from another category is still refused. */
    public function test_the_sub_category_must_belong_to_the_new_category(): void
    {
        $this->actingAs($this->admin())->post('/admin/categories', [
            'name' => 'Canteen',
            'label' => 'Canteen & Food Services',
            'routing_role' => 'General Services',
            'subcategories' => "Food quality\nPrices",
        ])->assertRedirect();

        ConcernCategory::forget();

        $this->flushSession();
        $this->actingAs($this->student())->post('/concerns', [
            'category' => 'Canteen',
            // Belongs to Facilities.
            'subcategory' => 'Rooms',
            'description' => 'The rice at the canteen was cold again this morning.',
        ])->assertSessionHasErrors('subcategory');

        fwrite(STDERR, "  [categories] a sub-category from elsewhere is still refused\n");
    }

    /**
     * Retiring one takes it off the form and leaves the concerns alone.
     *
     * This is the half that makes the whole thing safe to use. A category
     * is never deleted: the concerns filed under it match on its name, and
     * staff must still be able to find them.
     */
    public function test_retiring_a_category_hides_it_without_losing_anything(): void
    {
        $filed = Concern::create([
            'user_id' => $this->student()->id,
            'category' => 'Bullying',
            'department' => 'College of Computer Studies',
            'description' => 'Something happened that I want to report.',
            'urgency' => 'Low',
            'status' => 'submitted',
            'is_anonymous' => false,
            'assigned_to' => User::where('email', 'counselor@cspc.edu.ph')->firstOrFail()->id,
        ]);

        $bullying = ConcernCategory::cached()->firstWhere('name', 'Bullying');

        $this->actingAs($this->admin())->post('/admin/categories/'.$bullying->id, [
            'label' => 'Bullying',
            'routing_role' => 'Guidance Counselor',
            // is_active left out entirely: an unticked checkbox sends nothing.
        ])->assertRedirect();

        ConcernCategory::forget();

        // Off the form...
        $this->assertNotContains('Bullying', ConcernCategory::offered());

        $this->flushSession();
        $this->actingAs($this->student())->get('/concerns/create')->assertOk()
            ->assertDontSee('value="Bullying"', false);

        // ...but still a valid stored value, so the concern still loads and
        // staff can still filter for it.
        $this->assertContains('Bullying', ConcernCategory::names());

        $this->flushSession();
        $this->actingAs(User::where('email', 'counselor@cspc.edu.ph')->firstOrFail())
            ->get("/concerns/{$filed->id}")->assertOk()
            ->assertSee('Something happened that I want to report.');

        $this->flushSession();
        $this->actingAs(User::where('email', 'counselor@cspc.edu.ph')->firstOrFail())
            ->get('/concerns?category=Bullying')->assertOk()
            ->assertSee("concerns/{$filed->id}", false);

        fwrite(STDERR, "  [categories] a retired category keeps its concerns readable\n");
    }

    /**
     * Saving a category cannot wipe its sub-categories.
     *
     * They were one textarea holding the whole list. Select-all and a
     * keystroke took all seven of Academic's in a single action, with no
     * warning and no undo, and the form looked exactly as it does when
     * somebody means it. A field that destroys a list by being typed in
     * carelessly should not exist.
     */
    public function test_an_ordinary_save_leaves_the_sub_categories_alone(): void
    {
        $academic = ConcernCategory::cached()->firstWhere('name', 'Academic');
        $before = ConcernCategory::subcategoriesFor('Academic');

        $this->assertNotEmpty($before, 'the fixture needs something to lose');

        // A save that touches only the label -- which is what most saves
        // are, and what the old form could not distinguish from "and
        // delete everything".
        $this->actingAs($this->admin())->post('/admin/categories/'.$academic->id, [
            'label' => 'Academic matters',
            'routing_role' => 'Adviser',
            'is_active' => '1',
        ])->assertRedirect();

        ConcernCategory::forget();

        $this->assertSame($before, ConcernCategory::subcategoriesFor('Academic'));
        $this->assertSame('Academic matters', ConcernCategory::labelFor('Academic'));

        fwrite(STDERR, "  [categories] an ordinary save keeps the sub-categories\n");
    }

    /** Removal happens only when the item is named. */
    public function test_a_sub_category_goes_only_when_it_is_named(): void
    {
        $academic = ConcernCategory::cached()->firstWhere('name', 'Academic');
        $before = ConcernCategory::subcategoriesFor('Academic');
        $doomed = $before[0];

        $this->actingAs($this->admin())->post('/admin/categories/'.$academic->id, [
            'label' => 'Academic',
            'routing_role' => 'Adviser',
            'is_active' => '1',
            'remove' => [$doomed],
            'add' => "Thesis defence\nClearance",
        ])->assertRedirect();

        ConcernCategory::forget();

        $now = ConcernCategory::subcategoriesFor('Academic');

        $this->assertNotContains($doomed, $now, 'the one named goes');
        $this->assertContains('Thesis defence', $now, 'the ones added arrive');
        $this->assertContains('Clearance', $now);

        // And everything else is exactly as it was.
        foreach (array_slice($before, 1) as $kept) {
            $this->assertContains($kept, $now, "{$kept} was not named and must stay");
        }

        fwrite(STDERR, "  [categories] only the named sub-category is removed\n");
    }

    /** Removing one does not touch the concerns filed under it. */
    public function test_removing_a_sub_category_keeps_its_concerns(): void
    {
        $academic = ConcernCategory::cached()->firstWhere('name', 'Academic');
        $doomed = ConcernCategory::subcategoriesFor('Academic')[0];

        $filed = Concern::create([
            'user_id' => $this->student()->id,
            'category' => 'Academic',
            'subcategory' => $doomed,
            'department' => 'College of Computer Studies',
            'description' => 'A concern filed under a sub-category since retired.',
            'urgency' => 'Low',
            'status' => 'submitted',
            'is_anonymous' => false,
            'assigned_to' => User::where('email', 'staff@cspc.edu.ph')->firstOrFail()->id,
        ]);

        $this->actingAs($this->admin())->post('/admin/categories/'.$academic->id, [
            'label' => 'Academic',
            'routing_role' => 'Adviser',
            'is_active' => '1',
            'remove' => [$doomed],
        ])->assertRedirect();

        $this->assertSame($doomed, $filed->fresh()->subcategory, 'the stored value is untouched');

        $this->flushSession();
        $this->actingAs(User::where('email', 'staff@cspc.edu.ph')->firstOrFail())
            ->get("/concerns/{$filed->id}")->assertOk()
            ->assertSee($doomed);

        fwrite(STDERR, "  [categories] a retired sub-category keeps its concerns\n");
    }

    /**
     * A sub-category can name its own office, and the routing follows it.
     *
     * "Instructor conduct" and "Class schedule" are both Academic and are
     * not the same desk's work -- one is a complaint about a person, the
     * other a timetable. Routing by category alone sent both to the class
     * adviser, who referred half of them on by hand.
     */
    public function test_a_sub_category_can_route_somewhere_of_its_own(): void
    {
        $academic = ConcernCategory::cached()->firstWhere('name', 'Academic');

        $this->actingAs($this->admin())->post('/admin/categories/'.$academic->id, [
            'label' => 'Academic',
            'routing_role' => 'Adviser',
            'is_active' => '1',
            'route' => ['Instructor conduct' => 'Dean'],
        ])->assertRedirect();

        ConcernCategory::forget();

        $this->assertSame('Dean', ConcernCategory::routeForSubcategory('Academic', 'Instructor conduct'));
        $this->assertNull(ConcernCategory::routeForSubcategory('Academic', 'Grades'),
            'the ones left alone still follow the category');

        // And a concern filed under it lands on that desk.
        $this->flushSession();
        $this->actingAs($this->student())->post('/concerns', [
            'category' => 'Academic',
            'subcategory' => 'Instructor conduct',
            'description' => 'My instructor said something I want to report properly.',
        ])->assertRedirect();

        $concern = Concern::latest('id')->firstOrFail();

        $this->assertSame('Dean', optional(optional($concern->assignedUser)->role)->name);

        // While an ordinary Academic one still goes where it always did.
        $this->flushSession();
        $this->actingAs($this->student())->post('/concerns', [
            'category' => 'Academic',
            'subcategory' => 'Grades',
            'description' => 'My grade in this subject looks wrong to me.',
        ])->assertRedirect();

        $this->assertNotSame('Dean',
            optional(optional(Concern::latest('id')->firstOrFail()->assignedUser)->role)->name);

        fwrite(STDERR, "  [categories] a sub-category can route to its own office\n");
    }

    /**
     * Renaming a sub-category brings its concerns with it.
     *
     * Unlike a category's name, this one is safe to change: no code
     * matches on it. But the concerns carrying it must move in the same
     * breath, or every one of them fails the check that a sub-category
     * belongs to its category -- the opposite of what renaming is for.
     */
    public function test_renaming_a_sub_category_moves_its_concerns(): void
    {
        $academic = ConcernCategory::cached()->firstWhere('name', 'Academic');

        $filed = Concern::create([
            'user_id' => $this->student()->id,
            'category' => 'Academic',
            'subcategory' => 'Grades',
            'department' => 'College of Computer Studies',
            'description' => 'A concern filed before the rename.',
            'urgency' => 'Low',
            'status' => 'submitted',
            'is_anonymous' => false,
            'assigned_to' => User::where('email', 'staff@cspc.edu.ph')->firstOrFail()->id,
        ]);

        $this->actingAs($this->admin())->post('/admin/categories/'.$academic->id, [
            'label' => 'Academic',
            'routing_role' => 'Adviser',
            'is_active' => '1',
            'rename' => ['Grades' => 'Marks and grading'],
        ])->assertRedirect();

        ConcernCategory::forget();

        $this->assertContains('Marks and grading', ConcernCategory::subcategoriesFor('Academic'));
        $this->assertNotContains('Grades', ConcernCategory::subcategoriesFor('Academic'));

        // The concern came with it.
        $this->assertSame('Marks and grading', $filed->fresh()->subcategory);

        // Which means it still belongs to its category.
        $this->assertTrue(Concern::subcategoryBelongsTo('Academic', 'Marks and grading'));

        fwrite(STDERR, "  [categories] renaming a sub-category moves its concerns\n");
    }

    /** One nobody has used can be deleted outright. */
    public function test_an_unused_category_can_be_deleted(): void
    {
        $this->actingAs($this->admin())->post('/admin/categories', [
            'name' => 'Canteen',
            'label' => 'Canteen & Food Services',
            'routing_role' => 'General Services',
        ])->assertRedirect();

        ConcernCategory::forget();
        $canteen = ConcernCategory::cached()->firstWhere('name', 'Canteen');

        $this->flushSession();
        $this->actingAs($this->admin())
            ->delete('/admin/categories/'.$canteen->id)
            ->assertRedirect();

        ConcernCategory::forget();

        $this->assertNotContains('Canteen', ConcernCategory::names());

        fwrite(STDERR, "  [categories] an unused category can be deleted\n");
    }

    /**
     * One with history behind it cannot.
     *
     * Every concern matches its category by name. Deleting one with
     * concerns under it leaves those rows pointing at nothing: no label,
     * no routing office, and a whole slice of the archive missing from
     * the staff filter.
     */
    public function test_a_category_with_concerns_cannot_be_deleted(): void
    {
        $this->actingAs($this->admin())->post('/admin/categories', [
            'name' => 'Canteen',
            'label' => 'Canteen & Food Services',
            'routing_role' => 'General Services',
        ])->assertRedirect();

        ConcernCategory::forget();
        $canteen = ConcernCategory::cached()->firstWhere('name', 'Canteen');

        Concern::create([
            'user_id' => $this->student()->id,
            'category' => 'Canteen',
            'department' => 'College of Computer Studies',
            'description' => 'The rice at the canteen was cold again this morning.',
            'urgency' => 'Low',
            'status' => 'submitted',
            'is_anonymous' => false,
        ]);

        $this->flushSession();
        $this->actingAs($this->admin())
            ->delete('/admin/categories/'.$canteen->id)
            ->assertRedirect()
            ->assertSessionHas('error');

        ConcernCategory::forget();

        $this->assertContains('Canteen', ConcernCategory::names());

        fwrite(STDERR, "  [categories] one with concerns behind it cannot be deleted\n");
    }

    /**
     * Nor can one the system shipped with.
     *
     * The eleven are seeded from PHP constants, so a deleted one comes
     * back the next time the table is built from scratch. Allowing the
     * click would promise something the system then quietly undoes.
     */
    public function test_a_shipped_category_cannot_be_deleted(): void
    {
        $bullying = ConcernCategory::cached()->firstWhere('name', 'Bullying');

        $this->actingAs($this->admin())
            ->delete('/admin/categories/'.$bullying->id)
            ->assertRedirect()
            ->assertSessionHas('error');

        ConcernCategory::forget();

        $this->assertContains('Bullying', ConcernCategory::names());

        fwrite(STDERR, "  [categories] a shipped category cannot be deleted\n");
    }

    /**
     * The stored name is worked out from the label, not typed twice.
     *
     * The form asked for both -- "Canteen", then "Canteen & Food
     * Services" -- and for the administrator to remember which of the two
     * could be changed afterwards. The distinction is real and it is the
     * system's to keep.
     */
    public function test_the_stored_name_comes_from_the_label(): void
    {
        $this->actingAs($this->admin())->post('/admin/categories', [
            'label' => 'Canteen & Food Services',
            'routing_role' => 'General Services',
        ])->assertRedirect();

        ConcernCategory::forget();

        $this->assertContains('Canteen & Food Services', ConcernCategory::names());

        fwrite(STDERR, "  [categories] the stored name comes from the label\n");
    }

    /** Characters that would misbehave in a URL or a LIKE are stripped out. */
    public function test_an_awkward_label_still_produces_a_safe_stored_name(): void
    {
        $this->actingAs($this->admin())->post('/admin/categories', [
            'label' => 'Canteen (New Building) 100%',
        ])->assertRedirect()->assertSessionHasNoErrors();

        ConcernCategory::forget();

        $stored = collect(ConcernCategory::names())->first(fn ($n) => str_contains($n, 'Canteen'));

        $this->assertNotNull($stored);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9 &\-]+$/', $stored);
        $this->assertStringNotContainsString('%', $stored);
        $this->assertStringNotContainsString('(', $stored);

        // The label keeps what the student should read.
        $this->assertSame('Canteen (New Building) 100%', ConcernCategory::labelFor($stored));

        fwrite(STDERR, "  [categories] an awkward label still stores safely\n");
    }

    /**
     * Two labels reducing to one stored name do not collide.
     *
     * A number is appended rather than the save refused: the
     * administrator did not choose this value and cannot be asked to
     * resolve a clash inside it.
     */
    public function test_two_labels_that_reduce_to_one_name_both_save(): void
    {
        foreach (['Canteen!', 'Canteen?'] as $label) {
            $this->actingAs($this->admin())
                ->post('/admin/categories', ['label' => $label])
                ->assertRedirect()
                ->assertSessionHasNoErrors();

            ConcernCategory::forget();
            $this->flushSession();
        }

        $stored = collect(ConcernCategory::names())->filter(fn ($n) => str_starts_with($n, 'Canteen'));

        $this->assertCount(2, $stored, 'both saved');
        $this->assertCount(2, $stored->unique(), 'under different stored names');

        fwrite(STDERR, "  [categories] two labels reducing to one name both save\n");
    }

    /** Two categories cannot share a stored name. */
    public function test_a_name_cannot_be_used_twice(): void
    {
        $this->actingAs($this->admin())->post('/admin/categories', [
            'name' => 'Academic',
            'label' => 'Something else entirely',
        ])->assertSessionHasErrors('name');

        fwrite(STDERR, "  [categories] a stored name cannot be reused\n");
    }

    /** A name that would misbehave in a URL or a LIKE is refused. */
    public function test_an_awkward_name_is_refused(): void
    {
        foreach (['100% Done', 'A/B', 'Food_&_Drink!'] as $awkward) {
            $this->actingAs($this->admin())->post('/admin/categories', [
                'name' => $awkward,
                'label' => 'Whatever',
            ])->assertSessionHasErrors('name');
        }

        fwrite(STDERR, "  [categories] awkward names are refused\n");
    }

    /** Only an administrator reaches the page at all. */
    public function test_the_page_is_for_administrators(): void
    {
        $this->actingAs($this->student())->get('/admin/categories')->assertForbidden();

        $this->flushSession();
        $this->actingAs($this->student())->post('/admin/categories', [
            'name' => 'Sneaky',
            'label' => 'Sneaky',
        ])->assertForbidden();

        $this->assertNotContains('Sneaky', ConcernCategory::names());

        fwrite(STDERR, "  [categories] only an administrator may add one\n");
    }

    /**
     * The System Admin alone, not both tiers.
     *
     * Managing accounts is shared so the office can keep working while the
     * System Admin is busy. This is not that. A category decides where
     * every future concern of its kind is routed and which office answers
     * for it, and it cannot be deleted afterwards -- the concerns filed
     * under it match on its name. That is a decision for the person who
     * answers for the system, not for whoever is covering the desk.
     */
    public function test_a_staff_admin_may_not_change_the_categories(): void
    {
        $staffAdmin = User::factory()->create([
            'role_id' => Role::where('name', 'Staff Admin')->value('id'),
            'status' => 'approved',
            'department' => 'Academic Affairs',
        ]);

        $this->actingAs($staffAdmin)->get('/admin/categories')->assertForbidden();

        $this->flushSession();
        $this->actingAs($staffAdmin)->post('/admin/categories', [
            'name' => 'Canteen',
            'label' => 'Canteen',
        ])->assertForbidden();

        $this->assertNotContains('Canteen', ConcernCategory::names());

        // They keep everything else on the admin pages.
        $this->flushSession();
        $this->actingAs($staffAdmin)->get('/admin/users')->assertOk();

        fwrite(STDERR, "  [categories] a Staff Admin may not change them\n");
    }
}
