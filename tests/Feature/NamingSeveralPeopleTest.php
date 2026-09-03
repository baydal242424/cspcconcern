<?php

namespace Tests\Feature;

use App\Models\Concern;
use App\Models\Role;
use App\Models\Section;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A concern can be about more than one person, and every one of them must be
 * walled out of it.
 *
 * concerns.about_staff_id held exactly one id, and the three "this concern is
 * about..." rows on the form unticked each other. A complaint about two
 * instructors could name one of them. The second was not merely unrecorded --
 * they stayed fully eligible to be assigned the concern, to read it, and to
 * write the resolution on a complaint about themselves. The form gave no sign
 * of it; it simply had one dropdown.
 */
class NamingSeveralPeopleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, UserSeeder::class]);
    }

    private function student(): User
    {
        return User::where('email', 'student@my.cspc.edu.ph')->firstOrFail();
    }

    private function staff(string $roleName, string $name): User
    {
        return User::factory()->create([
            'name' => $name,
            'role_id' => Role::where('name', $roleName)->firstOrFail()->id,
            'department' => 'College of Computer Studies',
            'status' => 'approved',
        ]);
    }

    public function test_every_person_named_is_kept_off_the_concern(): void
    {
        $first = $this->staff('Instructor', 'First Named Instructor');
        $second = $this->staff('Instructor', 'Second Named Instructor');
        $student = $this->student();

        $this->actingAs($student)->post('/concerns', [
            'category' => 'Academic',
            'description' => 'Both of my instructors marked this the same wrong way.',
            'about_staff_id' => [$first->id, $second->id],
            'is_anonymous' => 0,
        ])->assertRedirect();

        $concern = Concern::where('user_id', $student->id)->latest('id')->firstOrFail();

        $this->assertEqualsCanonicalizing(
            [$first->id, $second->id],
            $concern->subjectIds(),
            'Both named people must be recorded, not just the first'
        );

        $this->assertNotNull($concern->assigned_to, 'It still has to reach somebody');

        $this->assertNotContains(
            $concern->assigned_to,
            [$first->id, $second->id],
            'A concern must never be assigned to somebody it is about'
        );

        // The wall, which is the part that matters: neither can see it.
        foreach ([$first, $second] as $subject) {
            $this->assertFalse(
                Concern::visibleTo($subject)->whereKey($concern->id)->exists(),
                "{$subject->name} must not be able to read a concern about them"
            );

            $this->actingAs($subject)->get("/concerns/{$concern->id}")->assertForbidden();
        }

        fwrite(STDERR, '  [subjects] two named, both walled out, went to '
            .optional($concern->assignedUser)->name."\n");
    }

    /**
     * The three rows on the form combine rather than replacing each other:
     * an instructor, the student's own class adviser and a dean, all at once.
     */
    public function test_an_instructor_the_adviser_and_a_dean_can_be_named_together(): void
    {
        $student = $this->student();
        $student->update(['course' => 'BSIT', 'section' => '4A']);

        $instructor = $this->staff('Instructor', 'The Instructor');
        $adviser = $this->staff('Program Chair', 'The Class Adviser');
        $dean = $this->staff('Dean', 'The Dean');

        Section::create([
            'course' => 'BSIT',
            'section' => '4A',
            'school_year' => '2024-2025',
            'semester' => 'Second',
            'adviser_id' => $adviser->id,
        ]);

        $this->actingAs($student->refresh())->post('/concerns', [
            'category' => 'Academic',
            'description' => 'This involves my instructor, my class adviser and the dean.',
            'about_staff_id' => [$instructor->id, $adviser->id, $dean->id],
            'is_anonymous' => 0,
        ])->assertRedirect();

        $concern = Concern::where('user_id', $student->id)->latest('id')->firstOrFail();

        $this->assertCount(3, $concern->subjectIds());

        // Academic routes to the class adviser first. Named here, so it has to
        // go past them -- and past the other two.
        $this->assertNotContains(
            $concern->assigned_to,
            [$instructor->id, $adviser->id, $dean->id],
            'None of the three named people may receive it'
        );

        fwrite(STDERR, '  [subjects] instructor + adviser + dean named together, went to '
            .optional($concern->assignedUser)->name."\n");
    }

    /** One name still posts as it always did, from a form or anywhere else. */
    public function test_a_single_name_still_works_unchanged(): void
    {
        $subject = $this->staff('Instructor', 'The Only One Named');
        $student = $this->student();

        $this->actingAs($student)->post('/concerns', [
            'category' => 'Academic',
            'description' => 'A concern naming exactly one instructor, posted as a scalar.',
            'about_staff_id' => $subject->id,
            'is_anonymous' => 0,
        ])->assertRedirect();

        $concern = Concern::where('user_id', $student->id)->latest('id')->firstOrFail();

        $this->assertSame([$subject->id], $concern->subjectIds());
        $this->assertSame($subject->id, $concern->about_staff_id, 'The column still holds the first name');
        $this->assertNotSame($subject->id, $concern->assigned_to);

        fwrite(STDERR, "  [subjects] a single name still posts unchanged: YES\n");
    }

    /**
     * about_staff_id is derived from the list. If they could disagree, a
     * person could be walled out by one rule and handed the concern by
     * another.
     */
    public function test_the_column_and_the_list_cannot_disagree(): void
    {
        $first = $this->staff('Instructor', 'Named First');
        $second = $this->staff('Instructor', 'Named Second');

        $concern = Concern::create([
            'user_id' => $this->student()->id,
            'category' => 'Academic',
            'description' => 'A concern whose subject list is rewritten afterwards.',
            'department' => 'College of Computer Studies',
            'status' => 'pending',
        ]);

        $concern->syncSubjects([$first->id, $second->id]);
        $this->assertSame($first->id, $concern->fresh()->about_staff_id);

        // Rewritten to somebody else entirely: the column must follow.
        $concern->syncSubjects([$second->id]);
        $this->assertSame($second->id, $concern->fresh()->about_staff_id);
        $this->assertSame([$second->id], $concern->fresh()->subjectIds());

        // And cleared to nobody.
        $concern->syncSubjects([]);
        $this->assertNull($concern->fresh()->about_staff_id);
        $this->assertSame([], $concern->fresh()->subjectIds());

        fwrite(STDERR, "  [subjects] the column always follows the list: YES\n");
    }
}
