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
 * user:list is for looking only -- Manage Users is where anything changes,
 * because that is auditable and needs no server access.
 *
 * What matters here is that it never LIES: a filter that quietly matched
 * nothing, or a placeholder shown as a working account, would send someone
 * off to fix a problem that is not there.
 */
class ListUsersCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, UserSeeder::class]);
    }

    private function staff(string $name, string $email, string $role, array $extra = []): User
    {
        return User::create(array_merge([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('not-used'),
            'role_id' => Role::where('name', $role)->firstOrFail()->id,
            'status' => 'approved',
            'email_verified_at' => now(),
        ], $extra));
    }

    /**
     * Run it and hand back everything it printed.
     *
     * expectsOutputToContain() consumes one output line per expectation, so
     * two things on the same table row can never both match. Reading the
     * whole output is what these tests actually mean.
     */
    private function listing(array $options = [], int $expectedStatus = 0): string
    {
        $this->assertSame($expectedStatus, Artisan::call('user:list', $options));

        return Artisan::output();
    }

    public function test_it_lists_people_with_their_roles(): void
    {
        $this->staff('Rosel Onesa', 'rosel.onesa@cspc.edu.ph', 'Dean', [
            'department' => 'College of Computer Studies',
        ]);

        $output = $this->listing();

        $this->assertStringContainsString('Rosel Onesa', $output);
        $this->assertStringContainsString('Dean', $output);
        $this->assertStringContainsString('College of Computer Studies', $output);

        fwrite(STDERR, "  [user:list] shows each account with its role and college\n");
    }

    public function test_it_separates_a_placeholder_from_a_working_account(): void
    {
        $this->staff('Never Signed In', 'dormant@cspc.edu.ph', 'Instructor');
        $this->staff('Has Signed In', 'active@cspc.edu.ph', 'Instructor', ['google_id' => '1234567890']);

        $dormant = $this->listing(['--search' => 'dormant']);
        $this->assertStringContainsString('not yet', $dormant);
        $this->assertStringNotContainsString('Has Signed In', $dormant);

        $active = $this->listing(['--search' => 'active@']);
        $this->assertStringContainsString('yes', $active);
        $this->assertStringNotContainsString('not yet', $active);

        fwrite(STDERR, "  [user:list] a placeholder reads as 'not yet', a real account as 'yes'\n");
    }

    public function test_it_filters_by_role_and_refuses_a_role_that_does_not_exist(): void
    {
        $this->staff('A Dean', 'dean.one@cspc.edu.ph', 'Dean');
        $this->staff('An Instructor', 'teacher@cspc.edu.ph', 'Instructor');

        $output = $this->listing(['--role' => 'Dean']);
        $this->assertStringContainsString('A Dean', $output);
        $this->assertStringNotContainsString('An Instructor', $output);

        // A typo must not read as "nobody has that role".
        $typo = $this->listing(['--role' => 'Deen'], 1);
        $this->assertStringContainsString('No such role: Deen', $typo);

        fwrite(STDERR, "  [user:list] --role filters, and a misspelt role fails instead of showing nothing\n");
    }

    public function test_it_shows_who_advises_which_class(): void
    {
        $adviser = $this->staff('Rosa Delgado', 'rosa@cspc.edu.ph', 'Instructor');
        $term = Section::currentTerm();

        Section::create([
            'course' => 'BS Information Technology',
            'section' => '2C',
            'school_year' => $term['school_year'],
            'semester' => $term['semester'],
            'adviser_id' => $adviser->id,
        ]);

        $this->staff('Advises Nobody', 'idle@cspc.edu.ph', 'Instructor');

        $output = $this->listing(['--advisers' => true]);

        $this->assertStringContainsString('Rosa Delgado', $output);
        $this->assertStringContainsString('BS Information Technology 2C', $output);
        $this->assertStringNotContainsString('Advises Nobody', $output);

        fwrite(STDERR, "  [user:list] --advisers names the classes each one advises\n");
    }

    public function test_it_shows_staff_still_waiting_for_a_role(): void
    {
        $this->staff('Waiting Patiently', 'waiting@cspc.edu.ph', 'Faculty/Staff', [
            'requested_role_id' => Role::where('name', 'Instructor')->firstOrFail()->id,
            'role_requested_at' => now()->subDay(),
        ]);

        $this->staff('Already Sorted', 'sorted@cspc.edu.ph', 'Instructor');

        $output = $this->listing(['--pending' => true]);

        $this->assertStringContainsString('Waiting Patiently', $output);
        $this->assertStringContainsString('Instructor', $output);   // what they asked for
        $this->assertStringNotContainsString('Already Sorted', $output);

        fwrite(STDERR, "  [user:list] --pending shows only staff an admin still has to act on\n");
    }

    public function test_roles_counts_everyone_including_classes_without_an_adviser(): void
    {
        $this->staff('Dean One', 'dean.one@cspc.edu.ph', 'Dean');
        $this->staff('Dean Two', 'dean.two@cspc.edu.ph', 'Dean');

        $term = Section::currentTerm();
        Section::create([
            'course' => 'BS Information Technology',
            'section' => '1A',
            'school_year' => $term['school_year'],
            'semester' => $term['semester'],
            'adviser_id' => null,
        ]);

        $output = $this->listing(['--roles' => true]);

        // Two created here, plus whichever the seeder made: the count is read
        // from the table rather than assumed.
        $this->assertMatchesRegularExpression('/\|\s*Dean\s*\|\s*[1-9]\d*\s*\|/', $output);
        $this->assertStringContainsString('without an adviser: 1', $output);

        fwrite(STDERR, "  [user:list] --roles counts each role and the classes nobody advises\n");
    }

    public function test_a_filter_matching_nobody_says_so(): void
    {
        $this->assertStringContainsString(
            'Nobody matches that.',
            $this->listing(['--search' => 'nobody-by-this-name'])
        );

        fwrite(STDERR, "  [user:list] an empty result is stated, not printed as a blank table\n");
    }

    /** A long roster must not print only the first page without saying so. */
    public function test_it_says_when_it_is_showing_only_part_of_the_list(): void
    {
        foreach (range(1, 6) as $n) {
            $this->staff("Instructor {$n}", "teacher{$n}@cspc.edu.ph", 'Instructor');
        }

        // The seeder ships instructors of its own, so the total is counted.
        $total = User::whereHas('role', fn ($q) => $q->where('name', 'Instructor'))->count();

        $capped = $this->listing(['--role' => 'Instructor', '--limit' => 2]);
        $this->assertStringContainsString("Showing 2 of {$total}", $capped);

        $all = $this->listing(['--role' => 'Instructor', '--limit' => 0]);
        $this->assertStringContainsString("{$total} accounts.", $all);
        $this->assertStringNotContainsString('Showing', $all);

        fwrite(STDERR, "  [user:list] a capped list says how many it is hiding; --limit=0 shows all\n");
    }
}
