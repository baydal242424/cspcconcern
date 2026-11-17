<?php

namespace Tests\Feature;

use App\Http\Controllers\ConcernController;
use App\Models\Concern;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Equipment is the ICT Unit's; Facilities stays with General Services.
 *
 * The two categories shared one office, on the reasoning that a student
 * reporting a broken anything should not have to decide which maintenance
 * office owns it. In practice they are different work -- a dead lab PC, a
 * projector that shows nothing, a printer out of toner against a broken
 * aircon, a blocked fire exit, a classroom with no water -- and one person
 * was receiving both queues.
 *
 * Splitting a category off a shared role is the sort of change that is
 * made in six places and remembered in five. These check the places.
 */
class EquipmentBelongsToIctTest extends TestCase
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

    private function file(string $category): Concern
    {
        $this->actingAs($this->student())->post('/concerns', [
            'category' => $category,
            'subcategory' => $category === 'Equipment' ? 'Computers' : 'Rooms',
            'description' => 'Something is broken and has been for a while now.',
        ]);

        return Concern::latest('id')->firstOrFail();
    }

    public function test_the_role_exists(): void
    {
        $this->assertTrue(
            Role::where('name', 'ICT Unit')->exists(),
            'the migration should have created the role'
        );
    }

    /** Somebody has to hold it, or an Equipment concern escalates away. */
    public function test_somebody_holds_the_role(): void
    {
        $holder = User::whereHas('role', fn ($q) => $q->where('name', 'ICT Unit'))
            ->where('status', 'approved')
            ->first();

        $this->assertNotNull($holder, 'a category routing to an empty role escalates silently');
    }

    public function test_an_equipment_concern_reaches_the_ict_unit(): void
    {
        $concern = $this->file('Equipment');

        $this->assertSame(
            'ICT Unit',
            optional(optional(User::find($concern->assigned_to))->role)->name
        );
    }

    /** And Facilities does not move with it. */
    public function test_a_facilities_concern_still_reaches_general_services(): void
    {
        $concern = $this->file('Facilities');

        $this->assertSame(
            'General Services',
            optional(optional(User::find($concern->assigned_to))->role)->name
        );
    }

    /**
     * The role counts as staff everywhere a role has to.
     *
     * Three lists decide this, and a role missing from any one of them is
     * an office that half exists: it receives concerns but cannot be named
     * on one, or survives an operation meant to cover every employee.
     */
    public function test_the_role_counts_as_staff_in_every_list(): void
    {
        $this->assertContains('ICT Unit', User::EMPLOYEE_ROLES);
        $this->assertContains('ICT Unit', ConcernController::REFERRAL_ROLES);

        $holder = User::whereHas('role', fn ($q) => $q->where('name', 'ICT Unit'))->firstOrFail();
        $this->assertTrue($holder->isEmployee(), 'the holder must count as an employee');
    }

    /** It is offered as a destination for the category it now owns. */
    public function test_it_is_offered_when_referring_an_equipment_concern(): void
    {
        $this->assertContains(
            'ICT Unit',
            ConcernController::CATEGORY_REFERRAL_ROLES['Equipment']
        );
    }

    /** The person holding it can read what lands on their desk. */
    public function test_the_holder_can_read_what_reaches_them(): void
    {
        $concern = $this->file('Equipment');
        $holder = User::findOrFail($concern->assigned_to);

        $this->assertTrue(
            Concern::whereKey($concern->id)->visibleTo($holder)->exists(),
            'a concern assigned to somebody who cannot open it is worse than an unassigned one'
        );

        $this->actingAs($holder)->get("/concerns/{$concern->id}")->assertOk();
    }

    /** And nothing else. Owning a category is not a standing window. */
    public function test_the_holder_reads_nothing_they_were_not_given(): void
    {
        $somebodyElses = $this->file('Facilities');

        $holder = User::whereHas('role', fn ($q) => $q->where('name', 'ICT Unit'))->firstOrFail();

        $this->assertFalse(
            Concern::whereKey($somebodyElses->id)->visibleTo($holder)->exists()
        );
    }
}
