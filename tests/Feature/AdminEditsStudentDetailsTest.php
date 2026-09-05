<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An admin can correct a student's year, class and student number.
 *
 * Role, college and programme were editable; the three things that identify a
 * student in their own year were not. Fixing a mistyped section meant a tinker
 * command, and a wrong section is not cosmetic -- Section::adviserFor() reads
 * it, so a student in the wrong class has their academic concerns routed to
 * somebody else's adviser.
 *
 * Year and class are two dropdowns but ONE stored value: "4A" is what the
 * adviser lookup matches and what the start-of-year promotion increments.
 */
class AdminEditsStudentDetailsTest extends TestCase
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

    private function student(): User
    {
        return User::where('email', 'student@my.cspc.edu.ph')->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'role_id' => Role::where('name', 'Student')->value('id'),
            'department' => 'College of Computer Studies',
            'course' => 'BS Information Technology',
        ], $overrides);
    }

    public function test_the_year_and_class_are_stored_as_one_section(): void
    {
        $student = $this->student();

        $this->actingAs($this->admin())
            ->post(route('admin.users.role', $student), $this->payload([
                'year' => 4,
                'section_letter' => 'B',
                'student_id' => '231002370',
            ]))
            ->assertRedirect();

        $student->refresh();

        $this->assertSame('4B', $student->section);
        $this->assertSame('231002370', $student->student_id);

        fwrite(STDERR, "  [admin] year 4 + class B stored as 4B: YES\n");
    }

    /**
     * Half of a section is not a section. "4" with no class letter matches no
     * row in sections, so the student would drop to college-level routing with
     * nothing on screen saying so.
     */
    public function test_a_year_without_a_class_clears_the_section(): void
    {
        $student = $this->student();
        $student->forceFill(['section' => '3A'])->save();

        $this->actingAs($this->admin())
            ->post(route('admin.users.role', $student), $this->payload([
                'year' => 4,
                'section_letter' => '',
            ]))
            ->assertRedirect();

        $this->assertNull($student->fresh()->section);

        fwrite(STDERR, "  [admin] a year with no class stores nothing, not a half section: YES\n");
    }

    /** Nonsense is refused rather than stored. */
    public function test_an_invalid_class_letter_is_refused(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.users.role', $this->student()), $this->payload([
                'year' => 3,
                'section_letter' => 'AB',
            ]))
            ->assertSessionHasErrors('section_letter');

        fwrite(STDERR, "  [admin] a two-letter class is refused: YES\n");
    }

    /**
     * An account holds a student number or an employee number, never both.
     * They come from different offices, and a leftover student number would
     * return a staff member when an admin searches for a student's id.
     */
    public function test_promoting_a_student_to_staff_swaps_which_number_they_carry(): void
    {
        $student = $this->student();
        $student->forceFill(['student_id' => '231002370', 'section' => '4A'])->save();

        $this->actingAs($this->admin())
            ->post(route('admin.users.role', $student), [
                'role_id' => Role::where('name', 'Instructor')->value('id'),
                'department' => 'College of Computer Studies',
                'employee_id' => '2019-00456',
            ])
            ->assertRedirect();

        $student->refresh();

        $this->assertNull($student->student_id);
        $this->assertSame('2019-00456', $student->employee_id);

        fwrite(STDERR, "  [admin] student number cleared, employee number set: YES\n");
    }

    /** And the other way: a staff member made a student loses the staff one. */
    public function test_an_employee_number_is_cleared_when_somebody_becomes_a_student(): void
    {
        $staff = User::factory()->create([
            'role_id' => Role::where('name', 'Instructor')->value('id'),
            'department' => 'College of Computer Studies',
            'employee_id' => '2019-00456',
            'status' => 'approved',
        ]);

        $this->actingAs($this->admin())
            ->post(route('admin.users.role', $staff), $this->payload([
                'student_id' => '231002370',
                'year' => 1,
                'section_letter' => 'A',
            ]))
            ->assertRedirect();

        $staff->refresh();

        $this->assertNull($staff->employee_id);
        $this->assertSame('231002370', $staff->student_id);

        fwrite(STDERR, "  [admin] employee number cleared on becoming a student: YES\n");
    }

    /** The fields are on the page, and only for a student. */
    public function test_the_fields_are_rendered(): void
    {
        $this->actingAs($this->admin())->get(route('admin.users'))
            ->assertOk()
            ->assertSee('Student ID')
            ->assertSee('name="year"', false)
            ->assertSee('name="section_letter"', false);

        fwrite(STDERR, "  [admin] year, class and student ID appear on the card: YES\n");
    }
}
