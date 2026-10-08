<?php

namespace Tests\Feature;

use App\Models\Concern;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Physical and Safety are separate labels reaching the same place, and Others
 * now has to say what it is.
 */
class CategorySplitAndOtherDetailTest extends TestCase
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

    private function submit(array $data): Concern
    {
        $this->actingAs($this->student())->post('/concerns', array_merge([
            'description' => 'A description long enough to satisfy the minimum length rule.',
            'is_anonymous' => 0,
        ], $data));

        return Concern::latest('id')->firstOrFail();
    }

    /** Both halves reach an instructor, exactly as the combined label did. */
    public function test_physical_and_safety_route_the_same_way(): void
    {
        foreach (['Physical', 'Safety'] as $category) {
            $concern = $this->submit(['category' => $category]);

            $this->assertSame($category, $concern->category);
            $this->assertSame(
                'Guidance Counselor',
                optional(optional($concern->assignedUser)->role)->name,
                "{$category} should reach Guidance"
            );

            fwrite(STDERR, "  [route] {$category} -> ".optional($concern->assignedUser)->name.PHP_EOL);
        }
    }

    /** And both are still graded High, as the combined label was. */
    public function test_both_are_still_graded_high(): void
    {
        foreach (['Physical', 'Safety'] as $category) {
            $this->assertSame('High', $this->submit(['category' => $category])->urgency);
        }

        fwrite(STDERR, "  [urgency] Physical and Safety both High\n");
    }

    /** Both sit in the shared teaching queue. */
    public function test_both_appear_in_the_open_teaching_queue(): void
    {
        // Physical and Safety left the teaching queue when they stopped
        // routing to the class adviser. An adviser has no standing claim on
        // an injury or a hazard any more; Guidance does.
        $adviser = User::create([
            'name' => 'Queue Adviser',
            'email' => 'queue.adviser@cspc.edu.ph',
            'password' => \Illuminate\Support\Facades\Hash::make('not-used'),
            'role_id' => \App\Models\Role::where('name', 'Adviser')->firstOrFail()->id,
            'department' => 'College of Computer Studies',
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);

        foreach (['Physical', 'Safety'] as $category) {
            $c = Concern::create([
                'user_id' => $this->student()->id,
                'category' => $category,
                'department' => 'College of Computer Studies',
                'description' => 'Unclaimed, sitting in the queue.',
                'status' => 'submitted',
                'is_anonymous' => false,
            ]);

            $this->assertFalse(
                Concern::visibleTo($adviser)->pluck('id')->contains($c->id),
                "{$category} is Guidance's work now, not the adviser's"
            );
        }

        // What the adviser does still hold a standing claim on.
        $academic = Concern::create([
            'user_id' => $this->student()->id,
            'category' => 'Academic',
            'department' => 'College of Computer Studies',
            'description' => 'Unclaimed, sitting in the queue.',
            'status' => 'submitted',
            'is_anonymous' => false,
        ]);

        $this->assertTrue(Concern::visibleTo($adviser)->pluck('id')->contains($academic->id));

        fwrite(STDERR, "  [queue] injury and hazard left the adviser queue; Academic stayed\n");
    }

    /** Others cannot be filed without saying what it is. */
    public function test_others_requires_a_label(): void
    {
        $this->actingAs($this->student())->post('/concerns', [
            'category' => 'Others',
            'description' => 'Something that does not fit any of the categories offered.',
            'is_anonymous' => 0,
        ])->assertSessionHasErrors('other_category');

        $this->assertSame(0, Concern::where('category', 'Others')->count());

        fwrite(STDERR, "  [others] refused without a label\n");
    }

    /** With one, it is stored and shown beside the category. */
    public function test_the_label_is_stored_and_displayed(): void
    {
        $concern = $this->submit([
            'category' => 'Others',
            'other_category' => 'Lost locker key',
        ]);

        $this->assertSame('Lost locker key', $concern->other_category);

        $resp = $this->actingAs($this->student())->get("/concerns/{$concern->id}");
        $resp->assertOk();
        $resp->assertSee('Lost locker key');

        fwrite(STDERR, "  [others] 'Lost locker key' stored and shown beside the category\n");
    }

    /** Every other category ignores the field, even if one is posted. */
    public function test_other_categories_do_not_keep_a_label(): void
    {
        $concern = $this->submit([
            'category' => 'Academic',
            'other_category' => 'should not be kept',
        ]);

        $this->assertSame('Academic', $concern->category);
        $this->assertNotSame('should not be kept', $concern->other_category);

        fwrite(STDERR, "  [others] label ignored on a category that names itself\n");
    }

    /**
     * The student reads "Administrator"; the row still says "Administrative".
     *
     * The stored value is a contract -- routing, urgency grading and every
     * visibility rule match it by name -- so renaming it for the sake of the
     * wording would have meant migrating every concern that carries it and
     * touching twenty call sites. Only the label changed, the same way
     * 'closed_no_action' is stored and "Closed" is shown.
     */
    public function test_the_vague_categories_are_renamed_without_changing_what_is_stored(): void
    {
        $page = $this->actingAs($this->student())->get('/concerns/create');

        $page->assertOk();

        // Several stored names were too vague to choose between: "Physical"
        // could be a fight, a disability or a broken wall, and "Facilities"
        // and "Equipment" both sound like the home for a dead lab computer.
        $renamed = [
            'Administrative' => 'System Problem',
            'Personal' => 'Personal Problem',
            'Physical' => 'Physical Injury',
            'Safety' => 'Safety Hazard',
            'Facilities' => 'Building & Facilities',
            'Equipment' => 'Equipment & Devices',
        ];

        foreach ($renamed as $stored => $shown) {
            $page->assertSee('value="'.$stored.'"', false);

            // e(): "Building & Facilities" reaches the page as
            // "Building &amp; Facilities", so the needle has to be escaped too.
            $page->assertSee(e($shown), false);

            $this->assertSame($shown, Concern::categoryLabel($stored));
        }

        // And a filed one keeps the stored value while displaying the label.
        $this->actingAs($this->student())->post('/concerns', [
            'category' => 'Administrative',
            'description' => 'I need a copy of my registration record for a scholarship.',
        ]);

        $concern = Concern::latest('id')->firstOrFail();

        $this->assertSame('Administrative', $concern->category, 'the stored value is the contract');
        $this->assertSame('System Problem', $concern->category_label);

        fwrite(STDERR, "  [label] vague categories renamed on screen, stored values untouched: YES\n");
    }

    /**
     * The staff picker narrows to the people with a part in the category.
     *
     * An Academic concern climbs one ladder -- the chair of the student's
     * programme, their college's dean, then the VPAA -- plus their own
     * college's office staff. Offering Legal Affairs or Gender and Development
     * there invites a student to name somebody with no part in it: the concern
     * still goes to the chair, and a name is attached to a case it has nothing
     * to do with.
     *
     * The narrowing itself runs in the browser and was driven there. This
     * pins what the page has to hand it: a role and an office on every person,
     * and the rule to apply.
     */
    public function test_the_staff_picker_carries_what_it_needs_to_narrow_by_category(): void
    {
        $page = $this->actingAs($this->student())->get('/concerns/create')->assertOk();

        // Every person is tagged with both, or the rule has nothing to read.
        $page->assertSee('class="person" data-role=', false)
            ->assertSee('data-office=', false);

        // And the rule itself, with the academic ladder named.
        $page->assertSee("'Academic': ['Program Chair', 'Dean', 'Vice President for Academic Affairs']", false)
            ->assertSee('const OWN_COLLEGE', false)
            ->assertSee('function narrowStaffPicker', false);

        fwrite(STDERR, "  [picker] each person carries a role and an office, and the category rule is on the page\n");
    }

    /** The dropdown also says what each one covers, before you have to pick. */
    public function test_the_dropdown_says_what_each_category_covers(): void
    {
        $page = $this->actingAs($this->student())->get('/concerns/create')->assertOk();

        $page->assertSee('System Problem — this website itself — a page or button that will not work', false)
            ->assertSee('Safety Hazard — a hazard that has not caused harm yet', false);

        // Every category carries one, so none is left as a bare word.
        foreach (Concern::CATEGORIES as $category) {
            $this->assertArrayHasKey($category, Concern::CATEGORY_HINTS, "{$category} has no clarifier");
        }

        fwrite(STDERR, "  [label] each option says what it covers, before it is chosen: YES\n");
    }
}
