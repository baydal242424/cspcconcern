<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Demo sign-in bypasses the only authentication this system has, so what it
 * REFUSES matters more than what it allows. Three properties are worth having
 * tests for:
 *
 *  - off unless switched on, and invisible when off;
 *  - it can never assume the identity of a real person;
 *  - the form is a suggestion, not the authority -- a hand-crafted post gets
 *    the same refusal as a missing account.
 */
class DemoLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, UserSeeder::class]);
    }

    private function enable(): void
    {
        config(['auth.demo_login' => true]);
    }

    /** Off by default: nothing rendered, and the route does not exist. */
    public function test_disabled_by_default(): void
    {
        config(['auth.demo_login' => false]);

        $this->get('/login')->assertOk()->assertDontSee('demo_user', false);

        $user = User::where('email', 'staff@cspc.edu.ph')->firstOrFail();
        $this->post('/auth/demo', ['user_id' => $user->id])->assertNotFound();
        $this->assertGuest();

        fwrite(STDERR, "  [off] no dropdown, route 404, still a guest: YES\n");
    }

    /** Switched on, the dropdown lists seeded accounts grouped by role. */
    public function test_dropdown_lists_seeded_accounts_when_enabled(): void
    {
        $this->enable();

        $resp = $this->get('/login');
        $resp->assertOk();
        $resp->assertSee('demo_user', false);
        $resp->assertSee('<optgroup label="Dean"', false);
        $resp->assertSee('<optgroup label="Guidance Counselor"', false);

        fwrite(STDERR, "  [on] dropdown rendered and grouped by role: YES\n");
    }

    /** Choosing a seeded account signs you in as it. */
    public function test_can_sign_in_as_a_seeded_account(): void
    {
        $this->enable();
        $counselor = User::where('email', 'counselor@cspc.edu.ph')->firstOrFail();

        $this->post('/auth/demo', ['user_id' => $counselor->id])
            ->assertRedirect(route('concerns.index'));

        $this->assertAuthenticatedAs($counselor);

        fwrite(STDERR, "  [use] signed in as the chosen demo account: YES\n");
    }

    /**
     * The safety property. An account belonging to somebody who has actually
     * signed in with Google is never offered and never accepted, even with the
     * feature switched on and their id posted directly.
     */
    public function test_a_real_person_can_never_be_impersonated(): void
    {
        $this->enable();

        // Somebody who has signed in for real.
        $real = User::where('email', 'staff@cspc.edu.ph')->firstOrFail();
        $real->forceFill(['google_id' => '1234567890', 'name' => 'A Real Person'])->save();

        $this->get('/login')->assertOk()->assertDontSee('A Real Person');

        $this->post('/auth/demo', ['user_id' => $real->id])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();

        fwrite(STDERR, "  [wall] account with a Google sign-in refused: YES\n");
    }

    /** A suspended account cannot be assumed either. */
    public function test_a_banned_account_is_refused(): void
    {
        $this->enable();
        $user = User::where('email', 'counselor@cspc.edu.ph')->firstOrFail();
        $user->forceFill(['status' => 'banned'])->save();

        $this->post('/auth/demo', ['user_id' => $user->id])
            ->assertSessionHasErrors('email');
        $this->assertGuest();

        fwrite(STDERR, "  [ban] suspended account refused: YES\n");
    }

    /** An id that is not a user at all is refused rather than erroring. */
    public function test_unknown_id_is_refused_cleanly(): void
    {
        $this->enable();

        $this->post('/auth/demo', ['user_id' => 999999])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');
        $this->assertGuest();

        fwrite(STDERR, "  [junk] unknown id refused cleanly: YES\n");
    }
}
