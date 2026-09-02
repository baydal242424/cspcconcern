<?php

namespace Tests\Feature;

use App\Models\Concern;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Category picks the handling ROLE; the concern's college then picks WHICH
 * person in that role owns it. The college must never override the role --
 * a mental-health case belongs to a counselor no matter which college it
 * came from.
 */
class DepartmentRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, UserSeeder::class]);
    }

    /**
     * File a concern as the demo student, after putting them in $college.
     * The concern's department is taken from the account, never the request.
     */
    private function submit(array $overrides = [], ?string $college = null): Concern
    {
        $student = User::where('email', 'student@my.cspc.edu.ph')->firstOrFail();

        if ($college !== null) {
            $student->update(['department' => $college]);
        }

        $data = array_merge([
            'category' => 'Academic',
            'description' => 'a concern with a description long enough to pass validation',
        ], $overrides);

        $this->actingAs($student)->post('/concerns', $data);

        return Concern::where('description', $data['description'])->firstOrFail();
    }

    private function handler(Concern $c): ?User
    {
        return User::find($c->assigned_to);
    }

    public function test_concern_inherits_the_reporters_college(): void
    {
        $c = $this->submit([], 'College of Computer Studies');

        $this->assertSame('College of Computer Studies', $c->department);
    }

    public function test_academic_concern_goes_to_an_instructor_of_that_college(): void
    {
        $c = $this->submit([], 'College of Computer Studies');

        $this->assertSame('ccs.instructor@cspc.edu.ph', $this->handler($c)->email);
        $this->assertSame('College of Computer Studies', $this->handler($c)->department);
    }

    public function test_a_different_college_gets_its_own_instructor(): void
    {
        $c = $this->submit([
            'description' => 'a health sciences concern with a sufficiently long description',
        ], 'College of Health Sciences');

        $this->assertSame('chs.instructor@cspc.edu.ph', $this->handler($c)->email);
    }

    public function test_college_without_an_instructor_falls_back_to_the_catch_all(): void
    {
        // Arts and Sciences has an instructor seeded; remove them so the
        // college has nobody in the Instructor role of its own.
        User::where('email', 'cas.instructor@cspc.edu.ph')->delete();

        $c = $this->submit([
            'description' => 'an arts and sciences concern with no instructor to take it',
        ], 'College of Arts and Sciences');

        $this->assertSame('staff@cspc.edu.ph', $this->handler($c)->email);
    }

    public function test_category_still_beats_department(): void
    {
        // A mental-health concern from a college with its own instructor must
        // STILL reach the counselor -- the college only narrows within a role.
        $c = $this->submit([
            'category' => 'Mental Health / Personal',
            'description' => 'a mental health concern raised by a computer studies student',
        ], 'College of Computer Studies');

        $this->assertSame('Guidance Counselor', optional($this->handler($c)->role)->name);
        $this->assertSame('counselor@cspc.edu.ph', $this->handler($c)->email);
    }

    public function test_referral_to_department_head_reaches_that_colleges_dean(): void
    {
        // A Health Sciences student's case, referred by the counselor to
        // "Dean", must reach the CHS dean -- not whichever dean
        // happens to have the lowest user id.
        $c = $this->submit([
            'category' => 'Bullying',
            'description' => 'a bullying concern from a health sciences student',
        ], 'College of Health Sciences');

        $counselor = User::where('email', 'counselor@cspc.edu.ph')->firstOrFail();
        $this->assertSame($counselor->id, $c->assigned_to, 'Should start with the counselor');

        $this->actingAs($counselor)->patch("/concerns/{$c->id}", [
            'status' => 'referred', 'referred_to' => 'Dean', 'urgency' => 'Medium',
        ]);

        $c->refresh();
        $this->assertSame('chs@cspc.edu.ph', $this->handler($c)->email);
        $this->assertSame('College of Health Sciences', $this->handler($c)->department);
    }

    public function test_referral_falls_back_when_that_college_has_no_dean(): void
    {
        User::where('email', 'chs@cspc.edu.ph')->delete();

        $c = $this->submit([
            'category' => 'Bullying',
            'description' => 'a bullying concern with no dean of its own to receive it',
        ], 'College of Health Sciences');

        $counselor = User::where('email', 'counselor@cspc.edu.ph')->firstOrFail();
        $this->actingAs($counselor)->patch("/concerns/{$c->id}", [
            'status' => 'referred', 'referred_to' => 'Dean', 'urgency' => 'Medium',
        ]);

        $c->refresh();
        $this->assertSame('Dean', optional($this->handler($c)->role)->name);
    }

    public function test_escalation_prefers_the_dean_of_the_same_college(): void
    {
        // Remove every Instructor so the conflict-of-interest path has to
        // escalate, then check it picks the CCS dean rather than any dean.
        $reported = User::where('email', 'ccs.instructor@cspc.edu.ph')->firstOrFail();
        User::whereHas('role', fn ($q) => $q->where('name', 'Instructor'))
            ->where('id', '!=', $reported->id)->delete();

        $c = $this->submit([
            'description' => 'a conflicted concern that has to escalate to a dean',
            'about_staff_id' => $reported->id,
        ], 'College of Computer Studies');

        $this->assertSame('ccs@cspc.edu.ph', $this->handler($c)->email);
        $this->assertSame('College of Computer Studies', $this->handler($c)->department);
    }
}
