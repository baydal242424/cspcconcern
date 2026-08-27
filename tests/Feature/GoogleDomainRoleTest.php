<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

/**
 * CSPC Mail is the only way in, and the ADDRESS DOMAIN decides what a
 * brand-new account starts as: my.cspc.edu.ph is a student, cspc.edu.ph is
 * an employee. Anything else is refused.
 */
class GoogleDomainRoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    /** Pretend Google authenticated this address and hand it to the callback. */
    private function signInWithGoogle(string $email, string $name = 'Test Person'): void
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
    }

    public function test_a_my_cspc_address_becomes_a_student(): void
    {
        $this->signInWithGoogle('juan@my.cspc.edu.ph');

        $user = User::where('email', 'juan@my.cspc.edu.ph')->firstOrFail();
        $this->assertSame('Student', $user->role->name);
        // College and course are student fields, so they must still be asked.
        $this->assertTrue($user->needsProfileCompletion());
    }

    public function test_a_cspc_address_becomes_faculty_staff(): void
    {
        $this->signInWithGoogle('rosa.delgado@cspc.edu.ph');

        $user = User::where('email', 'rosa.delgado@cspc.edu.ph')->firstOrFail();
        $this->assertSame('Faculty/Staff', $user->role->name);
        // Employees are not students -- they must not be sent to the
        // "tell us your college and course" form.
        $this->assertFalse($user->needsProfileCompletion());
    }

    public function test_a_non_cspc_address_is_refused_and_creates_nothing(): void
    {
        $this->signInWithGoogle('someone@gmail.com');

        $this->assertDatabaseMissing('users', ['email' => 'someone@gmail.com']);
        $this->assertGuest();
    }

    public function test_signing_in_again_never_undoes_a_promotion(): void
    {
        // An Admin promotes an instructor to Head of School. If the domain
        // rule re-applied on every sign-in it would silently demote them
        // back to Faculty/Staff -- a privilege the Admin granted, revoked by
        // an unrelated action.
        $this->signInWithGoogle('dean@cspc.edu.ph');

        $user = User::where('email', 'dean@cspc.edu.ph')->firstOrFail();
        $user->forceFill(['role_id' => Role::where('name', 'Head of School')->value('id')])->save();

        $this->post('/logout');
        $this->signInWithGoogle('dean@cspc.edu.ph');

        $this->assertSame('Head of School', $user->fresh()->role->name);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
