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
 * Making somebody a class adviser from Manage Users.
 *
 * Choosing the Adviser role asks for a program, year and section -- and saving
 * writes them into the sections table, because that is the only place routing
 * looks. A section typed onto the account alone makes nobody an adviser.
 */
class AdminAssignsAdviserTest extends TestCase
{
    use RefreshDatabase;

    private const CCS = 'College of Computer Studies';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, UserSeeder::class, CcsFacultySeeder::class]);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@cspc.edu.ph')->firstOrFail();
    }

    private function role(string $name): int
    {
        return Role::where('name', $name)->firstOrFail()->id;
    }

    private function staff(string $email = 'future.adviser@cspc.edu.ph'): User
    {
        return User::create([
            'name' => 'Future Adviser',
            'email' => $email,
            'password' => Hash::make('not-used'),
            'role_id' => $this->role('Faculty/Staff'),
            'department' => self::CCS,
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);
    }

    private function makeAdviser(User $person, string $course, string $year, string $letter, string $college = self::CCS)
    {
        return $this->actingAs($this->admin())
            ->from('/admin/users')
            ->post("/admin/users/{$person->id}/role", [
                'role_id' => $this->role('Adviser'),
                'department' => $college,
                'course' => $course,
                'year' => $year,
                'section_letter' => $letter,
            ]);
    }

    public function test_choosing_adviser_records_them_as_the_class_adviser(): void
    {
        $person = $this->staff();

        $this->makeAdviser($person, 'BS Information Systems', '4', 'A')->assertSessionHasNoErrors();

        $this->assertSame('Adviser', $person->refresh()->role->name);
        $this->assertSame($person->id, optional(Section::adviserFor('BS Information Systems', '4A'))->id);

        fwrite(STDERR, "  [adviser] Adviser + BSIS + 4 + A -> recorded as the class adviser of BSIS 4A\n");
    }

    public function test_a_student_of_that_section_reaches_them(): void
    {
        $person = $this->staff();
        $this->makeAdviser($person, 'BS Information Systems', '4', 'A');

        $student = User::where('email', 'student@my.cspc.edu.ph')->firstOrFail();
        $student->forceFill(['department' => self::CCS, 'course' => 'BS Information Systems', 'section' => '4A'])->save();

        $this->actingAs($student->refresh())->post('/concerns', [
            'category' => 'Academic',
            'description' => 'A concern from BSIS 4A, to see who receives it.',
        ]);

        $this->assertSame($person->id, Concern::latest('id')->firstOrFail()->assigned_to);

        fwrite(STDERR, "  [adviser] a BSIS 4A student's Academic concern reaches the new adviser\n");
    }

    public function test_moving_them_to_another_section_releases_the_old_one(): void
    {
        $person = $this->staff();
        $this->makeAdviser($person, 'BS Information Systems', '4', 'A');
        $this->makeAdviser($person, 'BS Information Systems', '3', 'B');

        $this->assertNull(Section::adviserFor('BS Information Systems', '4A'), '4A is released');
        $this->assertSame($person->id, optional(Section::adviserFor('BS Information Systems', '3B'))->id);

        fwrite(STDERR, "  [adviser] moved 4A -> 3B: 4A released, 3B theirs\n");
    }

    public function test_making_them_a_student_again_releases_every_section(): void
    {
        $person = $this->staff();
        $this->makeAdviser($person, 'BS Information Systems', '4', 'A');

        $this->actingAs($this->admin())->post("/admin/users/{$person->id}/role", [
            'role_id' => $this->role('Student'),
            'department' => self::CCS,
            'course' => 'BS Information Systems',
            'year' => '4',
            'section_letter' => 'A',
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, Section::where('adviser_id', $person->id)->count());

        fwrite(STDERR, "  [adviser] back to Student -> advises nothing\n");
    }

    public function test_the_program_must_belong_to_the_chosen_college(): void
    {
        $person = $this->staff();

        $this->makeAdviser($person, 'BS Nursing', '1', 'A', self::CCS)->assertSessionHasErrors('course');

        $this->assertNull(Section::adviserFor('BS Nursing', '1A'));

        fwrite(STDERR, "  [adviser] BS Nursing under Computer Studies is refused\n");
    }

    /** One Adviser account must not catch every college's unadvised sections. */
    public function test_the_adviser_role_never_reaches_another_college(): void
    {
        $person = $this->staff();
        $this->makeAdviser($person, 'BS Information Systems', '4', 'A');

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

        $this->assertNotSame($person->id, Concern::latest('id')->firstOrFail()->assigned_to);

        fwrite(STDERR, "  [adviser] a Computer Studies adviser never receives a Nursing concern\n");
    }

    public function test_the_card_lists_the_sections_they_advise(): void
    {
        $person = $this->staff();
        $this->makeAdviser($person, 'BS Information Systems', '4', 'A');

        $this->actingAs($this->admin())->get('/admin/users')
            ->assertOk()
            ->assertSee('Advises BS Information Systems 4A');

        fwrite(STDERR, "  [adviser] Manage Users shows \"Advises BS Information Systems 4A\"\n");
    }

    /**
     * Almost every adviser holds another role, so the search has to find them
     * by what they advise -- the word "adviser", and the section itself.
     */
    public function test_search_finds_an_adviser_by_the_word_and_by_their_section(): void
    {
        $person = $this->staff();
        $this->makeAdviser($person, 'BS Information Systems', '4', 'A');

        // A plain Instructor who advises nothing must not match "adviser".
        $plain = $this->staff('not.an.adviser@cspc.edu.ph');

        $html = $this->actingAs($this->admin())->get('/admin/users')->assertOk()->getContent();

        preg_match_all('/data-search="([^"]*)"/', $html, $matches);
        $haystacks = collect($matches[1]);

        $this->assertTrue(
            $haystacks->contains(fn ($h) => str_contains($h, 'future adviser') && str_contains($h, 'adviser') && str_contains($h, 'bs information systems 4a')),
            'the adviser is findable by "adviser" and by "bs information systems 4a"'
        );
        $this->assertFalse(
            $haystacks->contains(fn ($h) => str_contains($h, 'not.an.adviser@') && str_contains($h, 'class adviser')),
            'somebody advising nothing is not'
        );

        fwrite(STDERR, "  [adviser] search finds advisers by \"adviser\" and by the section they advise\n");
    }
}
