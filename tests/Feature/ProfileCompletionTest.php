<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A concern's department comes from the reporter's account, so a student
 * without a college cannot file one that routes correctly. Students created
 * by CSPC Mail sign-in skip the registration form, so they are held at a
 * completion form until they supply their college and course.
 */
class ProfileCompletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, UserSeeder::class]);
    }

    /** A student provisioned by Google sign-in: no college, no course. */
    private function googleStudent(): User
    {
        return User::create([
            'name' => 'Google Student',
            'email' => 'gstudent@my.cspc.edu.ph',
            'password' => bcrypt('x'),
            'role_id' => Role::where('name', 'Student')->value('id'),
            'google_id' => 'g-123',
            'status' => 'approved',
        ]);
    }

    public function test_incomplete_student_is_redirected_to_the_completion_form(): void
    {
        $this->actingAs($this->googleStudent())
            ->get('/concerns')
            ->assertRedirect(route('profile.complete'));
    }

    public function test_incomplete_student_cannot_file_a_concern(): void
    {
        $this->actingAs($this->googleStudent())->post('/concerns', [
            'category' => 'Academic',
            'description' => 'a concern filed before the profile was completed',
        ])->assertRedirect(route('profile.complete'));

        $this->assertDatabaseMissing('concerns', [
            'description' => 'a concern filed before the profile was completed',
        ]);
    }

    public function test_completing_the_profile_unblocks_the_app(): void
    {
        $student = $this->googleStudent();

        $this->actingAs($student)->post('/complete-profile', [
            'student_id' => '2024-00999',
            'department' => 'College of Computer Studies',
            'course' => 'BS Information Technology',
            'section' => '3A',
        ])->assertRedirect(route('concerns.index'));

        $student->refresh();
        $this->assertSame('College of Computer Studies', $student->department);
        $this->assertSame('BS Information Technology', $student->course);

        $this->actingAs($student)->get('/concerns')->assertOk();
    }

    public function test_completion_rejects_a_course_from_another_college(): void
    {
        $this->actingAs($this->googleStudent())->post('/complete-profile', [
            'student_id' => '2024-00999',
            'department' => 'College of Computer Studies',
            'course' => 'BS Nursing',
            'section' => '3A',
        ])->assertSessionHasErrors('course');
    }

    /**
     * Signing up without a section is refused, and an account already missing
     * one is asked for it.
     *
     * Section was optional, and a student who skipped it reached a filing form
     * with the "This concern is about my class adviser" row silently absent --
     * the adviser is found through the section, so with no section there was
     * nobody to name and nothing on screen explaining the gap. Their Academic,
     * Physical, Safety and Others concerns also fell to college-level routing
     * rather than reaching the person who actually teaches them.
     */
    public function test_a_student_cannot_finish_signing_up_without_a_section(): void
    {
        $student = $this->googleStudent();

        $this->actingAs($student)->post('/complete-profile', [
            'student_id' => '2024-00999',
            'department' => 'College of Computer Studies',
            'course' => 'BS Information Technology',
        ])->assertSessionHasErrors('section');

        $this->assertNull($student->fresh()->section);

        fwrite(STDERR, "  [gate] sign-up refuses to finish without a section: YES\n");
    }

    /** An existing account without one is stopped until it has one. */
    public function test_a_student_already_missing_a_section_is_asked_for_it(): void
    {
        $student = User::where('email', 'student@my.cspc.edu.ph')->firstOrFail();
        $student->forceFill(['section' => null])->save();

        $this->actingAs($student)->get('/concerns')
            ->assertRedirect(route('profile.complete'));

        fwrite(STDERR, "  [gate] an account with no section is asked on next sign-in: YES\n");
    }

    public function test_a_complete_student_is_never_redirected(): void
    {
        $this->actingAs(User::where('email', 'student@my.cspc.edu.ph')->firstOrFail())
            ->get('/concerns')
            ->assertOk();
    }

    public function test_staff_are_not_held_by_the_gate(): void
    {
        // Staff have no course and their department is an office, not a
        // college -- the gate must only ever apply to Students.
        $this->actingAs(User::where('email', 'staff@cspc.edu.ph')->firstOrFail())
            ->get('/concerns')
            ->assertOk();
    }
}
