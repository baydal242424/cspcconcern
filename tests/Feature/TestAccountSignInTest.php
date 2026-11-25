<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The dropdown that lets you sign in as a test account without Google.
 *
 * It is an authentication bypass, and it is fenced accordingly: a config
 * flag that is off by default, so a deploy is safe by omission, AND -- on
 * a deployed site -- a list narrowed to addresses nobody could mistake for
 * a person, never including the three roles that read everything or decide
 * who is who.
 *
 * It refused on production outright until the deployed site became the
 * place the system is demonstrated. The fence moved to what it was
 * protecting rather than staying shut.
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
     * On a deployed site the obviously-fake accounts still work.
     *
     * This replaced a blanket refusal. The deployed app is where the system
     * is shown to people, and a demonstration nobody can sign in to is no
     * demonstration -- so the fence narrowed to what it was always
     * protecting rather than staying shut. The two tests below are that
     * fence.
     */
    public function test_a_deployed_site_still_offers_the_fake_accounts(): void
    {
        $this->enable();
        Artisan::call('testing:accounts');

        $student = User::where('email', 'like', 'test.%')
            ->whereHas('role', fn ($q) => $q->where('name', 'Student'))
            ->firstOrFail();

        app()['env'] = 'production';

        // The whole stack runs, and the post carries a real CSRF token.
        //
        // Two earlier attempts are worth recording, because both LOOK right.
        // withoutMiddleware() with no argument takes StartSession away with
        // the rest, and a sign-in that SUCCEEDS calls session()->regenerate()
        // -- so the test died on plumbing every browser has. Excluding the
        // CSRF middleware by class name did not remove it either, and the
        // post came back 419.
        //
        // CSRF is only waived while the app thinks it is under test, and this
        // test tells it otherwise. So it behaves like a browser instead:
        // session token in, same token posted.
        $this->withSession(['_token' => 'a-token-the-form-would-carry'])
            ->post('/auth/demo', [
                'user_id' => $student->id,
                '_token' => 'a-token-the-form-would-carry',
            ])
            ->assertRedirect();

        $this->assertTrue(Auth::check(), 'A test account is what this dropdown is for');
        $this->assertSame($student->id, Auth::id());

        fwrite(STDERR, "  [test sign-in] a deployed site still offers the test accounts\n");
    }

    /**
     * A REAL person is not offered on a deployed site, and cannot be posted.
     *
     * This is the half of the old blanket worth keeping. A seeded staff row
     * has no google_id, so on a developer's machine the dropdown offers that
     * person. On the deployed site it must not: the site carries the
     * college's name, and anybody who found the URL would be signing in as
     * them.
     */
    public function test_a_real_person_is_out_of_reach_on_a_deployed_site(): void
    {
        $this->enable();

        $real = User::whereNull('google_id')
            ->where('status', 'approved')
            ->where('email', 'not like', 'test.%')
            ->where('email', 'not like', 'demo.%')
            ->whereHas('role')
            ->firstOrFail();

        app()['env'] = 'production';

        $this->get('/login')->assertOk()->assertDontSee($real->name, false);

        $this->withoutMiddleware()
            ->post('/auth/demo', ['user_id' => $real->id])
            ->assertRedirect();

        $this->assertFalse(Auth::check(), 'A real member of staff must not be signed in as on a deployed site');

        fwrite(STDERR, "  [test sign-in] a deployed site offers no real person: {$real->name} is out of reach\n");
    }

    /**
     * The three roles never offered on a deployed site, even wearing a test
     * address.
     *
     * System Admin edits the routing table every concern is sorted by. Staff
     * Admin manages the roster, which is a way to become any of the others.
     * Head of School is the one role that can unmask a pseudonymous reporter
     * -- the single thing this system promises a frightened student.
     */
    public function test_the_privileged_roles_are_never_offered_on_a_deployed_site(): void
    {
        $this->enable();

        foreach (['System Admin', 'Staff Admin', 'Head of School'] as $roleName) {
            $role = Role::where('name', $roleName)->firstOrFail();

            $planted = User::create([
                'name' => "TEST {$roleName}",
                'email' => 'test.'.str_replace(' ', '', strtolower($roleName)).'@cspc.edu.ph',
                'password' => bcrypt(Str::random(32)),
                'role_id' => $role->id,
                'status' => 'approved',
            ]);

            app()['env'] = 'production';

            $this->get('/login')->assertOk()->assertDontSee($planted->name, false);

            $this->withoutMiddleware()
                ->post('/auth/demo', ['user_id' => $planted->id])
                ->assertRedirect();

            $this->assertFalse(Auth::check(), "{$roleName} must not be reachable on a deployed site");

            app()['env'] = 'testing';
        }

        fwrite(STDERR, "  [test sign-in] System Admin, Staff Admin and Head of School stay shut on a deployed site\n");
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

    /**
     * A real account can be named for a demonstration, one at a time.
     *
     * The prefix rule keeps the whole roster out of the dropdown on a
     * deployed site. A demonstration reads better as the people who hold
     * the posts, though -- the counsellor the students would really write
     * to -- so an account can be marked, by name, and offered.
     *
     * Nothing else moves: the marking does not reach the three privileged
     * roles, and it does not touch google_id, which is tested below.
     */
    public function test_a_named_account_is_offered_on_a_deployed_site(): void
    {
        $this->enable();

        $counsellor = User::whereHas('role', fn ($q) => $q->where('name', 'Guidance Counselor'))
            ->whereNull('google_id')
            ->firstOrFail();

        app()['env'] = 'production';

        // Unmarked, they are a real person like any other: not offered.
        $this->asGuestHere()->get('/login')->assertOk()->assertDontSee($counsellor->name, false);

        $counsellor->forceFill(['demo_signin' => true])->save();

        $this->asGuestHere()->get('/login')->assertOk()->assertSee($counsellor->name, false);

        $this->withSession(['_token' => 't'])
            ->post('/auth/demo', ['user_id' => $counsellor->id, '_token' => 't'])
            ->assertRedirect();

        $this->assertTrue(Auth::check());
        $this->assertSame($counsellor->id, Auth::id());

        fwrite(STDERR, "  [test sign-in] {$counsellor->name} is offered once named, and not before\n");
    }

    /**
     * Marking somebody does NOT override the Google fence.
     *
     * That fence is the one with no way round it: the moment a real person
     * signs in, the dropdown stops offering them for good. A flag somebody
     * set in a console must not undo a decision their own sign-in made.
     */
    public function test_marking_does_not_override_a_google_sign_in(): void
    {
        $this->enable();

        $person = User::whereHas('role', fn ($q) => $q->where('name', 'Guidance Counselor'))
            ->whereNull('google_id')
            ->firstOrFail();

        $person->forceFill([
            'demo_signin' => true,
            'google_id' => 'a-real-google-identity',
        ])->save();

        app()['env'] = 'production';

        $this->asGuestHere()->get('/login')->assertOk()->assertDontSee($person->name, false);

        $this->withSession(['_token' => 't'])
            ->post('/auth/demo', ['user_id' => $person->id, '_token' => 't'])
            ->assertRedirect();

        $this->assertFalse(Auth::check(), 'A Google sign-in outranks any marking');

        fwrite(STDERR, "  [test sign-in] marking never outranks a Google sign-in\n");
    }

    /** Nor does it reach the three roles a deployed site always refuses. */
    public function test_marking_does_not_reach_the_privileged_roles(): void
    {
        $this->enable();

        foreach (['System Admin', 'Staff Admin', 'Head of School'] as $roleName) {
            $person = User::whereHas('role', fn ($q) => $q->where('name', $roleName))
                ->whereNull('google_id')
                ->first();

            if (! $person) {
                continue;
            }

            $person->forceFill(['demo_signin' => true])->save();

            app()['env'] = 'production';
            $this->asGuestHere()->get('/login')->assertOk()->assertDontSee($person->name, false);

            $this->withSession(['_token' => 't'])
                ->post('/auth/demo', ['user_id' => $person->id, '_token' => 't'])
                ->assertRedirect();

            $this->assertFalse(Auth::check(), "{$roleName} must stay shut however it is marked");

            app()['env'] = 'testing';
        }

        fwrite(STDERR, "  [test sign-in] marking never reaches System Admin, Staff Admin or Head of School\n");
    }

    /**
     * Instructors are never offered on a deployed site -- not a demo one,
     * not a marked one. Teaching staff receive nothing here.
     */
    public function test_instructors_are_not_offered_on_a_deployed_site(): void
    {
        $this->enable();

        $instructorRole = \App\Models\Role::where('name', 'Instructor')->firstOrFail();

        $demo = User::create([
            'name' => 'Demo Instructor',
            'email' => 'demo.instructor@cspc.edu.ph',
            'password' => bcrypt(Str::random(32)),
            'role_id' => $instructorRole->id,
            'status' => 'approved',
        ]);

        app()['env'] = 'production';

        $this->asGuestHere()->get('/login')->assertOk()->assertDontSee('Demo Instructor', false);

        $this->withSession(['_token' => 't'])
            ->post('/auth/demo', ['user_id' => $demo->id, '_token' => 't'])
            ->assertRedirect();

        $this->assertFalse(Auth::check(), 'An instructor must not be offered on a deployed site');

        fwrite(STDERR, "  [test sign-in] no instructor is offered on a deployed site, even a demo one\n");
    }
    /** actingAs() and a successful sign-in both persist; /login redirects them. */
    private function asGuestHere(): static
    {
        Auth::logout();

        return $this;
    }
}