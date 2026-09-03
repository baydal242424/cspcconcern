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
 * An academic concern reaches the student's OWN class adviser.
 *
 * "Adviser" is not a pool. The person who advises BSIT 3A is the one who knows
 * that section, and reaching a different adviser in the same college is the
 * failure this exists to prevent. The fallbacks matter as much: a student with
 * no section, or a section nobody advises, must still reach somebody.
 */
class SectionAdviserRoutingTest extends TestCase
{
    use RefreshDatabase;

    private const COURSE = 'BS Information Technology';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, UserSeeder::class]);
    }

    private function staff(string $roleName, string $email, string $college = 'College of Computer Studies'): User
    {
        return User::create([
            'name' => "Test {$roleName} {$email}",
            'email' => $email,
            'password' => Hash::make('not-used'),
            'role_id' => Role::where('name', $roleName)->firstOrFail()->id,
            'department' => $college,
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);
    }

    private function student(?string $section = null): User
    {
        $s = User::where('email', 'student@my.cspc.edu.ph')->firstOrFail();
        $s->forceFill([
            'department' => 'College of Computer Studies',
            'course' => self::COURSE,
            'section' => $section,
        ])->save();

        return $s;
    }

    private function file(User $student): Concern
    {
        $this->actingAs($student)->post('/concerns', [
            'category' => 'Academic',
            'description' => 'A concern filed to see which adviser it reaches.',
            'is_anonymous' => 0,
        ]);

        return Concern::latest('id')->firstOrFail();
    }

    private function section(string $section, User $adviser, string $semester = 'Second'): void
    {
        Section::create([
            'course' => self::COURSE,
            'section' => $section,
            'school_year' => '2024-2025',
            'semester' => $semester,
            'adviser_id' => $adviser->id,
        ]);
    }

    /** The adviser of their section, not another adviser in the college. */
    public function test_it_reaches_the_advisor_of_the_students_own_section(): void
    {
        $mine = $this->staff('Instructor', 'adviser.3a@cspc.edu.ph');
        $someoneElse = $this->staff('Adviser', 'adviser.other@cspc.edu.ph');

        $this->section('3A', $mine);
        $this->section('1A', $someoneElse);

        $concern = $this->file($this->student('3A'));

        $this->assertSame($mine->id, $concern->assigned_to);
        $this->assertNotSame($someoneElse->id, $concern->assigned_to);

        fwrite(STDERR, "  [section] BSIT 3A -> its own adviser, not another\n");
    }

    /** The section is snapshotted, so it survives the student moving on. */
    public function test_the_section_is_recorded_on_the_concern(): void
    {
        $this->section('3A', $this->staff('Instructor', 'adviser.3a@cspc.edu.ph'));
        $student = $this->student('3A');

        $concern = $this->file($student);
        $this->assertSame('3A', $concern->section);

        // They move up a year; the concern still says which section filed it.
        $student->forceFill(['section' => '4A'])->save();
        $this->assertSame('3A', $concern->fresh()->section);

        fwrite(STDERR, "  [snapshot] the concern keeps the section it was filed from\n");
    }

    /** The newest term wins when an assignment has changed. */
    public function test_the_most_recent_term_decides(): void
    {
        $lastTerm = $this->staff('Instructor', 'adviser.first@cspc.edu.ph');
        $thisTerm = $this->staff('Instructor', 'adviser.second@cspc.edu.ph');

        $this->section('1D', $lastTerm, 'First');
        $this->section('1D', $thisTerm, 'Second');

        $concern = $this->file($this->student('1D'));

        $this->assertSame($thisTerm->id, $concern->assigned_to);

        fwrite(STDERR, "  [term] the current semester's adviser, not last term's\n");
    }

    /** No section recorded: fall back to an adviser in their college. */
    public function test_without_a_section_it_falls_back_to_a_college_advisor(): void
    {
        $collegeAdviser = $this->staff('Adviser', 'adviser.ccs@cspc.edu.ph');

        $concern = $this->file($this->student(null));

        $this->assertSame($collegeAdviser->id, $concern->assigned_to);

        fwrite(STDERR, "  [fallback] no section -> an adviser of their college\n");
    }

    /** A section nobody advises behaves the same way. */
    public function test_an_unadvised_section_falls_back_too(): void
    {
        $collegeAdviser = $this->staff('Adviser', 'adviser.ccs@cspc.edu.ph');

        $concern = $this->file($this->student('6Z'));

        $this->assertSame($collegeAdviser->id, $concern->assigned_to);

        fwrite(STDERR, "  [fallback] a section nobody advises -> an adviser of their college\n");
    }

    /**
     * The conflict-of-interest wall still applies. A student reporting their
     * own class adviser must not have it routed straight back to them.
     */
    public function test_the_advisor_never_receives_a_concern_about_themselves(): void
    {
        $adviser = $this->staff('Instructor', 'adviser.3a@cspc.edu.ph');
        $this->section('3A', $adviser);

        $student = $this->student('3A');

        $this->actingAs($student)->post('/concerns', [
            'category' => 'Academic',
            'description' => 'A concern about how my class adviser handled something.',
            'about_staff_id' => $adviser->id,
            'is_anonymous' => 0,
        ]);

        $concern = Concern::latest('id')->firstOrFail();

        $this->assertNotSame($adviser->id, $concern->assigned_to);
        $this->assertNotNull($concern->assigned_to, 'it must still reach somebody');

        fwrite(STDERR, "  [wall] a concern about the adviser goes elsewhere\n");
    }

    /** Students can record a section, and it is normalised. */
    public function test_a_student_can_record_their_section(): void
    {
        $student = User::where('email', 'student2@my.cspc.edu.ph')->firstOrFail();
        $student->forceFill(['student_id' => null, 'course' => null, 'section' => null])->save();

        $this->actingAs($student)->post('/complete-profile', [
            'student_id' => '2026-00123',
            'department' => 'College of Computer Studies',
            'course' => self::COURSE,
            'section' => '3a',
        ])->assertRedirect();

        $this->assertSame('3A', $student->fresh()->section);

        fwrite(STDERR, "  [profile] section recorded and upper-cased\n");
    }

    /** Nonsense is refused rather than stored. */
    public function test_a_malformed_section_is_rejected(): void
    {
        $student = User::where('email', 'student2@my.cspc.edu.ph')->firstOrFail();

        $this->actingAs($student)->post('/complete-profile', [
            'student_id' => '2026-00123',
            'department' => 'College of Computer Studies',
            'course' => self::COURSE,
            'section' => 'third year A',
        ])->assertSessionHasErrors('section');

        fwrite(STDERR, "  [profile] 'third year A' refused\n");
    }
}
