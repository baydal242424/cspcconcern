<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Manage Users search box finds a person by whatever the admin has to hand.
 *
 * The filtering itself is one line of JavaScript over a data-search attribute,
 * so what actually decides whether a search works is what gets WRITTEN into
 * that attribute. That is server-side, and it has already been wrong twice:
 * the promotion panel told admins to search for graduated accounts before
 * status was in there, and the student number -- the one identifier a class
 * list is keyed on -- was missing entirely.
 *
 * These assert the haystack, which is the part that can silently lose a field.
 */
class AdminAccountSearchTest extends TestCase
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

    /**
     * Everything an admin might type. A name off a concern, a student number
     * off a class list, a status while clearing up after the year rollover.
     */
    public function test_a_student_can_be_found_by_any_of_their_details(): void
    {
        $student = User::factory()->create([
            'name' => 'Juana D. Dela Cruz',
            'email' => 'juana.delacruz@my.cspc.edu.ph',
            'student_id' => '2023-04517',
            'role_id' => Role::where('name', 'Student')->value('id'),
            'department' => 'College of Computer Studies',
            'course' => 'BS Information Systems',
            'section' => '3B',
            'status' => 'approved',
        ]);

        $page = $this->actingAs($this->admin())->get(route('admin.users'));
        $page->assertOk();

        $haystack = $this->searchTextFor($page->getContent(), $student);

        foreach ([
            'juana d. dela cruz' => 'their name',
            '2023-04517' => 'their student number',
            'juana.delacruz@my.cspc.edu.ph' => 'their email',
            'student' => 'their role',
            'college of computer studies' => 'their college',
            'bs information systems' => 'their programme',
            '3b' => 'their section',
            'approved' => 'their status',
        ] as $term => $what) {
            $this->assertStringContainsString(
                $term,
                $haystack,
                "Searching by {$what} must find them"
            );
        }

        fwrite(STDERR, "  [search] found by name, student ID, email, role, college, programme, section, status: YES\n");
    }

    /**
     * The promotion panel tells the admin to search for graduated accounts.
     * If that word is not in the haystack the advice is a dead end, and the
     * only way to find one is to scroll.
     */
    public function test_a_graduated_account_is_findable_by_status(): void
    {
        $graduate = User::factory()->create([
            'name' => 'Pedro Santos',
            'role_id' => Role::where('name', 'Student')->value('id'),
            'department' => 'College of Health Sciences',
            'course' => 'BS Nursing',
            'section' => '4A',
            'status' => 'graduated',
        ]);

        $page = $this->actingAs($this->admin())->get(route('admin.users'));

        $this->assertStringContainsString(
            'graduated',
            $this->searchTextFor($page->getContent(), $graduate)
        );

        fwrite(STDERR, "  [search] a graduated account is findable by status: YES\n");
    }

    /** The student number is shown, so a match by id can be confirmed by eye. */
    public function test_the_student_number_is_visible_on_the_card(): void
    {
        User::factory()->create([
            'name' => 'Ana Reyes',
            'student_id' => '2024-00891',
            'role_id' => Role::where('name', 'Student')->value('id'),
            'department' => 'College of Arts and Sciences',
            'course' => 'BS Mathematics',
            'section' => '2A',
            'status' => 'approved',
        ]);

        $this->actingAs($this->admin())->get(route('admin.users'))
            ->assertSee('ID 2024-00891')
            ->assertSee('Section 2A');

        fwrite(STDERR, "  [search] the card shows the student number and section: YES\n");
    }

    /**
     * Pull one card's data-search attribute out of the page.
     */
    private function searchTextFor(string $html, User $user): string
    {
        $this->assertMatchesRegularExpression(
            '/data-search="[^"]*'.preg_quote(strtolower($user->name), '/').'[^"]*"/',
            strtolower($html),
            "No card on the page carries {$user->name} in its search text"
        );

        preg_match_all('/data-search="([^"]*)"/', strtolower($html), $matches);

        foreach ($matches[1] as $haystack) {
            if (str_contains($haystack, strtolower($user->name))) {
                return $haystack;
            }
        }

        return '';
    }
}
