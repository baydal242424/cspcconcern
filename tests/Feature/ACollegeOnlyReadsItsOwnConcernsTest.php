<?php

namespace Tests\Feature;

use App\Models\Concern;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * A college-bound role reads its own college, and no further.
 *
 * Two windows had no college in them at all.
 *
 * The standing academic queue -- every SUBMITTED teaching concern -- was open
 * to every Adviser, Program Chair and Dean in the institution. The Dean of
 * Health Sciences could open a Computer Studies concern that had been
 * assigned to a Computer Studies chair, about a Computer Studies adviser.
 *
 * The referral window had the same hole: "referred to Dean" matched on the
 * word alone, so a hand-off meant for one college was readable by all seven.
 *
 * Being assigned a concern, or having handled one, still shows it whatever
 * the college says. Those are facts about the person, not about a queue.
 */
class ACollegeOnlyReadsItsOwnConcernsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, UserSeeder::class]);
    }

    private function person(string $name, string $role, ?string $college, ?string $course = null): User
    {
        return User::create([
            'name' => $name,
            'email' => \Illuminate\Support\Str::slug($name).'@cspc.edu.ph',
            'password' => Hash::make('not-used'),
            'role_id' => Role::where('name', $role)->firstOrFail()->id,
            'status' => 'approved',
            'department' => $college,
            'course' => $course,
        ]);
    }

    /** A submitted Academic concern from a Computer Studies student. */
    private function aComputerStudiesCase(array $overrides = []): Concern
    {
        return Concern::create(array_merge([
            'user_id' => User::where('email', 'student@my.cspc.edu.ph')->firstOrFail()->id,
            'category' => 'Academic',
            'department' => 'College of Computer Studies',
            'course' => 'BS Information Systems',
            'section' => '4A',
            'description' => 'Something happened in class.',
            'urgency' => 'Low',
            'status' => 'submitted',
            'is_anonymous' => false,
        ], $overrides));
    }

    /**
     * The roles bound to a college, and what each one needs recorded.
     *
     * @return array<string, array{0: string, 1: string|null}>
     */
    public static function collegeBoundRoles(): array
    {
        return [
            'a dean' => ['Dean', null],
            'a program chair' => ['Program Chair', 'BS Information Systems'],
            'an adviser' => ['Adviser', null],
            'an instructor' => ['Instructor', null],
            'a faculty or staff member' => ['Faculty/Staff', null],
        ];
    }

    /**
     * The reported bug, checked for every college-bound role rather than
     * only the dean it was noticed on.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('collegeBoundRoles')]
    public function test_another_colleges_staff_cannot_read_it(string $role, ?string $course): void
    {
        $concern = $this->aComputerStudiesCase();

        $outsider = $this->person('Outsider '.$role, $role, 'College of Health Sciences', $course);

        $this->assertFalse(
            Concern::visibleTo($outsider)->where('id', $concern->id)->exists(),
            "a {$role} in another college must not see this concern"
        );

        // Not just the query -- the page refuses them too.
        $this->actingAs($outsider)->get("/concerns/{$concern->id}")->assertForbidden();

        fwrite(STDERR, "  [college] {$role} from another college: cannot read it\n");
    }

    /** Their own college still works, which is the point of the queue. */
    #[\PHPUnit\Framework\Attributes\DataProvider('collegeBoundRoles')]
    public function test_their_own_colleges_staff_still_read_it(string $role, ?string $course): void
    {
        $concern = $this->aComputerStudiesCase();

        $insider = $this->person('Insider '.$role, $role, 'College of Computer Studies', $course);

        // Instructor and Faculty/Staff have no standing academic window at
        // all -- they work what is sent to them -- so the case has to be
        // referred before either can read it.
        if (in_array($role, ['Instructor', 'Faculty/Staff'], true)) {
            $concern->forceFill(['status' => 'referred', 'referred_to' => $role])->save();
        }

        $this->assertTrue(
            Concern::visibleTo($insider)->where('id', $concern->id)->exists(),
            "a {$role} in the student's own college should see this concern"
        );

        fwrite(STDERR, "  [college] {$role} from their own college: reads it\n");
    }

    /**
     * A referral names a role, and used to reach everybody holding it.
     *
     * "Referred to Dean" is a hand-off to one dean, in one college. It was
     * matching on the word, so the other six read it too.
     */
    public function test_a_referral_reaches_one_college_not_all_of_them(): void
    {
        $concern = $this->aComputerStudiesCase([
            'status' => 'referred',
            'referred_to' => 'Dean',
        ]);

        $theirs = $this->person('Their Dean', 'Dean', 'College of Computer Studies');
        $other = $this->person('Another Dean', 'Dean', 'College of Engineering and Architecture');

        $this->assertTrue(Concern::visibleTo($theirs)->where('id', $concern->id)->exists());
        $this->assertFalse(
            Concern::visibleTo($other)->where('id', $concern->id)->exists(),
            'a referral to "Dean" must not be readable by every dean in the institution'
        );

        fwrite(STDERR, "  [college] a referral reaches one college, not all seven\n");
    }

    /**
     * Inside one college, a chair reads their own programme.
     *
     * Computer Studies has four chairs. A chair of Computer Science has no
     * standing over an Information Systems student, which is the same rule
     * the referral picker already applies when choosing who to hand a case to.
     */
    public function test_a_chair_of_another_programme_cannot_read_it(): void
    {
        $concern = $this->aComputerStudiesCase();

        $theirs = $this->person('IS Chair', 'Program Chair', 'College of Computer Studies', 'BS Information Systems');
        $other = $this->person('CS Chair', 'Program Chair', 'College of Computer Studies', 'BS Computer Science');

        $this->assertTrue(Concern::visibleTo($theirs)->where('id', $concern->id)->exists());
        $this->assertFalse(
            Concern::visibleTo($other)->where('id', $concern->id)->exists(),
            'a chair of another programme must not read this student\'s concern'
        );

        fwrite(STDERR, "  [college] a chair of another programme cannot read it\n");
    }

    /**
     * The college boundary narrows a queue, never a person's own work.
     *
     * A concern handed to somebody stays readable by them wherever it came
     * from -- otherwise referring across colleges, which the system does on
     * purpose, would hand people cases they cannot open.
     */
    public function test_being_given_the_case_beats_the_college_boundary(): void
    {
        $concern = $this->aComputerStudiesCase();

        $outsider = $this->person('Health Sciences Dean', 'Dean', 'College of Health Sciences');

        $this->assertFalse(Concern::visibleTo($outsider)->where('id', $concern->id)->exists());

        $concern->forceFill(['assigned_to' => $outsider->id])->save();

        $this->assertTrue(
            Concern::visibleTo($outsider)->where('id', $concern->id)->exists(),
            'a concern assigned to somebody must be readable by them'
        );

        fwrite(STDERR, "  [college] being given the case beats the college boundary\n");
    }

    /**
     * The VPAA is institution-wide on purpose.
     *
     * There is one of her, and escalation is the whole of her queue, so
     * narrowing her to a college would leave escalated cases with nobody
     * above them.
     */
    public function test_the_vpaa_still_reads_escalations_from_every_college(): void
    {
        $vpaa = User::whereHas('role', fn ($q) => $q->where('name', 'Vice President for Academic Affairs'))
            ->first() ?? $this->person('The VPAA', 'Vice President for Academic Affairs', 'Academic Affairs');

        foreach (['College of Computer Studies', 'College of Health Sciences'] as $college) {
            $concern = $this->aComputerStudiesCase([
                'department' => $college,
                'course' => null,
                'status' => 'referred',
                'referred_to' => 'Vice President for Academic Affairs',
            ]);

            $this->assertTrue(
                Concern::visibleTo($vpaa)->where('id', $concern->id)->exists(),
                "the VPAA should read an escalation from {$college}"
            );
        }

        fwrite(STDERR, "  [college] the VPAA still reads escalations from every college\n");
    }

    /**
     * A concern with no college recorded stays reachable.
     *
     * Closing the window on missing data would make such a concern private
     * rather than safe: it would sit in a queue nobody can open, looking
     * handled.
     */
    public function test_a_concern_with_no_college_is_not_hidden_from_everybody(): void
    {
        $concern = $this->aComputerStudiesCase(['department' => null, 'course' => null]);

        $dean = $this->person('Some Dean', 'Dean', 'College of Health Sciences');

        $this->assertTrue(
            Concern::visibleTo($dean)->where('id', $concern->id)->exists(),
            'a concern the data cannot place must not become unreadable'
        );

        fwrite(STDERR, "  [college] a concern with no college recorded stays reachable\n");
    }
}
