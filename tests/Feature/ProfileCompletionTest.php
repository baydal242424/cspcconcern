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
        ])->assertSessionHasErrors('course');
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
