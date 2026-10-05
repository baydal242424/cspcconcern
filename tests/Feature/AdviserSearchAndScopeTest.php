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
 * Two guarantees about class advisers that sit outside the advised-classes
 * panel itself: that an administrator can find them, and that holding the
 * Adviser role never reaches past a person's own college.
 */
class AdviserSearchAndScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, UserSeeder::class, CcsFacultySeeder::class]);
    }

    private function person(string $email, string $role, string $college = 'College of Computer Studies'): User
    {
        return User::create([
            'name' => 'Person '.$email,
            'email' => $email,
            'password' => Hash::make('not-used'),
            'role_id' => Role::where('name', $role)->firstOrFail()->id,
            'department' => $college,
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);
    }

    /**
     * Almost every adviser holds another role, so the Manage Users search has
     * to find them by the word "adviser" and by the class they advise.
     */
    public function test_search_finds_an_adviser_by_the_word_and_by_their_class(): void
    {
        $adviser = $this->person('advises.bsis@cspc.edu.ph', 'Instructor');
        $plain = $this->person('advises.nothing@cspc.edu.ph', 'Instructor');

        Section::create([
            'course' => 'BS Information Systems',
            'section' => '4A',
            'school_year' => '2024-2025',
            'semester' => 'Second',
            'adviser_id' => $adviser->id,
        ]);

        $admin = User::where('email', 'admin@cspc.edu.ph')->firstOrFail();

        // Searching happens in the database now -- the roster is paged, so the
        // person being looked for is usually not on the page in front of you.
        $found = function (string $term) use ($admin) {
            return $this->actingAs($admin)
                ->get('/admin/users?q='.urlencode($term))
                ->assertOk()
                ->viewData('users')
                ->pluck('email');
        };

        foreach (['adviser', 'BS Information Systems 4A', '4A'] as $term) {
            $this->assertTrue(
                $found($term)->contains('advises.bsis@cspc.edu.ph'),
                "the adviser should be findable by \"{$term}\""
            );
        }

        $this->assertFalse(
            $found('adviser')->contains('advises.nothing@cspc.edu.ph'),
            'somebody advising nothing is not an adviser'
        );

        fwrite(STDERR, "  [adviser] search finds advisers by \"adviser\" and by the class they advise\n");
    }

    /**
     * findHandler()'s last tier is "anybody in the role". With one Adviser
     * account on record, every college's unadvised sections would have routed
     * to that one person.
     */
    public function test_the_adviser_role_never_reaches_another_college(): void
    {
        $ccsAdviser = $this->person('ccs.adviser.role@cspc.edu.ph', 'Adviser');

        $student = User::where('email', 'student@my.cspc.edu.ph')->firstOrFail();
        $student->forceFill([
            'department' => 'College of Health Sciences',
            'course' => 'BS Nursing',
            'section' => '2C',
        ])->save();

        $this->actingAs($student->refresh())->post('/concerns', [
            'category' => 'Academic',
            'description' => 'A Nursing concern from a section with no adviser.',
        ]);

        $this->assertNotSame($ccsAdviser->id, Concern::latest('id')->firstOrFail()->assigned_to);

        fwrite(STDERR, "  [adviser] a Computer Studies adviser never receives a Nursing concern\n");
    }
}
