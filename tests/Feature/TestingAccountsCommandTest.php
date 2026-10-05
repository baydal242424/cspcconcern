<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Section;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * testing:accounts makes one account per role per college.
 *
 * The danger it has to hold back is the one that bit this system before:
 * routing cannot tell a test account from a person, so a test dean is as
 * eligible to receive a student's concern as the real one -- and nobody can
 * sign in as it to answer. Staff demo accounts were removed once already after
 * exactly that happened.
 */
class TestingAccountsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, UserSeeder::class]);
    }

    private function testAccounts()
    {
        return User::where('email', 'like', 'test.%');
    }

    /**
     * A test account is made only where the job is vacant.
     *
     * Every dean and program chair is already on file, and those accounts have
     * never signed in -- so they are already in the sign-in dropdown under
     * their own role. A test one beside them is a second name for the same
     * job: a longer list to read, and a choice with no right answer.
     */
    public function test_it_only_fills_roles_nobody_real_holds(): void
    {
        $college = 'College of Computer Studies';

        // A real dean, and no real chair.
        User::create([
            'name' => 'The Real Dean',
            'email' => 'real.dean@cspc.edu.ph',
            'password' => Hash::make('not-used'),
            'role_id' => Role::where('name', 'Dean')->firstOrFail()->id,
            'status' => 'approved',
            'department' => $college,
        ]);

        Artisan::call('testing:accounts');

        $inCollege = fn (string $role) => $this->testAccounts()
            ->where('department', $college)
            ->whereHas('role', fn ($q) => $q->where('name', $role))
            ->exists();

        $this->assertFalse($inCollege('Dean'), 'The college has a dean, so it needs no test one');
        $this->assertTrue($inCollege('Program Chair'), 'The college has no chair, so it needs a test one');

        fwrite(STDERR, "  [testing:accounts] a test account is made only where the job is vacant\n");
    }

    /**
     * And is taken away once the vacancy is filled.
     *
     * Otherwise a test dean made when the college had none outlives the day
     * the real dean is added, and stays in the dropdown for good.
     */
    public function test_a_test_account_is_retired_when_a_real_one_appears(): void
    {
        Artisan::call('testing:accounts');

        // Whichever staff role the seeded data left vacant -- the point is the
        // rule, not which job happened to be empty today.
        $vacancy = $this->testAccounts()
            ->with('role')
            ->whereHas('role', fn ($q) => $q->where('name', '!=', 'Student'))
            ->firstOrFail();

        $roleName = $vacancy->role->name;

        User::create([
            'name' => 'The Real '.$roleName,
            'email' => 'real.person@cspc.edu.ph',
            'password' => Hash::make('not-used'),
            'role_id' => Role::where('name', $roleName)->firstOrFail()->id,
            'status' => 'approved',
            'department' => $vacancy->department,
        ]);

        Artisan::call('testing:accounts');

        $this->assertNull(
            User::find($vacancy->id),
            "The test {$roleName} should have been retired once a real one existed"
        );

        // Students are never retired: they file concerns, they do not handle
        // them, so a real student does not make a test one redundant.
        $this->assertGreaterThan(
            0,
            $this->testAccounts()->whereHas('role', fn ($q) => $q->where('name', 'Student'))->count()
        );

        fwrite(STDERR, "  [testing:accounts] a test account retires itself once the real person exists\n");
    }

    /**
     * A student per class, for every programme.
     *
     * Routing reads the programme AND the section, so one student per college
     * could only ever exercise one adviser. Sixteen per programme means every
     * class that exists has somebody who can file from it.
     */
    public function test_it_makes_a_student_for_every_class_of_every_programme(): void
    {
        Artisan::call('testing:accounts');

        $students = $this->testAccounts()
            ->whereHas('role', fn ($q) => $q->where('name', 'Student'))
            ->get();

        $expected = 0;

        foreach (User::COURSES_BY_COLLEGE as $college => $courses) {
            foreach ($courses as $programme) {
                foreach ([1, 2, 3, 4] as $year) {
                    // A programme shorter than four years has no fourth-year
                    // class, and a student filed into one sits in a class that
                    // does not exist.
                    if ($year > User::finalYearFor($programme)) {
                        continue;
                    }

                    foreach (['A', 'B', 'C', 'D'] as $letter) {
                        $expected++;

                        $match = $students->first(fn (User $u) => $u->course === $programme
                            && $u->section === $year.$letter);

                        $this->assertNotNull($match, "{$programme} {$year}{$letter} should have a test student");
                        $this->assertSame($college, $match->department);
                    }
                }
            }
        }

        $this->assertSame($expected, $students->count());

        fwrite(STDERR, "  [testing:accounts] a student in every class from 1A to 4D, for all {$expected} classes\n");
    }

    /**
     * Two programmes must never share an address.
     *
     * Initials collide: in Engineering, Electrical and Electronics both give
     * "bee", Civil and Computer both give "bce" -- and a shared handle means
     * one programme's student silently overwrites another's.
     */
    public function test_every_student_address_is_unique_to_its_class(): void
    {
        Artisan::call('testing:accounts');

        $students = $this->testAccounts()
            ->whereHas('role', fn ($q) => $q->where('name', 'Student'))
            ->get();

        $this->assertSame(
            $students->count(),
            $students->pluck('email')->unique()->count(),
            'Two classes share an address, so one of them was overwritten'
        );

        $this->assertTrue(
            $students->contains(fn (User $u) => $u->course === 'BS Electrical Engineering' && $u->section === '2A')
                && $students->contains(fn (User $u) => $u->course === 'BS Electronics Engineering' && $u->section === '2A'),
            'Electrical and Electronics must each keep their own 2A student'
        );

        fwrite(STDERR, "  [testing:accounts] programmes with the same initials keep separate students\n");
    }

    /** An adviser who advises nobody is not an adviser any concern can reach. */
    public function test_a_test_adviser_takes_a_class_that_is_free(): void
    {
        Artisan::call('testing:accounts');

        $adviser = $this->testAccounts()
            ->whereHas('role', fn ($q) => $q->where('name', 'Adviser'))
            ->firstOrFail();

        $held = Section::where('adviser_id', $adviser->id)->first();

        $this->assertNotNull($held, 'The test adviser should hold a class while free ones exist');
        $this->assertSame($adviser->course, $held->course);

        fwrite(STDERR, "  [testing:accounts] a test adviser is given a class the routing will find\n");
    }

    /**
     * And never one somebody already holds.
     *
     * The first version took section 1A with updateOrCreate, which replaced
     * whoever was there. On a database with advisers assigned that is a real
     * adviser displaced without a word, and their students' Academic concerns
     * rerouted to an account nobody can sign into.
     */
    public function test_it_never_takes_a_class_off_a_real_adviser(): void
    {
        $term = Section::currentTerm();

        $realAdviser = User::create([
            'name' => 'A Real Adviser',
            'email' => 'real.adviser@cspc.edu.ph',
            'password' => \Illuminate\Support\Facades\Hash::make('not-used'),
            'role_id' => \App\Models\Role::where('name', 'Instructor')->firstOrFail()->id,
            'status' => 'approved',
            'department' => 'College of Computer Studies',
            'course' => 'BS Information Technology',
        ]);

        // Every class of the programme, so there is no free one to fall back to.
        foreach (range(1, User::finalYearFor('BS Information Technology')) as $year) {
            foreach (['A', 'B', 'C', 'D'] as $letter) {
                Section::updateOrCreate([
                    'course' => 'BS Information Technology',
                    'section' => $year.$letter,
                    'school_year' => $term['school_year'],
                    'semester' => $term['semester'],
                ], ['adviser_id' => $realAdviser->id]);
            }
        }

        $before = Section::where('adviser_id', $realAdviser->id)->count();

        Artisan::call('testing:accounts');

        $this->assertSame(
            $before,
            Section::where('adviser_id', $realAdviser->id)->count(),
            'A test adviser took a class off a real one'
        );

        $testAdviser = $this->testAccounts()
            ->where('department', 'College of Computer Studies')
            ->whereHas('role', fn ($q) => $q->where('name', 'Adviser'))
            ->firstOrFail();

        $this->assertSame(0, Section::where('adviser_id', $testAdviser->id)->count());

        fwrite(STDERR, "  [testing:accounts] with every class taken, the test adviser takes none of them\n");
    }

    /** Every address is stamped, which is what makes the set findable and removable. */
    public function test_every_account_is_obviously_a_test_one(): void
    {
        Artisan::call('testing:accounts');

        foreach ($this->testAccounts()->get() as $account) {
            $this->assertStringStartsWith('test.', $account->email);
            $this->assertStringStartsWith('TEST ', $account->name);

            // Dormant: no Google identity, so nobody can sign in as one.
            $this->assertNull($account->google_id);
        }

        fwrite(STDERR, "  [testing:accounts] every one is stamped 'test' and cannot be signed into\n");
    }

    public function test_running_it_twice_updates_rather_than_duplicates(): void
    {
        Artisan::call('testing:accounts');
        $first = $this->testAccounts()->count();

        Artisan::call('testing:accounts');

        $this->assertSame($first, $this->testAccounts()->count());
        $this->assertStringContainsString('updated', Artisan::output());

        fwrite(STDERR, "  [testing:accounts] running it again updates the same accounts\n");
    }

    public function test_remove_takes_them_all_and_frees_their_classes(): void
    {
        Artisan::call('testing:accounts');

        $adviser = $this->testAccounts()
            ->whereHas('role', fn ($q) => $q->where('name', 'Adviser'))
            ->firstOrFail();

        $section = Section::where('adviser_id', $adviser->id)->firstOrFail();

        Artisan::call('testing:accounts', ['--remove' => true]);

        $this->assertSame(0, $this->testAccounts()->count());

        // The class survives its adviser, with nobody named -- not a row
        // pointing at an account that no longer exists.
        $this->assertNotNull($section->fresh(), 'The class itself must survive');
        $this->assertNull($section->fresh()->adviser_id);

        fwrite(STDERR, "  [testing:accounts] --remove clears them and leaves their classes unadvised, not broken\n");
    }

    /** Real accounts are not touched, however they are named. */
    public function test_it_leaves_real_accounts_alone(): void
    {
        $realCount = User::where('email', 'not like', 'test.%')->count();

        Artisan::call('testing:accounts');
        Artisan::call('testing:accounts', ['--remove' => true]);

        $this->assertSame($realCount, User::where('email', 'not like', 'test.%')->count());

        fwrite(STDERR, "  [testing:accounts] real accounts survive both create and remove\n");
    }

    /**
     * The guard that matters. On production these join the routing pool the
     * moment they exist, and a student's concern can land on one.
     */
    public function test_it_refuses_to_run_on_production(): void
    {
        app()['env'] = 'production';

        $status = Artisan::call('testing:accounts');

        $this->assertSame(1, $status);
        $this->assertSame(0, $this->testAccounts()->count());
        $this->assertStringContainsString('Refusing', Artisan::output());

        // And can still be forced, for someone who means it.
        Artisan::call('testing:accounts', ['--force' => true]);
        $this->assertGreaterThan(0, $this->testAccounts()->count());

        fwrite(STDERR, "  [testing:accounts] refused on production unless forced\n");
    }
}
