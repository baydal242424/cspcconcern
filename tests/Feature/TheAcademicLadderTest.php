<?php

namespace Tests\Feature;

use App\Models\Concern;
use App\Models\Role;
use App\Models\Section;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * One ladder, climbed one rung at a time.
 *
 *   skip the adviser        -> the chair of the student's own programme
 *   the concern is about the chair -> the dean of their college
 *   the concern is about the dean  -> the VPAA
 *
 * Each rung is the first person with standing over the one below. The point
 * is that nobody is ever handed a complaint about themselves, and nobody is
 * handed one sideways to a peer who cannot act on it.
 */
class TheAcademicLadderTest extends TestCase
{
    use RefreshDatabase;

    private const CCS = 'College of Computer Studies';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, UserSeeder::class]);
    }

    private function person(string $name, string $email, string $role, ?string $dept = null, ?string $course = null): User
    {
        return User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('not-used'),
            'role_id' => Role::where('name', $role)->firstOrFail()->id,
            'status' => 'approved',
            'department' => $dept,
            'course' => $course,
        ]);
    }

    private function studentOf(string $programme): User
    {
        $adviser = $this->person('Their Adviser', 'ladder.adviser@cspc.edu.ph', 'Instructor', self::CCS, $programme);

        $term = Section::currentTerm();
        Section::create([
            'course' => $programme,
            'section' => '2B',
            'school_year' => $term['school_year'],
            'semester' => $term['semester'],
            'adviser_id' => $adviser->id,
        ]);

        $student = User::where('email', 'student@my.cspc.edu.ph')->firstOrFail();
        $student->forceFill([
            'department' => self::CCS,
            'course' => $programme,
            'section' => '2B',
        ])->save();

        return $student->refresh();
    }

    private function file(User $student, array $extra = []): Concern
    {
        $this->actingAs($student)->post('/concerns', array_merge([
            'category' => 'Academic',
            'description' => 'A concern that has to find the right person to handle it.',
        ], $extra))->assertRedirect();

        return Concern::latest('id')->firstOrFail();
    }

    private function roleOf(Concern $concern): ?string
    {
        return optional(optional($concern->assignedUser)->role)->name;
    }

    /** Rung one: skipping the adviser reaches the chair of their programme. */
    public function test_skipping_the_adviser_reaches_their_own_programmes_chair(): void
    {
        $theirs = $this->person('BSIS Chair', 'ladder.bsis@cspc.edu.ph', 'Program Chair', self::CCS, 'BS Information Systems');
        $other = $this->person('BSIT Chair', 'ladder.bsit@cspc.edu.ph', 'Program Chair', self::CCS, 'BS Information Technology');

        $student = $this->studentOf('BS Information Systems');

        $concern = $this->file($student, ['skip_adviser' => 1]);

        $this->assertSame($theirs->id, $concern->assigned_to);
        $this->assertNotSame($other->id, $concern->assigned_to, 'the chair of another programme has no standing here');

        fwrite(STDERR, "  [ladder] skipping the adviser reaches the chair of the student's own programme\n");
    }

    /** Rung two: a concern about the chair reaches the dean. */
    public function test_a_concern_about_the_chair_reaches_the_dean(): void
    {
        $chair = $this->person('BSIS Chair', 'ladder.bsis@cspc.edu.ph', 'Program Chair', self::CCS, 'BS Information Systems');
        $dean = $this->person('CCS Dean', 'ladder.dean@cspc.edu.ph', 'Dean', self::CCS);

        $student = $this->studentOf('BS Information Systems');

        $concern = $this->file($student, ['about_staff_id' => [$chair->id]]);

        // A dean of their own college. Which one depends on the data --
        // the seeded roster already has a CCS dean -- so the role and the
        // college are what matter, not a particular person.
        $this->assertSame('Dean', $this->roleOf($concern));
        $this->assertSame(self::CCS, $concern->assignedUser->department);
        $this->assertNotNull($dean);

        fwrite(STDERR, "  [ladder] a concern about the chair goes up to the dean\n");
    }

    /** Rung three: a concern about the dean reaches the VPAA. */
    public function test_a_concern_about_the_dean_reaches_the_vpaa(): void
    {
        $this->person('BSIS Chair', 'ladder.bsis@cspc.edu.ph', 'Program Chair', self::CCS, 'BS Information Systems');
        $dean = $this->person('CCS Dean', 'ladder.dean@cspc.edu.ph', 'Dean', self::CCS);
        $vpaa = $this->person('The VPAA', 'ladder.vpaa@cspc.edu.ph', 'Vice President for Academic Affairs', 'Academic Affairs');

        // A second college with its own dean, who must never receive this.
        $otherDean = $this->person('CHS Dean', 'ladder.chs@cspc.edu.ph', 'Dean', 'College of Health Sciences');

        $student = $this->studentOf('BS Information Systems');

        $concern = $this->file($student, ['about_staff_id' => [$dean->id]]);

        $this->assertSame($vpaa->id, $concern->assigned_to);
        $this->assertNotSame($otherDean->id, $concern->assigned_to, 'a dean is a peer, not a reviewer');

        fwrite(STDERR, "  [ladder] a concern about the dean goes up to the VPAA, never sideways\n");
    }

    /** The whole ladder, for a different programme, unchanged. */
    public function test_the_same_ladder_serves_every_programme(): void
    {
        $it = $this->person('BSIT Chair', 'ladder.bsit@cspc.edu.ph', 'Program Chair', self::CCS, 'BS Information Technology');
        $this->person('BSIS Chair', 'ladder.bsis@cspc.edu.ph', 'Program Chair', self::CCS, 'BS Information Systems');

        $student = $this->studentOf('BS Information Technology');

        $concern = $this->file($student, ['skip_adviser' => 1]);

        $this->assertSame($it->id, $concern->assigned_to, 'an IT student reaches the IT chair');

        fwrite(STDERR, "  [ladder] an IT student reaches the IT chair, not the IS one\n");
    }

    /**
     * The ladder serves every category that reaches the class adviser.
     *
     * Academic, Physical, Safety and Others all start there, so all four climb
     * the same rungs when the adviser is skipped or named.
     *
     * @return array<string, array{0: string}>
     */
    public static function adviserCategories(): array
    {
        return [
            'Academic' => ['Academic'],
            'Physical injury' => ['Physical'],
            'Safety hazard' => ['Safety'],
            'Others' => ['Others'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('adviserCategories')]
    public function test_every_adviser_category_climbs_the_same_ladder(string $category): void
    {
        $chair = $this->person('BSIS Chair', 'ladder.bsis@cspc.edu.ph', 'Program Chair', self::CCS, 'BS Information Systems');
        $this->person('BSIT Chair', 'ladder.bsit@cspc.edu.ph', 'Program Chair', self::CCS, 'BS Information Technology');

        $student = $this->studentOf('BS Information Systems');

        $concern = $this->file($student, [
            'category' => $category,
            'skip_adviser' => 1,
            // Others has to say what it is before anything else can.
            'other_category' => $category === 'Others' ? 'Lost property' : null,
        ]);

        $this->assertSame($category, $concern->category);
        $this->assertTrue((bool) $concern->skip_adviser, "{$category} should be able to skip the adviser");
        $this->assertSame($chair->id, $concern->assigned_to, "{$category} should reach the programme's own chair");

        fwrite(STDERR, "  [ladder] {$category}: skipping the adviser reaches the programme's chair\n");
    }

    /** The form offers the same four the routing serves. */
    public function test_the_form_lets_those_four_name_somebody(): void
    {
        $student = $this->studentOf('BS Information Systems');

        $page = $this->actingAs($student)->get('/concerns/create')->assertOk();

        $page->assertSee("const NAMEABLE = ['Academic', 'Physical', 'Safety', 'Facilities', 'Equipment', 'Others']", false)
            ->assertSee("'Physical': ['Program Chair', 'Dean', 'Vice President for Academic Affairs']", false)
            ->assertSee("'Safety': ['Program Chair', 'Dean', 'Vice President for Academic Affairs']", false);

        fwrite(STDERR, "  [ladder] the form offers naming on all four adviser categories\n");
    }

    // ------------------------------------------------------------- the picker

    public function test_the_picker_offers_the_ladder_and_not_the_system_admin(): void
    {
        $this->person('BSIS Chair', 'ladder.bsis@cspc.edu.ph', 'Program Chair', self::CCS, 'BS Information Systems');
        $this->person('BSIT Chair', 'ladder.bsit@cspc.edu.ph', 'Program Chair', self::CCS, 'BS Information Technology');
        $this->person('CCS Dean', 'ladder.dean@cspc.edu.ph', 'Dean', self::CCS);
        $this->person('The VPAA', 'ladder.vpaa@cspc.edu.ph', 'Vice President for Academic Affairs', 'Academic Affairs');
        $this->person('CCS Office Staff', 'ladder.staff@cspc.edu.ph', 'Faculty/Staff', self::CCS);

        $student = $this->studentOf('BS Information Systems');

        $grouped = $this->actingAs($student)->get('/concerns/create')->assertOk()
            ->viewData('otherStaffByOffice');

        $offered = collect($grouped)->flatten();

        // Grouped by what somebody is, in the order a concern climbs.
        $this->assertSame(
            ['Program Chair', 'Dean', 'Vice President for Academic Affairs', 'Staff and offices'],
            collect($grouped)->keys()->all()
        );

        // One chair, theirs.
        $chairs = collect($grouped)->get('Program Chair');
        $this->assertCount(1, $chairs);
        $this->assertSame('BS Information Systems', $chairs->first()->course);

        // And nobody who runs the system.
        $this->assertFalse(
            $offered->contains(fn (User $u) => optional($u->role)->name === 'System Admin'),
            'a System Admin administers accounts, and is no part of a student\'s concern'
        );

        fwrite(STDERR, "  [ladder] the picker is grouped chair, dean, VPAA, staff -- with one chair and no System Admin\n");
    }
}
