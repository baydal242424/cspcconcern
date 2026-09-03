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
 * Academic, Physical, Safety and Others reach a student's adviser first.
 *
 * The adviser is the tier a student actually meets, above the instructor and
 * below the programme chair. The interesting cases are the two edges: what
 * happens in a college that has an adviser, and in one that has not named one
 * yet.
 */
class AdviserRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, UserSeeder::class]);
    }

    private function personIn(string $roleName, string $college, string $email): User
    {
        return User::create([
            'name' => "{$roleName} of {$college}",
            'email' => $email,
            'password' => Hash::make('not-used'),
            'role_id' => Role::where('name', $roleName)->firstOrFail()->id,
            'department' => $college,
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);
    }

    private function fileAs(User $student, string $category): Concern
    {
        $this->actingAs($student)->post('/concerns', [
            'category' => $category,
            'description' => 'A concern filed to see which tier it reaches.',
            'is_anonymous' => 0,
        ]);

        return Concern::latest('id')->firstOrFail();
    }

    private function student(): User
    {
        return User::where('email', 'student@my.cspc.edu.ph')->firstOrFail();
    }

    /** The adviser of the student's own college takes it. */
    public function test_academic_reaches_the_advisor_of_the_students_college(): void
    {
        $mine = $this->personIn('Adviser', 'College of Computer Studies', 'adviser.ccs@cspc.edu.ph');
        $elsewhere = $this->personIn('Adviser', 'College of Health Sciences', 'adviser.chs@cspc.edu.ph');

        $concern = $this->fileAs($this->student(), 'Academic');

        $this->assertSame($mine->id, $concern->assigned_to);
        $this->assertNotSame($elsewhere->id, $concern->assigned_to);

        fwrite(STDERR, "  [adviser] Academic -> the adviser of the student's own college\n");
    }

    /** All four categories the adviser now owns behave the same way. */
    public function test_the_adviser_takes_all_four_categories(): void
    {
        $adviser = $this->personIn('Adviser', 'College of Computer Studies', 'adviser.ccs@cspc.edu.ph');

        foreach (['Academic', 'Physical', 'Safety', 'Others'] as $category) {
            $concern = $this->fileAs($this->student(), $category);

            $this->assertSame(
                $adviser->id,
                $concern->assigned_to,
                "{$category} should reach the adviser"
            );
        }

        fwrite(STDERR, "  [adviser] Academic, Physical, Safety and Others all reach them\n");
    }

    /**
     * An adviser outranks an instructor, so having both must not send the
     * concern to the wrong one.
     */
    public function test_the_adviser_is_preferred_over_an_instructor(): void
    {
        $instructor = $this->personIn('Instructor', 'College of Computer Studies', 'inst.ccs@cspc.edu.ph');
        $adviser = $this->personIn('Adviser', 'College of Computer Studies', 'adviser.ccs@cspc.edu.ph');

        $concern = $this->fileAs($this->student(), 'Academic');

        $this->assertSame($adviser->id, $concern->assigned_to);
        $this->assertNotSame($instructor->id, $concern->assigned_to);

        fwrite(STDERR, "  [ladder] adviser preferred over an instructor in the same college\n");
    }

    /**
     * A college that has not named an adviser yet falls back to the tier
     * BELOW, not to its dean. This is what makes the change safe to deploy
     * before anybody has been assigned the role.
     */
    public function test_without_an_adviser_it_falls_back_to_an_instructor(): void
    {
        $this->personIn('Instructor', 'College of Computer Studies', 'inst.ccs@cspc.edu.ph');
        $this->assertSame(0, User::whereHas('role', fn ($q) => $q->where('name', 'Adviser'))->count());

        $concern = $this->fileAs($this->student(), 'Academic');
        $handler = $concern->assignedUser;

        // Any instructor of that college, not one specific person -- the
        // seeded college instructor and the one created here are equally
        // eligible, and which of them wins is sort order, not a rule.
        $this->assertNotNull($handler);
        $this->assertSame('Instructor', $handler->role->name);
        $this->assertSame('College of Computer Studies', $handler->department);

        fwrite(STDERR, "  [fallback] no adviser yet -> the instructor, not the dean\n");
    }

    /** With neither, it still reaches somebody rather than nobody. */
    public function test_with_neither_it_still_reaches_somebody(): void
    {
        User::whereHas('role', fn ($q) => $q->whereIn('name', ['Adviser', 'Instructor']))->delete();

        $concern = $this->fileAs($this->student(), 'Academic');

        $this->assertNotNull($concern->assigned_to, 'it must not be left unassigned');

        fwrite(STDERR, "  [escalate] no adviser and no instructor -> still assigned\n");
    }

    /** They work the shared queue; an instructor no longer does. */
    public function test_the_adviser_sees_the_open_queue_and_the_instructor_does_not(): void
    {
        $adviser = $this->personIn('Adviser', 'College of Computer Studies', 'adviser.ccs@cspc.edu.ph');
        $instructor = $this->personIn('Instructor', 'College of Computer Studies', 'inst.ccs@cspc.edu.ph');

        $unclaimed = Concern::create([
            'user_id' => $this->student()->id,
            'category' => 'Academic',
            'department' => 'College of Computer Studies',
            'description' => 'Unclaimed, sitting in the queue.',
            'status' => 'submitted',
            'is_anonymous' => false,
            'assigned_to' => null,
        ]);

        $this->assertTrue(Concern::whereKey($unclaimed->id)->visibleTo($adviser)->exists());
        $this->assertFalse(Concern::whereKey($unclaimed->id)->visibleTo($instructor)->exists());

        fwrite(STDERR, "  [queue] the adviser works it; the instructor is referral-gated\n");
    }
}
