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
     * The staff picker is the student's own college, plus the central offices.
     *
     * A BSIS student cannot be taught, handled or answered by the dean of
     * another college; those names were a screenful of noise in front of their
     * own. Offices that serve the whole school are a different matter -- any
     * student may need to report the Guidance Office or Records -- so those
     * stay whoever is looking.
     */
    public function test_the_staff_picker_holds_their_own_college_and_the_central_offices(): void
    {
        $student = $this->student();
        $student->forceFill(['department' => 'College of Computer Studies'])->save();

        $ownDean = User::where('email', 'ccs@cspc.edu.ph')->firstOrFail();

        $foreignDean = User::create([
            'name' => 'Dean of Health Sciences',
            'email' => 'chs.picker@cspc.edu.ph',
            'password' => \Illuminate\Support\Facades\Hash::make('not-used'),
            'role_id' => \App\Models\Role::where('name', 'Dean')->firstOrFail()->id,
            'department' => 'College of Health Sciences',
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);

        $office = User::create([
            'name' => 'The Guidance Office',
            'email' => 'guidance.picker@cspc.edu.ph',
            'password' => \Illuminate\Support\Facades\Hash::make('not-used'),
            'role_id' => \App\Models\Role::where('name', 'Guidance Counselor')->firstOrFail()->id,
            'department' => 'Guidance Office',
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);

        $offered = $this->actingAs($student->refresh())->get('/concerns/create')
            ->assertOk()
            ->viewData('otherStaffByOffice')
            ->flatten()
            ->pluck('id');

        $this->assertTrue($offered->contains($ownDean->id), 'their own dean');
        $this->assertTrue($offered->contains($office->id), 'a central office');
        $this->assertFalse($offered->contains($foreignDean->id), 'never another college');

        fwrite(STDERR, "  [picker] own college + central offices, no other college: YES\n");
    }

    /**
     * The instructor picker has been removed from the form.
     *
     * It offered every teacher so a student could name the one a concern was
     * about. What has to hold now is that they are not quietly offered
     * somewhere else instead: the staff picker is for deans, program chairs,
     * counselors and offices, and an instructor falling into it would put the
     * whole list back on the page under another heading.
     */
    public function test_instructors_are_not_offered_on_the_form(): void
    {
        $instructor = User::where('email', 'jeremyneo@cspc.edu.ph')->firstOrFail();
        $this->assertSame('Instructor', $instructor->role->name);

        $officeStaff = User::where('email', 'mict@cspc.edu.ph')->firstOrFail();
        $this->assertSame('Faculty/Staff', $officeStaff->role->name);

        $resp = $this->actingAs($this->student())->get('/concerns/create');
        $resp->assertOk();

        // The view data behind the picker is gone entirely.
        $this->assertArrayNotHasKey('instructorsByCollege', $resp->viewData());

        $other = $resp->viewData('otherStaffByOffice')->flatten();

        $this->assertFalse(
            $other->contains('id', $instructor->id),
            'An instructor must not reappear in the staff picker'
        );

        // The office staff the picker is actually for are still there.
        $this->assertTrue($other->contains('id', $officeStaff->id));

        $resp->assertDontSee('This concern is about a specific instructor');
        $resp->assertDontSee($instructor->name);

        fwrite(STDERR, "  [picker] instructors are offered nowhere on the form: YES\n");
    }

    /**
     * The office picker has to say which office.
     *
     * It was a flat list of "Name -- Role", and the commonest role there is
     * "Faculty/Staff", which names no office at all: the ICT Unit, Records,
     * Health Services and half a dozen colleges all read the same. A student
     * naming the person their concern is about could not tell who they were
     * pointing at -- and the whole purpose of that field is to route the
     * concern AWAY from the person named, so picking the wrong one sends it to
     * the very office it should be kept from.
     *
     * Worst case in the real data: one lawyer heads both Human Rights
     * Education and the Legal Affairs Office under two accounts, so two
     * consecutive rows were identical but for a role name.
     */
    public function test_the_office_picker_says_which_office_each_person_belongs_to(): void
    {
        $officeStaff = User::where('email', 'mict@cspc.edu.ph')->firstOrFail();
        $this->assertNotEmpty($officeStaff->department);

        $resp = $this->actingAs($this->student())->get('/concerns/create');
        $resp->assertOk();

        $grouped = $resp->viewData('otherStaffByOffice');

        $this->assertTrue(
            $grouped->has($officeStaff->department),
            'The picker must be grouped by office, not presented as one flat list'
        );

        $this->assertTrue(
            $grouped->get($officeStaff->department)->contains('id', $officeStaff->id),
            'A member of an office must be listed under that office'
        );

        // And it must reach the page, not just the view data. The picker is a
        // list of checkboxes rather than a <select multiple>, because naming
        // two people in one of those needs a Ctrl key and most students file
        // from a phone.
        $resp->assertSee('<p class="people-group">'.e($officeStaff->department).'</p>', false);

        fwrite(STDERR, "  [picker] office staff grouped under their office: YES\n");
    }

    /**
     * Promotion must not be a way back onto the form.
     *
     * A promoted student holds the Instructor role, and the staff picker is
     * built by rejecting that role -- so the moment the promotion lands they
     * drop out of the form, rather than appearing in the office list beside
     * the deans.
     */
    public function test_a_promoted_instructor_is_not_offered_on_the_form(): void
    {
        $person = $this->student();
        $this->assertSame('Student', $person->role->name);

        $this->actingAs($this->admin())->post("/admin/users/{$person->id}/role", [
            'role_id' => Role::where('name', 'Instructor')->firstOrFail()->id,
            'department' => 'College of Computer Studies',
        ])->assertRedirect();

        $resp = $this->actingAs(User::where('email', 'student2@my.cspc.edu.ph')->firstOrFail())
            ->get('/concerns/create');

        $this->assertFalse(
            $resp->viewData('otherStaffByOffice')->flatten()->contains('id', $person->id),
            'Someone promoted to Instructor must not appear in the staff picker'
        );

        fwrite(STDERR, "  [picker] a newly promoted instructor is not offered either: YES\n");
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
