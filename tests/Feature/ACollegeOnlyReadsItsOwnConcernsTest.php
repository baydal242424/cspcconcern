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
 * A concern is read by the desk it sits on, and by nobody else.
 *
 * This file used to guard a narrower rule: that a college-bound role read
 * its own college and no further. That rule existed because there were
 * standing queues to narrow -- every submitted teaching concern was open to
 * every Adviser, Program Chair and Dean in the student's college, a case
 * referred to "Faculty/Staff" was open to all nine of them, and the Head of
 * School read everything.
 *
 * The queues are gone. A Dean opening a report meant for a class adviser is
 * what settled it: nothing was leaking by accident, and a student's account
 * of being bullied was still readable by people they never wrote to. So the
 * college boundary no longer narrows a queue; there is no queue to narrow,
 * and what is left is stronger than the boundary ever was.
 *
 * What the tests below now check is that the old windows really are shut,
 * including for the people they were built for.
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
     * The roles that used to be bound to a college, and what each needs
     * recorded.
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

    /** The original bug, checked for every role it could have applied to. */
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

        fwrite(STDERR, "  [desk] {$role} from another college: cannot read it\n");
    }

    /**
     * Nor can their own college's staff, which is the part that changed.
     *
     * This asserted the opposite: that the student's own college could read
     * the case, because that was the queue. A concern not yet picked up is
     * now readable by its handler and its author, and by no one else in the
     * building.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('collegeBoundRoles')]
    public function test_their_own_colleges_staff_cannot_read_it_either(string $role, ?string $course): void
    {
        $concern = $this->aComputerStudiesCase();

        $insider = $this->person('Insider '.$role, $role, 'College of Computer Studies', $course);

        $this->assertFalse(
            Concern::visibleTo($insider)->where('id', $concern->id)->exists(),
            "a {$role} in the student's own college must not read a case that is not theirs"
        );

        $this->actingAs($insider)->get("/concerns/{$concern->id}")->assertForbidden();

        fwrite(STDERR, "  [desk] {$role} from their own college: cannot read it either\n");
    }

    /**
     * A referral is a hand-off to a person, not a posting to a role.
     *
     * "Referred to Dean" once reached every dean in the institution, then
     * every dean in one college. It now reaches the one who was given it.
     */
    public function test_a_referral_reaches_the_person_it_was_handed_to(): void
    {
        $theirs = $this->person('Their Dean', 'Dean', 'College of Computer Studies');
        $other = $this->person('Another Dean', 'Dean', 'College of Computer Studies');

        $concern = $this->aComputerStudiesCase([
            'status' => 'referred',
            'referred_to' => 'Dean',
            'assigned_to' => $theirs->id,
        ]);

        $this->assertTrue(Concern::visibleTo($theirs)->where('id', $concern->id)->exists());
        $this->assertFalse(
            Concern::visibleTo($other)->where('id', $concern->id)->exists(),
            'a referral must reach the dean it was handed to, not everyone holding the role'
        );

        fwrite(STDERR, "  [desk] a referral reaches one person, not a role\n");
    }

    /** Two chairs of one college, and the case belongs to one of them. */
    public function test_a_chair_of_another_programme_cannot_read_it(): void
    {
        $theirs = $this->person('IS Chair', 'Program Chair', 'College of Computer Studies', 'BS Information Systems');
        $other = $this->person('CS Chair', 'Program Chair', 'College of Computer Studies', 'BS Computer Science');

        $concern = $this->aComputerStudiesCase(['assigned_to' => $theirs->id]);

        $this->assertTrue(Concern::visibleTo($theirs)->where('id', $concern->id)->exists());
        $this->assertFalse(
            Concern::visibleTo($other)->where('id', $concern->id)->exists(),
            'a chair of another programme must not read this student\'s concern'
        );

        fwrite(STDERR, "  [desk] a chair of another programme cannot read it\n");
    }

    /**
     * Being handed the case is what grants the reading, wherever it came
     * from -- the system refers across colleges on purpose, and a recipient
     * who cannot open what they were given would make that useless.
     */
    public function test_being_given_the_case_is_what_opens_it(): void
    {
        $concern = $this->aComputerStudiesCase();

        $outsider = $this->person('Health Sciences Dean', 'Dean', 'College of Health Sciences');

        $this->assertFalse(Concern::visibleTo($outsider)->where('id', $concern->id)->exists());

        $concern->forceFill(['assigned_to' => $outsider->id])->save();

        $this->assertTrue(
            Concern::visibleTo($outsider)->where('id', $concern->id)->exists(),
            'a concern assigned to somebody must be readable by them'
        );

        fwrite(STDERR, "  [desk] being given the case is what opens it\n");
    }

    /** An escalation reaches the VPAA from any college, once it is hers. */
    public function test_the_vpaa_reads_escalations_handed_to_her_from_any_college(): void
    {
        $vpaa = User::whereHas('role', fn ($q) => $q->where('name', 'Vice President for Academic Affairs'))
            ->first() ?? $this->person('The VPAA', 'Vice President for Academic Affairs', 'Academic Affairs');

        foreach (['College of Computer Studies', 'College of Health Sciences'] as $college) {
            $concern = $this->aComputerStudiesCase([
                'department' => $college,
                'course' => null,
                'status' => 'referred',
                'referred_to' => 'Vice President for Academic Affairs',
                'assigned_to' => $vpaa->id,
            ]);

            $this->assertTrue(
                Concern::visibleTo($vpaa)->where('id', $concern->id)->exists(),
                "the VPAA should read an escalation handed to her from {$college}"
            );
        }

        fwrite(STDERR, "  [desk] the VPAA reads escalations handed to her, from any college\n");
    }

    /**
     * A concern with no college recorded is still reachable by its handler.
     *
     * The worry this guarded against was a concern the data could not place
     * falling out of every queue and sitting unread. There are no queues
     * now, so what keeps it reachable is the same thing that keeps any case
     * reachable: somebody holds it.
     */
    public function test_a_concern_with_no_college_is_still_reachable(): void
    {
        $dean = $this->person('Some Dean', 'Dean', 'College of Health Sciences');

        $concern = $this->aComputerStudiesCase([
            'department' => null,
            'course' => null,
            'assigned_to' => $dean->id,
        ]);

        $this->assertTrue(
            Concern::visibleTo($dean)->where('id', $concern->id)->exists(),
            'a concern the data cannot place must still be readable by whoever holds it'
        );

        fwrite(STDERR, "  [desk] a concern with no college recorded is still reachable\n");
    }
}
