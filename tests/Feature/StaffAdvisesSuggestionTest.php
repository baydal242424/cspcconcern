<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Section;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * A staff member names the class they advise at sign-up; an administrator
 * decides whether they do.
 *
 * The suggestion routes nothing on its own. It is written to neither the
 * sections table nor the account's programme, so a class's concerns reach the
 * person only once Add class on Manage Users confirms it.
 */
class StaffAdvisesSuggestionTest extends TestCase
{
    use RefreshDatabase;

    private const CCS = 'College of Computer Studies';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, UserSeeder::class]);
    }

    private function newStaff(): User
    {
        return User::create([
            'name' => 'New Staff Member',
            'email' => 'new.staff.advises@cspc.edu.ph',
            'password' => Hash::make('not-used'),
            'role_id' => Role::where('name', 'Faculty/Staff')->firstOrFail()->id,
            'google_id' => 'google-new-staff-advises',
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);
    }

    private function signUp(User $staff, array $fields)
    {
        return $this->actingAs($staff)->post(route('profile.complete.post'), $fields + [
            'requested_role_id' => Role::where('name', 'Instructor')->value('id'),
            'department' => self::CCS,
        ]);
    }

    public function test_the_class_is_saved_as_a_suggestion_and_routes_nothing(): void
    {
        $staff = $this->newStaff();

        $this->signUp($staff, [
            'advises_course' => 'BS Information Systems',
            'advises_year' => '4',
            'advises_letter' => 'A',
        ])->assertSessionHasNoErrors();

        $staff->refresh();

        $this->assertSame('BS Information Systems|4A', $staff->advises_request);
        $this->assertNull($staff->course, 'not written to their programme');
        $this->assertNull(Section::adviserFor('BS Information Systems', '4A'), 'not an adviser until confirmed');

        fwrite(STDERR, "  [advises] named at sign-up -> a suggestion only, no routing\n");
    }

    public function test_all_three_or_none(): void
    {
        $staff = $this->newStaff();

        $this->signUp($staff, [
            'advises_course' => 'BS Information Systems',
            'advises_year' => '4',
        ])->assertSessionHasErrors('advises_course');

        $this->assertNull($staff->fresh()->advises_request);

        $this->signUp($staff, [])->assertSessionHasNoErrors();
        $this->assertNull($staff->fresh()->advises_request, 'leaving all three empty is fine');

        fwrite(STDERR, "  [advises] half a class is refused, none at all is fine\n");
    }

    public function test_a_year_the_program_does_not_have_is_refused(): void
    {
        $staff = $this->newStaff();

        $this->signUp($staff, [
            'advises_course' => 'BS Information Systems',
            'advises_year' => '5',
            'advises_letter' => 'A',
        ])->assertSessionHasErrors('advises_year');

        fwrite(STDERR, "  [advises] year 5 of a four-year program is refused\n");
    }

    /** The admin sees it, Add class comes pre-filled, and confirming clears it. */
    public function test_the_admin_confirms_it_with_add_class(): void
    {
        $staff = $this->newStaff();
        $this->signUp($staff, [
            'advises_course' => 'BS Information Systems',
            'advises_year' => '4',
            'advises_letter' => 'A',
        ]);

        $admin = User::where('email', 'admin@cspc.edu.ph')->firstOrFail();

        $html = $this->actingAs($admin)->get('/admin/users')
            ->assertOk()
            ->assertSee('Says they advise', false)
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/id="adv-course-'.$staff->id.'"[\s\S]*?<option value="BS Information Systems"\s+selected>/',
            $html,
            'Add class comes pre-filled with the program'
        );

        $this->actingAs($admin)->post(route('admin.users.sections.assign', $staff), [
            'course' => 'BS Information Systems',
            'year' => '4',
            'section_letter' => 'A',
        ])->assertSessionHasNoErrors();

        $this->assertSame($staff->id, optional(Section::adviserFor('BS Information Systems', '4A'))->id);
        $this->assertNull($staff->fresh()->advises_request, 'the suggestion is cleared once confirmed');

        fwrite(STDERR, "  [advises] admin sees it pre-filled, Add class confirms it and clears the suggestion\n");
    }
}
