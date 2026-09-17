<?php

namespace Tests\Feature;

use App\Models\Concern;
use App\Models\Role;
use App\Models\Section;
use App\Models\User;
use Database\Seeders\Faculty\CcsFacultySeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * A student says "not my adviser", and the concern climbs instead of dropping.
 *
 * Academic, Physical, Safety and Others reach the class adviser first. That is
 * right until the adviser is the person the student does not want reading it --
 * and asking to be heard elsewhere should not require accusing them of
 * anything. The tier above is the Program Chair; the Dean takes it when a
 * chair is the one being reported.
 */
class AdviserBypassTest extends TestCase
{
    use RefreshDatabase;

    private const COURSE = 'BS Information Technology';

    private const COLLEGE = 'College of Computer Studies';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, UserSeeder::class, CcsFacultySeeder::class]);
    }

    private function student(): User
    {
        $student = User::where('email', 'student@my.cspc.edu.ph')->firstOrFail();

        $student->forceFill([
            'department' => self::COLLEGE,
            'course' => self::COURSE,
            'section' => '3A',
        ])->save();

        return $student;
    }

    private function personIn(string $roleName, string $email, ?string $course = null): User
    {
        return User::create([
            'name' => $roleName.' for the test',
            'email' => $email,
            'password' => Hash::make('not-used'),
            'role_id' => Role::where('name', $roleName)->firstOrFail()->id,
            'department' => self::COLLEGE,
            'course' => $course,
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);
    }

    /** The adviser of the student's own section, as routing finds them. */
    private function sectionAdviser(): User
    {
        $adviser = $this->personIn('Instructor', 'section.adviser@cspc.edu.ph');

        Section::create([
            'course' => self::COURSE,
            'section' => '3A',
            'school_year' => '2024-2025',
            'semester' => 'Second',
            'adviser_id' => $adviser->id,
        ]);

        return $adviser;
    }

    private function fileAs(User $student, array $payload = []): Concern
    {
        $this->actingAs($student)->post('/concerns', array_merge([
            'category' => 'Academic',
            'description' => 'A concern filed to see which tier it reaches.',
        ], $payload));

        return Concern::latest('id')->firstOrFail();
    }

    /** Unchanged when the student says nothing: their own adviser takes it. */
    public function test_without_the_box_it_still_goes_to_the_adviser(): void
    {
        $adviser = $this->sectionAdviser();

        $concern = $this->fileAs($this->student());

        $this->assertSame($adviser->id, $concern->assigned_to);
        $this->assertFalse((bool) $concern->skip_adviser);

        fwrite(STDERR, "  [bypass] box unticked -> the class adviser, as before\n");
    }

    /** Ticked: the Program Chair of the student's own programme takes it. */
    public function test_ticking_the_box_sends_it_to_the_program_chair(): void
    {
        $adviser = $this->sectionAdviser();
        $this->personIn('Program Chair', 'chair.bsit@cspc.edu.ph', self::COURSE);

        $concern = $this->fileAs($this->student(), ['skip_adviser' => '1']);
        $handler = $concern->assignedUser;

        $this->assertNotSame($adviser->id, $concern->assigned_to, 'the adviser must be stepped over');
        $this->assertSame('Program Chair', $handler->role->name);
        $this->assertSame(self::COURSE, $handler->course, 'their own programme, not another chair');
        $this->assertTrue((bool) $concern->skip_adviser, 'the request is recorded on the concern');

        fwrite(STDERR, "  [bypass] ticked -> the Program Chair of their own programme\n");
    }

    /** A chair who is the subject cannot be handed the complaint about them. */
    public function test_when_a_chair_is_the_problem_the_dean_takes_it(): void
    {
        $this->sectionAdviser();
        $chair = $this->personIn('Program Chair', 'chair.bsit@cspc.edu.ph', self::COURSE);
        $this->personIn('Dean', 'dean.ccs.test@cspc.edu.ph');

        $concern = $this->fileAs($this->student(), [
            'skip_adviser' => '1',
            'about_staff_id' => [$chair->id],
        ]);
        $handler = $concern->assignedUser;

        $this->assertNotSame($chair->id, $concern->assigned_to);
        $this->assertSame('Dean', $handler->role->name);
        $this->assertSame(self::COLLEGE, $handler->department);

        fwrite(STDERR, "  [bypass] chair is the subject -> the Dean\n");
    }

    /**
     * Reporting the adviser used to drop the concern to an INSTRUCTOR -- a tier
     * below the person being reported. It climbs now, same as the checkbox.
     */
    public function test_reporting_the_adviser_climbs_to_the_chair(): void
    {
        $adviser = $this->sectionAdviser();
        $this->personIn('Program Chair', 'chair.bsit@cspc.edu.ph', self::COURSE);

        $concern = $this->fileAs($this->student(), ['about_staff_id' => [$adviser->id]]);
        $handler = $concern->assignedUser;

        $this->assertNotSame($adviser->id, $concern->assigned_to);
        $this->assertSame('Program Chair', $handler->role->name);

        fwrite(STDERR, "  [bypass] concern about the adviser -> the chair, not an instructor\n");
    }

    /**
     * Naming a Program Chair is enough on its own -- no checkbox.
     *
     * This went to the class adviser: an instructor, junior to both chairs the
     * concern was about, left holding a complaint about their own senior.
     */
    public function test_a_concern_about_a_chair_reaches_the_dean_without_the_box(): void
    {
        $adviser = $this->sectionAdviser();
        $chair = $this->personIn('Program Chair', 'chair.bsit@cspc.edu.ph', self::COURSE);
        $this->personIn('Dean', 'dean.ccs.test@cspc.edu.ph');

        $concern = $this->fileAs($this->student(), ['about_staff_id' => [$chair->id]]);
        $handler = $concern->assignedUser;

        $this->assertFalse((bool) $concern->skip_adviser, 'the student ticked nothing');
        $this->assertNotSame($adviser->id, $concern->assigned_to, 'not the class adviser');
        $this->assertNotSame($chair->id, $concern->assigned_to, 'and never the chair themselves');
        $this->assertSame('Dean', $handler->role->name);

        fwrite(STDERR, "  [chair] a concern naming a chair -> the Dean, with nothing ticked\n");
    }

    /** Several people named, one of them a chair: still the Dean. */
    public function test_naming_a_chair_among_others_still_reaches_the_dean(): void
    {
        $adviser = $this->sectionAdviser();
        $chair = $this->personIn('Program Chair', 'chair.bsit@cspc.edu.ph', self::COURSE);
        $instructor = $this->personIn('Instructor', 'another.instructor@cspc.edu.ph');
        $this->personIn('Dean', 'dean.ccs.test@cspc.edu.ph');

        $concern = $this->fileAs($this->student(), [
            'about_staff_id' => [$instructor->id, $chair->id],
        ]);

        $this->assertSame('Dean', $concern->assignedUser->role->name);
        $this->assertNotSame($adviser->id, $concern->assigned_to);

        fwrite(STDERR, "  [chair] a chair named beside an instructor -> still the Dean\n");
    }

    /**
     * A dean is the top of a college, so the next reviewer is outside it.
     *
     * Excluding the named dean was not enough: findHandler() falls back to
     * anybody in the role, so the complaint went to another college's dean --
     * a peer of equal rank with no standing over them.
     */
    public function test_a_concern_about_a_dean_reaches_the_vpaa(): void
    {
        $adviser = $this->sectionAdviser();
        $dean = $this->personIn('Dean', 'dean.ccs.test@cspc.edu.ph');
        $otherDean = User::create([
            'name' => 'Dean of another college',
            'email' => 'dean.chs.test@cspc.edu.ph',
            'password' => Hash::make('not-used'),
            'role_id' => Role::where('name', 'Dean')->firstOrFail()->id,
            'department' => 'College of Health Sciences',
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);
        $vpaa = $this->personIn('Vice President for Academic Affairs', 'vpaa.test@cspc.edu.ph');

        $concern = $this->fileAs($this->student(), ['about_staff_id' => [$dean->id]]);

        $this->assertSame($vpaa->id, $concern->assigned_to);
        $this->assertNotSame($dean->id, $concern->assigned_to, 'never the dean it is about');
        $this->assertNotSame($otherDean->id, $concern->assigned_to, 'nor a dean of another college');
        $this->assertNotSame($adviser->id, $concern->assigned_to, 'nor their class adviser');

        fwrite(STDERR, "  [dean] a concern naming a dean -> the VPAA, not another dean\n");
    }

    /** Every college, not just Computer Studies: the rule reads the role. */
    public function test_the_rule_holds_for_a_dean_of_any_college(): void
    {
        $this->sectionAdviser();
        $vpaa = $this->personIn('Vice President for Academic Affairs', 'vpaa.test@cspc.edu.ph');

        foreach (['College of Health Sciences', 'College of Arts and Sciences'] as $i => $college) {
            $dean = User::create([
                'name' => 'Dean of '.$college,
                'email' => 'dean.'.$i.'.test@cspc.edu.ph',
                'password' => Hash::make('not-used'),
                'role_id' => Role::where('name', 'Dean')->firstOrFail()->id,
                'department' => $college,
                'status' => 'approved',
                'email_verified_at' => now(),
            ]);

            $concern = $this->fileAs($this->student(), ['about_staff_id' => [$dean->id]]);

            $this->assertSame($vpaa->id, $concern->assigned_to, $college.' should still reach the VPAA');
        }

        fwrite(STDERR, "  [dean] the same for a dean of any college: YES\n");
    }

    /**
     * A chair of somebody else's college is not a smaller version of the right
     * chair. Where the student's own college has none, the concern climbs to
     * their dean rather than crossing to a college with nothing to do with it.
     */
    public function test_a_chair_of_another_college_is_never_used(): void
    {
        $this->sectionAdviser();

        // The seeded college has chairs of its own, which is the case this
        // test is not about: empty the tier first, so the only chair anywhere
        // belongs to somebody else's college.
        User::whereHas('role', fn ($q) => $q->where('name', 'Program Chair'))
            ->where('department', self::COLLEGE)
            ->update(['role_id' => Role::where('name', 'Instructor')->firstOrFail()->id]);

        $foreignChair = User::create([
            'name' => 'Chair of another college',
            'email' => 'chair.elsewhere@cspc.edu.ph',
            'password' => Hash::make('not-used'),
            'role_id' => Role::where('name', 'Program Chair')->firstOrFail()->id,
            'department' => 'College of Health Sciences',
            'course' => 'BS Nursing',
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);
        $this->personIn('Dean', 'dean.ccs.test@cspc.edu.ph');

        $concern = $this->fileAs($this->student(), ['skip_adviser' => '1']);
        $handler = $concern->assignedUser;

        $this->assertNotSame($foreignChair->id, $concern->assigned_to, 'not a chair from another college');
        // Whichever dean of their own college, not one specific account: the
        // seeded college dean and the one made here are equally right.
        $this->assertSame('Dean', $handler->role->name);
        $this->assertSame(self::COLLEGE, $handler->department);

        fwrite(STDERR, "  [scope] no chair in their college -> their own dean, not a stranger's chair\n");
    }

    /** The flag is meaningless outside the adviser's categories, so it is cleared. */
    public function test_the_flag_is_cleared_for_a_category_with_no_adviser_tier(): void
    {
        $this->sectionAdviser();

        $concern = $this->fileAs($this->student(), [
            'category' => 'Facilities',
            'skip_adviser' => '1',
        ]);

        $this->assertFalse((bool) $concern->skip_adviser);
        $this->assertSame('General Services', optional($concern->assignedUser->role)->name);

        fwrite(STDERR, "  [bypass] Facilities still goes to General Services, flag cleared\n");
    }

    /** The box is on the form, and the concern page says when it was used. */
    public function test_the_form_offers_it_and_the_page_reports_it(): void
    {
        $this->sectionAdviser();
        $this->personIn('Program Chair', 'chair.bsit@cspc.edu.ph', self::COURSE);
        $student = $this->student();

        $this->actingAs($student)->get('/concerns/create')
            ->assertOk()
            ->assertSee('name="skip_adviser"', false)
            ->assertSee('I do not want this to go to my class adviser');

        $concern = $this->fileAs($student, ['skip_adviser' => '1']);

        $this->actingAs($student)->get("/concerns/{$concern->id}")
            ->assertOk()
            ->assertSee("Skipped at the student's request", false);

        fwrite(STDERR, "  [bypass] offered on the form, reported on the concern page\n");
    }
}
