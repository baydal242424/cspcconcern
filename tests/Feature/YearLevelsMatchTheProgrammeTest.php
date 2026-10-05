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
 * A year picker offers the years that exist.
 *
 * Every one of them offered 1 to 6. No programme here runs to six, and only
 * Architecture runs to five, so an ordinary student was invited to choose
 * between two years that do not exist anywhere and one they could never
 * reach -- and was then refused on submit, by a server rule that had been
 * right all along.
 */
class YearLevelsMatchTheProgrammeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, UserSeeder::class]);
    }

    /** Four for an ordinary programme, five for the one that runs to five. */
    public function test_the_list_is_as_long_as_the_programme(): void
    {
        $this->assertSame([1, 2, 3, 4], User::yearLevelsFor('BS Information Systems'));
        $this->assertSame([1, 2, 3, 4, 5], User::yearLevelsFor('BS Architecture'));

        // No programme chosen yet: assume the ordinary four.
        $this->assertSame([1, 2, 3, 4], User::yearLevelsFor(null));

        // And nothing anywhere runs to six.
        $this->assertSame(5, User::longestProgrammeYears());

        fwrite(STDERR, "  [years] the list is as long as the programme, never longer\n");
    }

    /** The form a new student fills in offers four, not six. */
    public function test_a_new_student_is_not_offered_years_that_do_not_exist(): void
    {
        $student = User::create([
            'name' => 'Brand New',
            'email' => 'brand.new@my.cspc.edu.ph',
            'password' => Hash::make('x'),
            'role_id' => Role::where('name', 'Student')->firstOrFail()->id,
            'status' => 'approved',
            'google_id' => 'google-brand-new',
        ]);

        $html = $this->actingAsWithoutPolicy($student)->get('/complete-profile')
            ->assertOk()->getContent();

        $years = $this->yearOptionsIn($html, 'year');

        $this->assertSame(['1', '2', '3', '4'], $years, 'six years were offered to a new student');

        fwrite(STDERR, "  [years] complete-profile offers 1-4: ".implode(', ', $years)."\n");
    }

    /** Manage Users shows a student the years their own programme runs to. */
    public function test_manage_users_offers_the_students_own_programme_length(): void
    {
        $admin = User::where('email', 'admin@cspc.edu.ph')->firstOrFail();

        $ordinary = User::create([
            'name' => 'Ordinary Student',
            'email' => 'ordinary@my.cspc.edu.ph',
            'password' => Hash::make('x'),
            'role_id' => Role::where('name', 'Student')->firstOrFail()->id,
            'status' => 'approved',
            'student_id' => 'ORD1',
            'department' => 'College of Computer Studies',
            'course' => 'BS Information Systems',
            'section' => '2A',
        ]);

        $html = $this->actingAs($admin)->get('/admin/users?q=Ordinary+Student')
            ->assertOk()->getContent();

        $this->assertSame(
            ['1', '2', '3', '4'],
            $this->yearOptionsIn($html, 'year-'.$ordinary->id),
            'Manage Users offered years the programme does not run to'
        );

        fwrite(STDERR, "  [years] Manage Users offers 1-4 for a four-year programme\n");
    }

    /** Architecture keeps its fifth year, which a blanket cut would have lost. */
    public function test_architecture_keeps_its_fifth_year(): void
    {
        $admin = User::where('email', 'admin@cspc.edu.ph')->firstOrFail();

        $architect = User::create([
            'name' => 'Architecture Student',
            'email' => 'arki@my.cspc.edu.ph',
            'password' => Hash::make('x'),
            'role_id' => Role::where('name', 'Student')->firstOrFail()->id,
            'status' => 'approved',
            'student_id' => 'ARK1',
            'department' => 'College of Engineering and Architecture',
            'course' => 'BS Architecture',
            'section' => '5A',
        ]);

        $html = $this->actingAs($admin)->get('/admin/users?q=Architecture+Student')
            ->assertOk()->getContent();

        $this->assertSame(
            ['1', '2', '3', '4', '5'],
            $this->yearOptionsIn($html, 'year-'.$architect->id),
            'a five-year programme must still be able to record its fifth year'
        );

        fwrite(STDERR, "  [years] BS Architecture keeps year 5\n");
    }

    /** A sixth year is refused even if the request is hand-made. */
    public function test_a_sixth_year_is_refused_on_submit(): void
    {
        $student = User::create([
            'name' => 'Hand Poster',
            'email' => 'hand.poster@my.cspc.edu.ph',
            'password' => Hash::make('x'),
            'role_id' => Role::where('name', 'Student')->firstOrFail()->id,
            'status' => 'approved',
            'google_id' => 'google-hand-poster',
        ]);

        $this->actingAsWithoutPolicy($student)->post('/complete-profile', [
            'student_id' => 'HAND1',
            'department' => 'College of Computer Studies',
            'course' => 'BS Information Systems',
            'year' => '6',
            'section_letter' => 'A',
        ])->assertSessionHasErrors('year');

        $this->assertNull($student->fresh()->section);

        fwrite(STDERR, "  [years] a hand-posted sixth year is refused\n");
    }

    /**
     * The year options of one select, in order.
     *
     * @return list<string>
     */
    private function yearOptionsIn(string $html, string $id): array
    {
        if (! preg_match('#<select[^>]*\bid="'.preg_quote($id, '#').'"(.*?)</select>#s', $html, $select)) {
            $this->fail("no select with id {$id} on the page");
        }

        preg_match_all('#<option[^>]*value="([^"]*)"#', $select[1], $options);

        return array_values(array_filter($options[1], fn ($value) => $value !== ''));
    }
}
