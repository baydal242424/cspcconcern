<?php

namespace Tests\Feature;

use App\Models\Concern;
use App\Models\Role;
use App\Models\Section;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An admin puts a staff member in charge of classes -- more than one.
 *
 * This is why advising is a table and not a field. users.section holds a
 * single string, so it could answer "which class?" and never "which classes?"
 * -- and three sections each is normal here, not an edge case.
 *
 * Section::adviserFor() reads the same rows, so assigning a class here is what
 * decides where that class's Academic, Physical, Safety and Others concerns go.
 */
class AdminAssignsAdvisedSectionsTest extends TestCase
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

    private function instructor(string $email = 'teacher@cspc.edu.ph'): User
    {
        return User::factory()->create([
            'name' => 'Test Instructor',
            'email' => $email,
            'role_id' => Role::where('name', 'Instructor')->value('id'),
            'department' => 'College of Computer Studies',
            'status' => 'approved',
        ]);
    }

    private function assign(User $staff, string $course, int $year, string $letter)
    {
        return $this->actingAs($this->admin())
            ->post(route('admin.users.sections.assign', $staff), [
                'course' => $course,
                'year' => $year,
                'section_letter' => $letter,
            ]);
    }

    /** The whole point: one person, several classes. */
    public function test_an_instructor_can_advise_more_than_one_section(): void
    {
        $teacher = $this->instructor();

        $this->assign($teacher, 'BS Information Technology', 3, 'A')->assertRedirect();
        $this->assign($teacher, 'BS Information Technology', 3, 'B')->assertRedirect();
        $this->assign($teacher, 'BS Computer Science', 1, 'A')->assertRedirect();

        $advised = $teacher->advisedSections()->get();

        $this->assertCount(3, $advised);
        $this->assertEqualsCanonicalizing(
            ['3A', '3B', '1A'],
            $advised->pluck('section')->all()
        );

        fwrite(STDERR, "  [advises] one instructor, three classes: YES\n");
    }

    /** And each of those classes routes to them. */
    public function test_every_advised_class_routes_to_them(): void
    {
        $teacher = $this->instructor();

        $this->assign($teacher, 'BS Information Technology', 3, 'A');
        $this->assign($teacher, 'BS Information Technology', 4, 'C');

        foreach ([['3A'], ['4C']] as [$section]) {
            $found = Section::adviserFor('BS Information Technology', $section);

            $this->assertSame(
                $teacher->id,
                optional($found)->id,
                "BSIT {$section} should reach its adviser"
            );
        }

        fwrite(STDERR, "  [advises] both classes reach the same adviser: YES\n");
    }

    /**
     * Handovers happen mid-year. Assigning a class that already has somebody
     * replaces them rather than failing, or an admin would be deleting rows by
     * hand to do something ordinary.
     */
    public function test_assigning_an_advised_class_replaces_the_previous_adviser(): void
    {
        $first = $this->instructor('first@cspc.edu.ph');
        $second = $this->instructor('second@cspc.edu.ph');

        $this->assign($first, 'BS Information Systems', 2, 'A');
        $this->assign($second, 'BS Information Systems', 2, 'A');

        $this->assertCount(0, $first->advisedSections()->get());
        $this->assertCount(1, $second->advisedSections()->get());

        $this->assertSame(
            $second->id,
            optional(Section::adviserFor('BS Information Systems', '2A'))->id
        );

        fwrite(STDERR, "  [advises] reassigning a class hands it over: YES\n");
    }

    /**
     * Removing clears the adviser but keeps the class. The students are still
     * in it -- deleting the row would stop their concerns matching a section
     * at all, rather than falling back to the college.
     */
    public function test_removing_a_class_keeps_the_class(): void
    {
        $teacher = $this->instructor();
        $this->assign($teacher, 'BS Information Technology', 3, 'A');

        $section = $teacher->advisedSections()->firstOrFail();

        $this->actingAs($this->admin())
            ->delete(route('admin.users.sections.unassign', [$teacher, $section]))
            ->assertRedirect();

        $this->assertCount(0, $teacher->advisedSections()->get());
        $this->assertNotNull(Section::find($section->id), 'the class itself survives');
        $this->assertNull(Section::find($section->id)->adviser_id);

        fwrite(STDERR, "  [advises] removing an adviser leaves the class in place: YES\n");
    }

    /** A student is IN a class, not in charge of one. */
    public function test_a_student_cannot_be_made_an_adviser(): void
    {
        $student = User::where('email', 'student@my.cspc.edu.ph')->firstOrFail();

        $this->assign($student, 'BS Information Technology', 3, 'A')
            ->assertSessionHas('error');

        $this->assertCount(0, $student->advisedSections()->get());

        fwrite(STDERR, "  [advises] a student cannot be assigned a class: YES\n");
    }

    /** New assignments land in the term the existing rows use. */
    public function test_a_new_assignment_joins_the_current_term(): void
    {
        Section::create([
            'course' => 'BS Information Technology',
            'section' => '1A',
            'school_year' => '2025-2026',
            'semester' => 'First',
            'adviser_id' => null,
        ]);

        $teacher = $this->instructor();
        $this->assign($teacher, 'BS Information Technology', 2, 'A');

        $added = $teacher->advisedSections()->firstOrFail();

        $this->assertSame('2025-2026', $added->school_year);
        $this->assertSame('First', $added->semester);

        fwrite(STDERR, "  [advises] a new class joins the newest term on record: YES\n");
    }

    /** The panel is on staff cards, and not on a student's. */
    public function test_the_panel_shows_for_staff_only(): void
    {
        $this->instructor();

        $page = $this->actingAs($this->admin())->get(route('admin.users'));

        $page->assertOk();
        $page->assertSee('Advises');
        $page->assertSee('Add class');

        fwrite(STDERR, "  [advises] the panel appears on staff cards: YES\n");
    }
}
