<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The college/course pairing rules, which used to live on the registration
 * form. Self-registration was removed -- a student account is now created by
 * their first CSPC Mail sign-in -- so these details are collected on
 * /complete-profile instead, under the same validation.
 *
 * The pairing has to be checked as a pair: the form narrows the course list
 * client-side, but a hand-crafted POST must not be able to file a Computer
 * Studies student under the College of Health Sciences. That matters because
 * a concern inherits its reporter's college, and routeConcern() uses it to
 * pick a handler.
 */
class CompleteProfileCourseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    /**
     * A student straight out of a first CSPC Mail sign-in: approved and
     * signed in, but with no college or course yet.
     */
    private function googleProvisionedStudent(): User
    {
        return User::create([
            'name' => 'Juan Dela Cruz',
            'email' => 'juan@my.cspc.edu.ph',
            'password' => Hash::make(str()->random(40)),
            'role_id' => Role::where('name', 'Student')->value('id'),
            'google_id' => '1234567890',
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);
    }

    /** @return array<string, string> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'student_id' => '2023-00123',
            'department' => 'College of Computer Studies',
            'course' => 'BS Information Technology',
        ], $overrides);
    }

    public function test_the_form_lists_the_colleges_and_their_courses(): void
    {
        $this->actingAs($this->googleProvisionedStudent())
            ->get('/complete-profile')
            ->assertOk()
            ->assertSee('College of Computer Studies')
            ->assertSee('BS Information Technology')
            ->assertSee('BS Nursing')
            ->assertSee('Bachelor of Technical-Vocational Teacher Education');
    }

    public function test_it_stores_the_college_and_course(): void
    {
        $user = $this->googleProvisionedStudent();

        $this->actingAs($user)
            ->post('/complete-profile', $this->payload())
            ->assertRedirect('/concerns');

        $user->refresh();
        $this->assertSame('College of Computer Studies', $user->department);
        $this->assertSame('BS Information Technology', $user->course);
        $this->assertFalse($user->needsProfileCompletion());
    }

    public function test_a_course_from_another_college_is_rejected(): void
    {
        $user = $this->googleProvisionedStudent();

        $this->actingAs($user)
            ->post('/complete-profile', $this->payload(['course' => 'BS Nursing']))
            ->assertSessionHasErrors('course');

        $this->assertNull($user->fresh()->course);
    }

    public function test_a_made_up_course_is_rejected(): void
    {
        $user = $this->googleProvisionedStudent();

        $this->actingAs($user)
            ->post('/complete-profile', $this->payload(['course' => 'BS Wizardry']))
            ->assertSessionHasErrors('course');

        $this->assertNull($user->fresh()->course);
    }

    public function test_a_made_up_college_is_rejected(): void
    {
        $user = $this->googleProvisionedStudent();

        $this->actingAs($user)
            ->post('/complete-profile', $this->payload(['department' => 'College of Hogwarts']))
            ->assertSessionHasErrors('department');

        $this->assertNull($user->fresh()->department);
    }

    public function test_registration_is_no_longer_reachable(): void
    {
        // Students get in only through CSPC Mail; there is no signup form to
        // let someone create an account under an address they do not own.
        $this->get('/register')->assertNotFound();
        $this->post('/register', $this->payload())->assertNotFound();
    }
}
