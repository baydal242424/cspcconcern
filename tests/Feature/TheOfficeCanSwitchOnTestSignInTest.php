<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * The test-account sign-in is a switch in Manage Users, not an env var.
 *
 * It used to live in the hosting panel. Turning it on for a demonstration
 * therefore meant an environment edit and a redeploy -- work only the
 * person who set the site up could do, at the moment they were least able
 * to: the morning of the demonstration. A System Admin flicks it now, and
 * the sign-in page answers immediately.
 *
 * DEMO_LOGIN_ENABLED did not go away. It is the default -- what the site
 * does before anybody has decided -- and it is still false by omission, so
 * a fresh deploy is shut.
 *
 * What the switch opens is narrow, and TestAccountSignInTest holds that
 * line: on a deployed site only `test.` and `demo.` addresses are offered,
 * never a System Admin, Staff Admin or Head of School.
 */
class TheOfficeCanSwitchOnTestSignInTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, UserSeeder::class]);
        Setting::forget();
    }

    /**
     * The one string that is written only when accounts are actually
     * offered.
     *
     * NOT a class name: `.demo-select` and `.demo-signin` are in the page's
     * <style> block whichever way the switch is set, so asserting on either
     * would pass for a verdict while proving nothing.
     */
    private const MARKER = 'Sign in as this account';

    /** actingAs() persists, and /login redirects anybody already signed in. */
    private function asGuest(): static
    {
        Auth::logout();

        return $this;
    }

    private function personIn(string $role): User
    {
        return User::whereHas('role', fn ($q) => $q->where('name', $role))->firstOrFail();
    }

    /**
     * The whole point: no environment variable is touched, and the sign-in
     * page changes.
     */
    public function test_the_switch_turns_the_dropdown_on_without_touching_the_environment(): void
    {
        // The default a fresh deploy has.
        config(['auth.demo_login' => false]);
        Artisan::call('testing:accounts');

        $this->asGuest()->get('/login')->assertOk()->assertDontSee(self::MARKER, false);

        $this->actingAs($this->personIn('System Admin'))
            ->post('/admin/demo-login', ['enabled' => 1])
            ->assertRedirect();

        Setting::forget();

        // The env var is still false. The page offers the accounts anyway.
        $this->assertFalse((bool) config('auth.demo_login'), 'The environment was not the thing that changed');
        $this->asGuest()->get('/login')->assertOk()->assertSee(self::MARKER, false);

        fwrite(STDERR, "  [switch] on from Manage Users, with DEMO_LOGIN_ENABLED still false\n");
    }

    /** And off again, which is the half that matters after the demonstration. */
    public function test_the_switch_turns_the_dropdown_off_again(): void
    {
        config(['auth.demo_login' => true]);
        Artisan::call('testing:accounts');

        $this->asGuest()->get('/login')->assertOk()->assertSee(self::MARKER, false);

        $this->actingAs($this->personIn('System Admin'))
            ->post('/admin/demo-login', ['enabled' => 0])
            ->assertRedirect();

        Setting::forget();

        // The row beats the environment, or switching off would be advice
        // rather than an action.
        $this->assertTrue((bool) config('auth.demo_login'));
        $this->asGuest()->get('/login')->assertOk()->assertDontSee(self::MARKER, false);
        $this->post('/auth/demo', ['user_id' => $this->personIn('Student')->id])->assertNotFound();

        fwrite(STDERR, "  [switch] off again, and the route goes with it\n");
    }

    /**
     * A Staff Admin manages the roster, which is a way to become any other
     * role. Handing them the door as well would make that a way in.
     */
    public function test_only_a_system_admin_may_move_it(): void
    {
        config(['auth.demo_login' => false]);

        $this->actingAs($this->personIn('Staff Admin'))
            ->post('/admin/demo-login', ['enabled' => 1])
            ->assertForbidden();

        Setting::forget();
        $this->assertFalse(Setting::flag(Setting::DEMO_LOGIN, false), 'Nothing was written');

        $this->asGuest()->get('/login')->assertOk()->assertDontSee(self::MARKER, false);

        fwrite(STDERR, "  [switch] a Staff Admin is refused, and nothing is written\n");
    }

    /** Nor does a Staff Admin see it, which is where they would try first. */
    public function test_only_a_system_admin_is_shown_it(): void
    {
        $this->actingAs($this->personIn('System Admin'))
            ->get('/admin/users')->assertOk()->assertSee('Test-account sign-in', false);

        $this->actingAs($this->personIn('Staff Admin'))
            ->get('/admin/users')->assertOk()->assertDontSee('Test-account sign-in', false);

        fwrite(STDERR, "  [switch] shown to a System Admin, hidden from a Staff Admin\n");
    }

    /**
     * Switching it on does NOT widen what it offers. On a deployed site the
     * narrowing still holds, which is the thing that makes a switch in the
     * interface a reasonable idea at all.
     */
    public function test_switching_it_on_does_not_widen_what_it_offers(): void
    {
        config(['auth.demo_login' => false]);

        $this->actingAs($this->personIn('System Admin'))
            ->post('/admin/demo-login', ['enabled' => 1])
            ->assertRedirect();

        Setting::forget();

        $real = User::whereNull('google_id')
            ->where('status', 'approved')
            ->where('email', 'not like', 'test.%')
            ->where('email', 'not like', 'demo.%')
            ->whereHas('role')
            ->firstOrFail();

        app()['env'] = 'production';

        $page = $this->asGuest()->get('/login')->assertOk();
        $page->assertDontSee($real->name, false);

        foreach (['System Admin', 'Staff Admin', 'Head of School'] as $privileged) {
            $page->assertDontSee($privileged.'</optgroup', false);
        }

        fwrite(STDERR, "  [switch] on, and a deployed site still offers no real person: {$real->name}\n");
    }
}
