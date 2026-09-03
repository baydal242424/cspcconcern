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
 * A student must be able to say the concern is about their own class adviser.
 *
 * This is the one subject the form has to get right. Academic, Physical,
 * Safety and Others route to the class adviser BEFORE anyone else, so a
 * concern about the adviser that cannot name them is delivered to the person
 * it is about. routeConcern() steps past an adviser who is the named subject,
 * but only if the student had some way to name them.
 *
 * They did not. The picker was built from the Instructor role, and advising is
 * not a role -- 14 of the 105 section assignments belong to a Program Chair, a
 * Dean or Faculty/Staff, none of whom appeared in it. Those students could name
 * every instructor in the college except the one person their concern was
 * actually about.
 */
class ReportingYourOwnAdviserTest extends TestCase
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

    /**
     * Makes someone the adviser of the student's own section.
     *
     * The role is a parameter because that is the whole point: an adviser is
     * whoever holds the section, whatever they are otherwise.
     */
    private function adviserOfTheStudentsSection(string $roleName): User
    {
        $student = $this->student();
        $student->update(['course' => 'BSIT', 'section' => '4A']);

        $adviser = User::factory()->create([
            'role_id' => Role::where('name', $roleName)->firstOrFail()->id,
            'department' => $student->department ?: 'College of Computer Studies',
            'status' => 'approved',
        ]);

        Section::create([
            'course' => 'BSIT',
            'section' => '4A',
            'school_year' => '2024-2025',
            'semester' => 'Second',
            'adviser_id' => $adviser->id,
        ]);

        return $adviser;
    }

    /**
     * A Program Chair advising a section is the case that was broken: not an
     * Instructor, so absent from the instructor picker entirely.
     */
    public function test_the_form_names_an_adviser_who_is_not_an_instructor(): void
    {
        $adviser = $this->adviserOfTheStudentsSection('Program Chair');

        $resp = $this->actingAs($this->student()->refresh())->get('/concerns/create');
        $resp->assertOk();

        $this->assertSame(
            $adviser->id,
            optional($resp->viewData('adviser'))->id,
            'The student\'s own class adviser must be offered, whatever role they hold'
        );

        $resp->assertSee('This concern is about my class adviser');
        $resp->assertSee($adviser->name);

        fwrite(STDERR, "  [adviser] a Program Chair adviser is nameable: YES\n");
    }

    /** Nobody should appear twice on one form -- it reads as two people. */
    public function test_the_adviser_is_not_also_listed_among_the_instructors(): void
    {
        $adviser = $this->adviserOfTheStudentsSection('Instructor');

        $resp = $this->actingAs($this->student()->refresh())->get('/concerns/create');

        $this->assertFalse(
            $resp->viewData('instructorsByCollege')->flatten()->contains('id', $adviser->id),
            'The adviser has their own row; they must not also be in the instructor list'
        );

        fwrite(STDERR, "  [adviser] listed once, not twice: YES\n");
    }

    /**
     * The point of naming them: the concern must go somewhere else.
     */
    public function test_a_concern_about_the_adviser_is_not_assigned_to_the_adviser(): void
    {
        $adviser = $this->adviserOfTheStudentsSection('Instructor');
        $student = $this->student()->refresh();

        $this->actingAs($student)->post('/concerns', [
            'category' => 'Academic',
            'description' => 'A concern about the way my own class adviser handled a grade.',
            'about_staff_id' => $adviser->id,
            'is_anonymous' => 0,
        ])->assertRedirect();

        $concern = Concern::where('user_id', $student->id)->latest('id')->firstOrFail();

        $this->assertNotNull($concern->assigned_to, 'It still has to reach somebody');

        $this->assertNotSame(
            $adviser->id,
            $concern->assigned_to,
            'An Academic concern routes to the class adviser first -- when it is ABOUT them, '
            .'it must go past them instead'
        );

        fwrite(STDERR, '  [adviser] concern about the adviser went to '
            .optional($concern->assignedUser)->name." instead: YES\n");
    }

    /** A student with no section recorded simply does not see the row. */
    public function test_no_adviser_row_when_the_section_has_none(): void
    {
        $student = $this->student();
        $student->update(['course' => 'BSIT', 'section' => null]);

        $resp = $this->actingAs($student->refresh())->get('/concerns/create');
        $resp->assertOk();

        $this->assertNull($resp->viewData('adviser'));
        $resp->assertDontSee('This concern is about my class adviser');

        fwrite(STDERR, "  [adviser] hidden when there is no adviser: YES\n");
    }
}
