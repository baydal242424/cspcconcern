<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * The dropdown that lets you sign in as a test account without Google.
 *
 * It is an authentication bypass, which is the whole reason it is fenced in
 * twice: a config flag that is off by default, AND a refusal to work in
 * production whatever that flag says. One stray value in a hosting panel
 * cannot open the door, and nobody has to remember to check.
 *
 * The other fence is google_id. A seeded row is a fixture nobody owns; the
 * moment a real person signs in, their google_id is set and they drop out of
 * this list permanently. That is what keeps it from being an impersonation
 * tool aimed at real students and staff.
 */
class TestAccountSignInTest extends TestCase
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

    /**
     * Each account sits in its own role's lane, and the test ones come first
     * inside it.
     *
     * They were collected under one "Test accounts" heading for a while, which
     * filed a test Program Chair under a heading that did not say Program
     * Chair -- so finding one meant reading the whole list instead of going
     * straight to the lane you wanted.
     */
    public function test_each_account_sits_in_its_own_role_lane(): void
    {
        $this->enable();
        Artisan::call('testing:accounts');

        $page = $this->get('/login')->assertOk();
        $groups = $page->viewData('demoAccounts');

        $this->assertFalse($groups->has('Test accounts'), 'The catch-all heading should be gone');
        $this->assertTrue($groups->has('Student'));

        // Every lane is named for a role, and holds only that role.
        foreach ($groups as $roleName => $people) {
            foreach ($people as $person) {
                $this->assertSame($roleName, optional($person->role)->name);
            }
        }

        // Inside a lane, the purpose-made accounts come first: by name alone
        // "TEST ..." sorts to the bottom, behind every real one.
        $students = $groups->get('Student');
        $this->assertStringStartsWith('TEST Student', $students->first()->name);

        fwrite(STDERR, "  [test sign-in] every account sits in its own role lane, test ones first\n");
    }

    /** A student is told apart by their class, not by their college. */
    public function test_a_student_is_listed_with_their_programme_and_class(): void
    {
        $this->enable();
        Artisan::call('testing:accounts');

        $this->get('/login')->assertOk()
            ->assertSee('TEST Student — BS Information Technology 3A', false);

        fwrite(STDERR, "  [test sign-in] a student's line names their programme and class\n");
    }

    public function test_signing_in_as_one_works(): void
    {
        $this->enable();
        Artisan::call('testing:accounts');

        $dean = User::where('email', 'like', 'test.%')->whereHas('role', fn ($q) => $q->where('name', 'Student'))->firstOrFail();

        $this->post('/auth/demo', ['user_id' => $dean->id])->assertRedirect();

        $this->assertTrue(Auth::check());
        $this->assertSame($dean->id, Auth::id());
        $this->assertSame('Student', Auth::user()->role->name);

        fwrite(STDERR, "  [test sign-in] you can sign in as a test account and land in it\n");
    }

    /** Off by default: nothing on the page, and nothing at the route. */
    public function test_it_is_invisible_and_unreachable_when_switched_off(): void
    {
        config(['auth.demo_login' => false]);
        Artisan::call('testing:accounts');

        $this->get('/login')->assertOk()->assertDontSee('Test accounts', false);

        // 404 rather than 403: when the feature is off there is nothing here.
        $dean = User::where('email', 'like', 'test.%')->whereHas('role', fn ($q) => $q->where('name', 'Student'))->firstOrFail();
        $this->post('/auth/demo', ['user_id' => $dean->id])->assertNotFound();

        $this->assertFalse(Auth::check());

        fwrite(STDERR, "  [test sign-in] switched off, the dropdown is gone and the route 404s\n");
    }

    /**
     * The fence that matters most. Even with the flag on, production refuses:
     * the live site has real students' concerns behind these accounts.
     */
    public function test_production_refuses_even_with_the_flag_on(): void
    {
        $this->enable();
        Artisan::call('testing:accounts');

        $dean = User::where('email', 'like', 'test.%')->whereHas('role', fn ($q) => $q->where('name', 'Student'))->firstOrFail();

        app()['env'] = 'production';

        $this->get('/login')->assertOk()->assertDontSee('Test accounts', false);

        // Middleware off for this call. CSRF is skipped only while the app
        // thinks it is under test, so posting as "production" otherwise fails
        // on the token -- a 419 that would pass for a refusal while proving
        // nothing about the guard actually being tested, which is in the
        // controller.
        $this->withoutMiddleware()
            ->post('/auth/demo', ['user_id' => $dean->id])
            ->assertNotFound();

        $this->assertFalse(Auth::check());

        fwrite(STDERR, "  [test sign-in] production refuses it whatever the flag says\n");
    }

    /**
     * A real person cannot be signed in as, however the form is crafted: the
     * moment they use Google their google_id is set.
     */
    public function test_a_real_account_cannot_be_signed_in_as(): void
    {
        $this->enable();

        $real = User::where('email', 'student@my.cspc.edu.ph')->firstOrFail();
        $real->forceFill(['google_id' => 'a-real-google-identity'])->save();

        $this->get('/login')->assertOk()->assertDontSee($real->name, false);

        $this->post('/auth/demo', ['user_id' => $real->id])->assertRedirect();
        $this->assertFalse(Auth::check(), 'Someone who has signed in with Google must not be impersonable');

        fwrite(STDERR, "  [test sign-in] anyone who has used Google is out of reach of this dropdown\n");
    }
}
