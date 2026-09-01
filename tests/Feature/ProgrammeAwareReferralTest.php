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
 * A referral should land on the person who actually covers this student.
 *
 * Two tiers, and they are not the same question. "Refer to Dean" means the
 * dean of the student's COLLEGE, which works off the college snapshotted on
 * the concern. "Refer to Program Chair" means the chair of their PROGRAMME --
 * a BSIS student's concern belongs to the BSIS chair, not to whichever of the
 * four Computer Studies chairs happens to sort first.
 */
class ProgrammeAwareReferralTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, UserSeeder::class]);
    }

    private function u(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    private function chair(string $email, string $name, string $course): User
    {
        return User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('secret-not-used'),
            'role_id' => Role::where('name', 'Program Chair')->firstOrFail()->id,
            'department' => 'College of Computer Studies',
            'course' => $course,
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);
    }

    /** A BSIS student's concern reaches the BSIS chair, not another CCS chair. */
    public function test_referral_reaches_the_chair_of_the_students_programme(): void
    {
        // Three chairs in the same college. Only one covers this student.
        $cs = $this->chair('chair.bscs@cspc.edu.ph', 'BSCS Chair', 'BS Computer Science');
        $it = $this->chair('chair.bsit@cspc.edu.ph', 'BSIT Chair', 'BS Information Technology');
        $is = $this->chair('chair.bsis@cspc.edu.ph', 'BSIS Chair', 'BS Information Systems');

        // The BSCS chair is created FIRST, so a naive pick would land there.
        $this->assertTrue($cs->id < $is->id);

        $student = $this->u('student@my.cspc.edu.ph');
        $student->forceFill([
            'department' => 'College of Computer Studies',
            'course' => 'BS Information Systems',
        ])->save();

        $this->actingAs($student)->post('/concerns', [
            'category' => 'Academic',
            'description' => 'A problem with a subject in my programme this term.',
            'is_anonymous' => 0,
        ]);

        $concern = Concern::where('user_id', $student->id)->latest('id')->firstOrFail();
        $this->assertSame('BS Information Systems', $concern->course);

        $handler = $this->u('ccs.instructor@cspc.edu.ph');
        $concern->forceFill(['assigned_to' => $handler->id])->save();

        $this->actingAs($handler)->patch("/concerns/{$concern->id}", [
            'status' => 'referred',
            'referred_to' => 'Program Chair',
            'urgency' => 'Medium',
        ])->assertRedirect();

        $concern->refresh();
        $this->assertSame($is->id, $concern->assigned_to, 'A BSIS concern must reach the BSIS chair');
        $this->assertNotSame($cs->id, $concern->assigned_to);
        $this->assertNotSame($it->id, $concern->assigned_to);

        fwrite(STDERR, "  [chair] BSIS student -> BSIS chair (id={$concern->assigned_to}, not BSCS id={$cs->id})\n");
    }

    /** With no chair for that programme, it still reaches one in the college. */
    public function test_falls_back_to_the_college_when_no_chair_covers_the_programme(): void
    {
        $cs = $this->chair('chair.bscs@cspc.edu.ph', 'BSCS Chair', 'BS Computer Science');

        $student = $this->u('student@my.cspc.edu.ph');
        $student->forceFill([
            'department' => 'College of Computer Studies',
            'course' => 'BS Information Systems',
        ])->save();

        $concern = Concern::create([
            'user_id' => $student->id,
            'category' => 'Academic',
            'department' => 'College of Computer Studies',
            'course' => 'BS Information Systems',
            'description' => 'No chair exists for my programme yet.',
            'status' => 'submitted',
            'is_anonymous' => false,
            'assigned_to' => $this->u('ccs.instructor@cspc.edu.ph')->id,
        ]);

        $this->actingAs($this->u('ccs.instructor@cspc.edu.ph'))->patch("/concerns/{$concern->id}", [
            'status' => 'referred',
            'referred_to' => 'Program Chair',
            'urgency' => 'Medium',
        ])->assertRedirect();

        $this->assertSame($cs->id, $concern->refresh()->assigned_to);

        fwrite(STDERR, "  [fallback] no BSIS chair -> a chair in the same college: YES\n");
    }

    /** "Refer to Dean" still means the dean of the student's own college. */
    public function test_dean_referral_reaches_the_students_own_college(): void
    {
        $ccsDean = $this->u('ccs@cspc.edu.ph');
        $this->assertSame('Dean', $ccsDean->role->name);
        $this->assertSame('College of Computer Studies', $ccsDean->department);

        $student = $this->u('student@my.cspc.edu.ph');
        $student->forceFill([
            'department' => 'College of Computer Studies',
            'course' => 'BS Information Systems',
        ])->save();

        $concern = Concern::create([
            'user_id' => $student->id,
            'category' => 'Academic',
            'department' => 'College of Computer Studies',
            'course' => 'BS Information Systems',
            'description' => 'Something that needs the dean of my college.',
            'status' => 'submitted',
            'is_anonymous' => false,
            'assigned_to' => $this->u('ccs.instructor@cspc.edu.ph')->id,
        ]);

        $this->actingAs($this->u('ccs.instructor@cspc.edu.ph'))->patch("/concerns/{$concern->id}", [
            'status' => 'referred',
            'referred_to' => 'Dean',
            'urgency' => 'Medium',
        ])->assertRedirect();

        $this->assertSame($ccsDean->id, $concern->refresh()->assigned_to);

        fwrite(STDERR, "  [dean] CCS student -> CCS dean (id={$ccsDean->id}): YES\n");
    }

    /** The picker offers the covering chair first, so the default is the right one. */
    public function test_picker_lists_the_programmes_own_chair_first(): void
    {
        $cs = $this->chair('chair.bscs@cspc.edu.ph', 'AAA BSCS Chair', 'BS Computer Science');
        $is = $this->chair('chair.bsis@cspc.edu.ph', 'ZZZ BSIS Chair', 'BS Information Systems');

        $student = $this->u('student@my.cspc.edu.ph');
        $student->forceFill([
            'department' => 'College of Computer Studies',
            'course' => 'BS Information Systems',
        ])->save();

        $handler = $this->u('ccs.instructor@cspc.edu.ph');
        $concern = Concern::create([
            'user_id' => $student->id,
            'category' => 'Academic',
            'department' => 'College of Computer Studies',
            'course' => 'BS Information Systems',
            'description' => 'Ordering check for the people picker.',
            'status' => 'submitted',
            'is_anonymous' => false,
            'assigned_to' => $handler->id,
        ]);

        $html = $this->actingAs($handler)->get("/concerns/{$concern->id}")->getContent();

        // Named alphabetically last, but it covers this student, so it must
        // still be the first Program Chair offered.
        $posIs = strpos($html, 'value="'.$is->id.'" data-role="Program Chair"');
        $posCs = strpos($html, 'value="'.$cs->id.'" data-role="Program Chair"');
        $this->assertNotFalse($posIs, 'The covering chair should be offered at all');
        $this->assertNotFalse($posCs);
        $this->assertLessThan($posCs, $posIs, 'The covering chair should be listed before the others');

        fwrite(STDERR, "  [picker] covering chair listed first despite alphabetical order: YES\n");
    }
}
