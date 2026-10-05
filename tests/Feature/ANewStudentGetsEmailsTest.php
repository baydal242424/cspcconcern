<?php

namespace Tests\Feature;

use App\Models\Concern;
use App\Models\Role;
use App\Models\User;
use App\Notifications\ConcernAssigned;
use App\Notifications\ConcernStatusUpdated;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

/**
 * A student who has never used the system before still gets the emails.
 *
 * Nothing about the notifications is set up by hand for an account -- no
 * subscription, no preference, no verification step. The address Google hands
 * over at first sign-in is the address the mail goes to, from that moment on.
 * This walks the whole path for a brand-new person to prove there is no step
 * an unwary student can miss.
 */
class ANewStudentGetsEmailsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, UserSeeder::class]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** Sign in through Google for the very first time, as the callback does. */
    private function signInAsNewStudent(string $name, string $email): User
    {
        $socialite = new SocialiteUser();
        $socialite->map([
            'id' => 'google-'.md5($email),
            'name' => $name,
            'email' => $email,
        ]);

        $provider = Mockery::mock('Laravel\Socialite\Contracts\Provider');
        $provider->shouldReceive('user')->andReturn($socialite);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $this->get('/auth/google/callback');

        return User::where('email', $email)->firstOrFail();
    }

    public function test_a_brand_new_student_is_emailed_when_their_concern_moves(): void
    {
        Notification::fake();

        // 1. First sign-in. The account did not exist a moment ago.
        $this->assertNull(User::where('email', 'newbie@my.cspc.edu.ph')->first());

        $student = $this->signInAsNewStudent('Brand New Student', 'newbie@my.cspc.edu.ph');

        $this->assertSame('Student', $student->role->name);
        $this->assertSame('newbie@my.cspc.edu.ph', $student->email);

        // 2. The details the sign-in cannot know.
        $this->actingAs($student)->post('/complete-profile', [
            'student_id' => '241000999',
            'department' => 'College of Computer Studies',
            'course' => 'BS Information Technology',
            'year' => 1, 'section_letter' => 'A',
        ])->assertSessionHasNoErrors()->assertRedirect();

        // 3. An adviser exists to receive it.
        $adviser = User::create([
            'name' => 'Their Adviser',
            'email' => 'their.adviser@cspc.edu.ph',
            'password' => Hash::make('not-used'),
            'role_id' => Role::where('name', 'Adviser')->firstOrFail()->id,
            'status' => 'approved',
            'department' => 'College of Computer Studies',
            'course' => 'BS Information Technology',
        ]);

        // 4. File a concern, the way the form does.
        $this->actingAs($student->refresh())->post('/concerns', [
            'category' => 'Academic',
            'description' => 'My very first concern, filed minutes after signing in.',
        ])->assertRedirect();

        $concern = Concern::latest('id')->firstOrFail();
        $this->assertSame($student->id, $concern->user_id);

        // The handler is told at once, without anybody configuring anything.
        Notification::assertSentTo($adviser, ConcernAssigned::class);

        // 5. The handler starts work.
        $this->actingAs($adviser)
            ->put("/concerns/{$concern->id}", [
            'investigation_notes' => 'Looked into this and spoke with the people involved.',
            'resolution_notes' => 'Recorded what is being done about it.','status' => 'in_progress'])
            ->assertRedirect();

        Notification::assertSentTo($student, ConcernStatusUpdated::class);

        // 6. And again when it is settled.
        $this->actingAs($adviser)
            ->put("/concerns/{$concern->id}", [
            'investigation_notes' => 'Looked into this and spoke with the people involved.',
            'resolution_notes' => 'Recorded what is being done about it.','status' => 'resolved'])
            ->assertRedirect();

        Notification::assertSentToTimes($student, ConcernStatusUpdated::class, 2);

        fwrite(STDERR, "  [new student] signed in, filed, and was emailed at both steps -- no setup needed\n");
    }

    /**
     * The address is whatever Google gave, not something stored earlier. A
     * student the admin has never touched is reachable all the same.
     */
    public function test_the_mail_goes_to_the_address_google_supplied(): void
    {
        $student = $this->signInAsNewStudent('Another New Student', 'another.newbie@my.cspc.edu.ph');

        $this->assertSame('another.newbie@my.cspc.edu.ph', $student->routeNotificationFor(
            'mail',
            new ConcernStatusUpdated(new Concern(['id' => 1]), 'In progress', 'Working on it.')
        ));

        // Nothing gates delivery on an admin having approved or verified them.
        $this->assertSame('approved', $student->status);
        $this->assertNotNull($student->email_verified_at);

        fwrite(STDERR, "  [new student] mail is addressed to the CSPC address Google supplied\n");
    }
}
