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
 * Two things that must not be duplicated: an ID number, and a concern.
 *
 * A student number is what the college's own records key on, so two accounts
 * carrying one stops it identifying anybody. And one problem filed twice is
 * two cases routed and assigned separately, where a handler resolves one
 * while the other sits open.
 */
class NoDuplicateIdsOrConcernsTest extends TestCase
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

    private function student(): User
    {
        $student = User::where('email', 'student@my.cspc.edu.ph')->firstOrFail();

        $student->forceFill([
            'department' => 'College of Computer Studies',
            'course' => 'BS Information Technology',
            'section' => '3A',
            'student_id' => '231000001',
        ])->save();

        return $student->refresh();
    }

    // ------------------------------------------------------------- ID numbers

    public function test_two_accounts_cannot_share_a_student_number(): void
    {
        $this->student();

        $other = User::create([
            'name' => 'Another Student',
            'email' => 'another@my.cspc.edu.ph',
            'password' => Hash::make('not-used'),
            'role_id' => Role::where('name', 'Student')->firstOrFail()->id,
            'status' => 'approved',
            'department' => 'College of Computer Studies',
        ]);

        $this->actingAs($this->admin())
            ->post("/admin/users/{$other->id}/role", [
                'role_id' => Role::where('name', 'Student')->firstOrFail()->id,
                'department' => 'College of Computer Studies',
                'student_id' => '231000001',   // already somebody else's
            ])
            ->assertSessionHasErrors('student_id');

        $this->assertNull($other->fresh()->student_id, 'the number must not have been taken');

        fwrite(STDERR, "  [unique] a student number already in use is refused\n");
    }

    public function test_two_accounts_cannot_share_a_staff_number(): void
    {
        $first = User::create([
            'name' => 'First Staff',
            'email' => 'first.staff@cspc.edu.ph',
            'password' => Hash::make('not-used'),
            'role_id' => Role::where('name', 'Instructor')->firstOrFail()->id,
            'status' => 'approved',
            'department' => 'College of Computer Studies',
            'employee_id' => 'EMP-0001',
        ]);

        $second = User::create([
            'name' => 'Second Staff',
            'email' => 'second.staff@cspc.edu.ph',
            'password' => Hash::make('not-used'),
            'role_id' => Role::where('name', 'Instructor')->firstOrFail()->id,
            'status' => 'approved',
            'department' => 'College of Computer Studies',
        ]);

        $this->actingAs($this->admin())
            ->post("/admin/users/{$second->id}/role", [
                'role_id' => Role::where('name', 'Instructor')->firstOrFail()->id,
                'department' => 'College of Computer Studies',
                'employee_id' => 'EMP-0001',
            ])
            ->assertSessionHasErrors('employee_id');

        $this->assertSame('EMP-0001', $first->fresh()->employee_id);
        $this->assertNull($second->fresh()->employee_id);

        fwrite(STDERR, "  [unique] a staff number already in use is refused\n");
    }

    /** Saving somebody's own row without changing their number is fine. */
    public function test_keeping_your_own_number_is_not_a_duplicate(): void
    {
        $student = $this->student();

        $this->actingAs($this->admin())
            ->post("/admin/users/{$student->id}/role", [
                'role_id' => Role::where('name', 'Student')->firstOrFail()->id,
                'department' => 'College of Computer Studies',
                'course' => 'BS Information Technology',
                'year' => 3,
                'section_letter' => 'A',
                'student_id' => '231000001',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('231000001', $student->fresh()->student_id);

        fwrite(STDERR, "  [unique] keeping your own number is not a clash with yourself\n");
    }

    // ---------------------------------------------------------- duplicate concerns

    public function test_the_same_concern_cannot_be_filed_twice(): void
    {
        $student = $this->student();

        $payload = [
            'category' => 'Academic',
            'description' => 'Our subject has had no instructor for three weeks now.',
        ];

        $this->actingAs($student)->post('/concerns', $payload)->assertRedirect();
        $first = Concern::latest('id')->firstOrFail();

        // The same form posted again -- a double click, or a refresh.
        $this->actingAs($student)->post('/concerns', $payload)
            ->assertRedirect(route('concerns.show', $first));

        $this->assertSame(1, Concern::count(), 'only one concern should exist');
        $this->assertStringContainsString('already submitted this concern', session('error'));
        $this->assertStringContainsString('#'.$first->id, session('error'));

        fwrite(STDERR, "  [duplicate] the same concern filed twice is refused, and names the first one\n");
    }

    /** Capitals and stray spaces do not make it a different concern. */
    public function test_retyping_it_differently_is_still_the_same_concern(): void
    {
        $student = $this->student();

        $this->actingAs($student)->post('/concerns', [
            'category' => 'Academic',
            'description' => 'Our subject has had no instructor for three weeks now.',
        ])->assertRedirect();

        $this->actingAs($student)->post('/concerns', [
            'category' => 'Academic',
            'description' => '   OUR SUBJECT HAS HAD NO INSTRUCTOR FOR THREE WEEKS NOW.  ',
        ])->assertRedirect();

        $this->assertSame(1, Concern::count());

        fwrite(STDERR, "  [duplicate] the same text in capitals is still the same concern\n");
    }

    /** A different concern, or a different category, still goes through. */
    public function test_a_genuinely_different_concern_still_goes_through(): void
    {
        $student = $this->student();

        $this->actingAs($student)->post('/concerns', [
            'category' => 'Academic',
            'description' => 'Our subject has had no instructor for three weeks now.',
        ])->assertRedirect();

        $this->actingAs($student)->post('/concerns', [
            'category' => 'Academic',
            'description' => 'A different problem entirely, about the laboratory schedule.',
        ])->assertRedirect();

        // Same words, different category: a different office handles it.
        $this->actingAs($student)->post('/concerns', [
            'category' => 'Safety',
            'description' => 'Our subject has had no instructor for three weeks now.',
        ])->assertRedirect();

        $this->assertSame(3, Concern::count());

        fwrite(STDERR, "  [duplicate] a different concern, or a different category, still files\n");
    }

    /**
     * Once the first one is finished, the same complaint may be filed again.
     * A recurring problem is a new report, not a duplicate.
     */
    public function test_it_can_be_filed_again_once_the_first_is_settled(): void
    {
        $student = $this->student();

        $payload = [
            'category' => 'Academic',
            'description' => 'Our subject has had no instructor for three weeks now.',
        ];

        $this->actingAs($student)->post('/concerns', $payload)->assertRedirect();

        Concern::latest('id')->firstOrFail()->forceFill([
            'status' => 'resolved',
            'resolved_at' => now(),
        ])->save();

        $this->actingAs($student)->post('/concerns', $payload)->assertRedirect();

        $this->assertSame(2, Concern::count(), 'a recurring problem is a new report');

        fwrite(STDERR, "  [duplicate] once the first is settled, the same problem can be reported again\n");
    }

    /** One student's concern never blocks another's. */
    public function test_another_student_filing_the_same_thing_is_not_a_duplicate(): void
    {
        $student = $this->student();

        $classmate = User::create([
            'name' => 'A Classmate',
            'email' => 'classmate@my.cspc.edu.ph',
            'password' => Hash::make('not-used'),
            'role_id' => Role::where('name', 'Student')->firstOrFail()->id,
            'status' => 'approved',
            'department' => 'College of Computer Studies',
            'course' => 'BS Information Technology',
            'section' => '3A',
            'student_id' => '231000002',
        ]);

        $payload = [
            'category' => 'Academic',
            'description' => 'Our subject has had no instructor for three weeks now.',
        ];

        $this->actingAs($student)->post('/concerns', $payload)->assertRedirect();
        $this->actingAs($classmate)->post('/concerns', $payload)->assertRedirect();

        $this->assertSame(2, Concern::count(), 'two students reporting the same thing are two reports');

        fwrite(STDERR, "  [duplicate] two classmates reporting the same problem are two reports\n");
    }
}
