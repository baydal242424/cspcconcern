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
                'Instructor',
                optional(optional($concern->assignedUser)->role)->name,
                "{$category} should reach an instructor"
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
        $instructor = User::where('email', 'ccs.instructor@cspc.edu.ph')->firstOrFail();

        foreach (['Physical', 'Safety'] as $category) {
            $c = Concern::create([
                'user_id' => $this->student()->id,
                'category' => $category,
                'department' => 'College of Computer Studies',
                'description' => 'Unclaimed, sitting in the queue.',
                'status' => 'submitted',
                'is_anonymous' => false,
            ]);

            $this->assertTrue(Concern::visibleTo($instructor)->pluck('id')->contains($c->id));
        }

        fwrite(STDERR, "  [queue] both visible to every instructor until claimed\n");
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
}
