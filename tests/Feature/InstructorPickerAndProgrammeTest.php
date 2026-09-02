<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\Faculty\CcsFacultySeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Two defects that both followed from splitting Faculty/Staff into Instructor,
 * and both of which failed silently -- no error, just wrong names in a list and
 * a handler quietly preferred over their colleagues.
 */
class InstructorPickerAndProgrammeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, UserSeeder::class, CcsFacultySeeder::class]);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@cspc.edu.ph')->firstOrFail();
    }

    private function student(): User
    {
        return User::where('email', 'student@my.cspc.edu.ph')->firstOrFail();
    }

    /**
     * "Which instructor is this concern about?" must list instructors. It listed
     * office staff instead: the partition still split on 'Faculty/Staff', which
     * after the split means unit heads.
     */
    public function test_the_instructor_picker_lists_instructors(): void
    {
        $instructor = User::where('email', 'jeremyneo@cspc.edu.ph')->firstOrFail();
        $this->assertSame('Instructor', $instructor->role->name);

        $officeStaff = User::where('email', 'mict@cspc.edu.ph')->firstOrFail();
        $this->assertSame('Faculty/Staff', $officeStaff->role->name);

        $resp = $this->actingAs($this->student())->get('/concerns/create');
        $resp->assertOk();

        $byCollege = $resp->viewData('instructorsByCollege')->flatten();
        $other = $resp->viewData('otherStaff');

        $this->assertTrue(
            $byCollege->contains('id', $instructor->id),
            'An Instructor must appear in the instructor picker'
        );
        $this->assertFalse(
            $byCollege->contains('id', $officeStaff->id),
            'A unit head must not appear in the instructor picker'
        );
        $this->assertTrue($other->contains('id', $officeStaff->id));

        fwrite(STDERR, "  [picker] instructors listed as instructors, unit heads as other staff: YES\n");
    }

    /** A newly promoted instructor shows up in the picker straight away. */
    public function test_a_promoted_student_appears_as_an_instructor(): void
    {
        $person = $this->student();
        $this->assertSame('Student', $person->role->name);

        $this->actingAs($this->admin())->post("/admin/users/{$person->id}/role", [
            'role_id' => Role::where('name', 'Instructor')->firstOrFail()->id,
            'department' => 'College of Computer Studies',
        ])->assertRedirect();

        $resp = $this->actingAs(User::where('email', 'student2@my.cspc.edu.ph')->firstOrFail())
            ->get('/concerns/create');

        $this->assertTrue(
            $resp->viewData('instructorsByCollege')->flatten()->contains('id', $person->id),
            'Someone promoted to Instructor should be offered immediately'
        );

        fwrite(STDERR, "  [picker] newly promoted instructor offered immediately: YES\n");
    }

    /**
     * The programme must not survive a move to a role that has none. The picker
     * is hidden for other roles, but a hidden field still posts its value.
     */
    public function test_promoting_a_student_clears_their_programme(): void
    {
        $person = $this->student();
        $this->assertNotNull($person->course, 'The seeded student should have a programme');

        $this->actingAs($this->admin())->post("/admin/users/{$person->id}/role", [
            'role_id' => Role::where('name', 'Instructor')->firstOrFail()->id,
            'department' => 'College of Computer Studies',
            // Exactly what a hidden select still submits.
            'course' => $person->course,
        ])->assertRedirect();

        $person->refresh();
        $this->assertSame('Instructor', $person->role->name);
        $this->assertNull($person->course, 'An Instructor must not carry a programme');

        fwrite(STDERR, "  [programme] cleared on promotion, even though the form posted it: YES\n");
    }

    /** A Program Chair still keeps theirs -- that is the role it exists for. */
    public function test_a_program_chair_keeps_their_programme(): void
    {
        $person = User::where('email', 'jeremyneo@cspc.edu.ph')->firstOrFail();

        $this->actingAs($this->admin())->post("/admin/users/{$person->id}/role", [
            'role_id' => Role::where('name', 'Program Chair')->firstOrFail()->id,
            'department' => 'College of Computer Studies',
            'course' => 'BS Information Systems',
        ])->assertRedirect();

        $person->refresh();
        $this->assertSame('Program Chair', $person->role->name);
        $this->assertSame('BS Information Systems', $person->course);

        fwrite(STDERR, "  [programme] a chair keeps theirs: YES\n");
    }

    /** A student keeps theirs too. */
    public function test_a_student_keeps_their_programme(): void
    {
        $person = $this->student();
        $course = $person->course;

        $this->actingAs($this->admin())->post("/admin/users/{$person->id}/role", [
            'role_id' => $person->role_id,
            'department' => $person->department,
            'course' => $course,
        ])->assertRedirect();

        $this->assertSame($course, $person->refresh()->course);

        fwrite(STDERR, "  [programme] a student keeps theirs: YES\n");
    }
}
