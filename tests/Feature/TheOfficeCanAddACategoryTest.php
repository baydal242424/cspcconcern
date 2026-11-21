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
        $this->assertSame(Concern::CATEGORIES, ConcernCategory::names());

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
            'subcategories' => implode("\n", $bullying->subcategories ?? []),
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
