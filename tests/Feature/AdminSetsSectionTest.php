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
 * An admin corrects a section from Manage Users by picking a year and a
 * section letter, instead of living with whatever was typed at sign-up.
 *
 * The section is what finds a student's class adviser, so a slip there is not
 * cosmetic: "3B" typed as "3A" sends their academic concerns to another
 * class's adviser, with nothing on screen to say so.
 */
class AdminSetsSectionTest extends TestCase
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

    private function role(string $name): Role
    {
        return Role::where('name', $name)->firstOrFail();
    }

    private function student(string $course = 'BS Information Technology', string $college = 'College of Computer Studies'): User
    {
        $student = User::where('email', 'student@my.cspc.edu.ph')->firstOrFail();

        $student->forceFill([
            'role_id' => $this->role('Student')->id,
            'department' => $college,
            'course' => $course,
            'section' => '1A',
        ])->save();

        return $student;
    }

    /** Posts the card's form the way the page does: every field, with overrides. */
    private function update(User $person, array $fields)
    {
        return $this->actingAs($this->admin())
            ->from('/admin/users')
            ->post("/admin/users/{$person->id}/role", $fields + [
                'role_id' => $person->role_id,
                'department' => $person->department,
                'course' => $person->course,
            ]);
    }

    public function test_admin_can_correct_a_students_section(): void
    {
        $student = $this->student();

        $this->update($student, ['year' => '3', 'section_letter' => 'B'])
            ->assertRedirect('/admin/users')
            ->assertSessionHasNoErrors();

        $this->assertSame('3B', $student->fresh()->section);

        fwrite(STDERR, "  [section] 1A corrected to 3B from the two dropdowns: YES\n");
    }

    /** Half a section is refused, and the one on record is kept. */
    public function test_a_year_without_a_section_is_refused(): void
    {
        $student = $this->student();

        $this->update($student, ['year' => '3', 'section_letter' => ''])
            ->assertSessionHasErrors('section_letter');

        $this->assertSame('1A', $student->fresh()->section);

        fwrite(STDERR, "  [section] year without a letter refused, 1A kept: YES\n");
    }

    /** No fifth year in a four-year programme -- but Architecture has one. */
    public function test_a_year_the_programme_does_not_have_is_refused(): void
    {
        $it = $this->student();

        $this->update($it, ['year' => '5', 'section_letter' => 'A'])
            ->assertSessionHasErrors('year');

        $this->assertSame('1A', $it->fresh()->section);

        $architecture = $this->student('BS Architecture', 'College of Engineering and Architecture');

        $this->update($architecture, ['year' => '5', 'section_letter' => 'A'])
            ->assertSessionHasNoErrors();

        $this->assertSame('5A', $architecture->fresh()->section);

        fwrite(STDERR, "  [section] BSIT 5A refused, Architecture 5A accepted: YES\n");
    }

    /** A hand-crafted post cannot slip a typed value past the dropdown. */
    public function test_a_section_not_in_the_list_is_refused(): void
    {
        $student = $this->student();

        $this->update($student, ['year' => '3', 'section_letter' => 'BSIT'])
            ->assertSessionHasErrors('section_letter');

        $this->assertSame('1A', $student->fresh()->section);

        fwrite(STDERR, "  [section] 'BSIT' as a section letter refused: YES\n");
    }

    /** The section an instructor advises is corrected the same way. */
    public function test_an_instructors_section_can_be_corrected(): void
    {
        $instructor = User::forceCreate([
            'name' => 'Test Instructor',
            'email' => 'test.instructor@cspc.edu.ph',
            'password' => Hash::make('unused'),
            'role_id' => $this->role('Instructor')->id,
            'department' => 'College of Computer Studies',
            'section' => '2A',
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);

        $this->update($instructor, ['course' => '', 'year' => '2', 'section_letter' => 'C'])
            ->assertSessionHasNoErrors();

        $this->assertSame('2C', $instructor->fresh()->section);

        fwrite(STDERR, "  [section] instructor's 2A corrected to 2C: YES\n");
    }

    /** A dean has no class, so a section does not follow a student into the role. */
    public function test_a_role_without_a_section_clears_it(): void
    {
        $student = $this->student();

        $this->update($student, [
            'role_id' => $this->role('Dean')->id,
            'course' => '',
            'year' => '1',
            'section_letter' => 'A',
        ])->assertSessionHasNoErrors();

        $this->assertNull($student->fresh()->section);

        fwrite(STDERR, "  [section] cleared when the role carries none: YES\n");
    }

    /** A post without the pickers -- a script, an older form -- leaves it alone. */
    public function test_a_role_only_update_leaves_the_section_alone(): void
    {
        $student = $this->student();

        $this->actingAs($this->admin())
            ->post("/admin/users/{$student->id}/role", ['role_id' => $student->role_id])
            ->assertRedirect();

        $this->assertSame('1A', $student->fresh()->section);

        fwrite(STDERR, "  [safety] role-only update kept the section: YES\n");
    }

    /** The pickers are on the page, and a refused update says why. */
    public function test_the_page_offers_the_pickers_and_explains_a_refusal(): void
    {
        $student = $this->student();

        $this->actingAs($this->admin())->get('/admin/users')
            ->assertOk()
            ->assertSee('name="year"', false)
            ->assertSee('name="section_letter"', false);

        $this->update($student, ['year' => '3', 'section_letter' => '']);

        $this->get('/admin/users')->assertSee('Choose both a year and a section');

        fwrite(STDERR, "  [ui] year and section pickers rendered, refusal shown: YES\n");
    }
}
