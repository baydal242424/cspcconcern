<?php

namespace Tests\Feature;

use App\Models\Concern;
use App\Models\User;
use App\Notifications\ConcernAssigned;
use App\Notifications\ConcernStatusUpdated;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

/**
 * The button in a notification email has to open the concern for somebody
 * reading it on their own phone.
 *
 * Two things stopped that. The link took its host from wherever the email was
 * sent -- localhost, on the laptop sending them -- and signing in on the way
 * dropped the reader on their concern list instead of the concern.
 */
class EmailLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, UserSeeder::class]);
    }

    private function concern(): Concern
    {
        return Concern::create([
            'user_id' => User::where('email', 'student@my.cspc.edu.ph')->firstOrFail()->id,
            'category' => 'Academic',
            'department' => 'College of Computer Studies',
            'description' => 'A concern whose email link is being checked.',
            'status' => 'submitted',
            'is_anonymous' => false,
        ]);
    }

    public function test_email_buttons_link_to_the_public_site_not_the_sending_machine(): void
    {
        config(['app.url' => 'http://localhost:8001', 'app.public_url' => 'https://cspcconcern.laravel.cloud']);

        $concern = $this->concern();
        $reader = User::where('email', 'admin@cspc.edu.ph')->firstOrFail();
        $expected = "https://cspcconcern.laravel.cloud/concerns/{$concern->id}";

        $assigned = (new ConcernAssigned($concern))->toMail($reader);
        $status = (new ConcernStatusUpdated($concern, 'In progress', 'Someone is working on it.'))->toMail($reader);

        $this->assertSame($expected, $assigned->actionUrl);
        $this->assertSame($expected, $status->actionUrl);

        fwrite(STDERR, "  [email] both buttons link to {$expected}\n");
    }

    public function test_without_a_public_url_it_falls_back_to_app_url(): void
    {
        config(['app.url' => 'https://cspcconcern.laravel.cloud', 'app.public_url' => 'https://cspcconcern.laravel.cloud']);

        $concern = $this->concern();
        $mail = (new ConcernAssigned($concern))->toMail(User::where('email', 'admin@cspc.edu.ph')->firstOrFail());

        $this->assertStringStartsWith('https://cspcconcern.laravel.cloud/concerns/', $mail->actionUrl);

        fwrite(STDERR, "  [email] on the live site the link is its own address\n");
    }

    /** Opening the link signed out, then signing in, lands on that concern. */
    public function test_signing_in_from_an_email_link_returns_to_the_concern(): void
    {
        $concern = $this->concern();

        // The email link, opened while signed out: sent to sign in.
        $this->get("/concerns/{$concern->id}")->assertRedirect('/login');

        $socialite = new SocialiteUser();
        $socialite->map(['id' => 'google-admin', 'name' => 'Admin', 'email' => 'admin@cspc.edu.ph']);
        $provider = Mockery::mock('Laravel\Socialite\Contracts\Provider');
        $provider->shouldReceive('user')->andReturn($socialite);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $this->get('/auth/google/callback')
            ->assertRedirect("/concerns/{$concern->id}");

        fwrite(STDERR, "  [email] signed in from the link -> back on concern #{$concern->id}, not the list\n");
    }
}
