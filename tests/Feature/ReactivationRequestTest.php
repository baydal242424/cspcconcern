<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A graduated student asks for their account back, from the login page.
 *
 * "Ask the admin to reactivate it" was a dead end. The student is locked out
 * by definition, so they cannot reach anybody through the system, and an
 * irregular student with a real concern would give up rather than hunt for an
 * office. The button sends the request for them.
 *
 * The security question is the whole design. At that moment nobody is signed
 * in, so if the form carried an email address, anyone could aim a request at
 * any account -- or press it repeatedly to learn which addresses exist. The
 * student is identified from the session that their own refused Google sign-in
 * just wrote.
 */
class ReactivationRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, UserSeeder::class]);
    }

    private function graduate(): User
    {
        return User::factory()->create([
            'name' => 'Irregular Student',
            'email' => 'irregular@my.cspc.edu.ph',
            'role_id' => Role::where('name', 'Student')->value('id'),
            'department' => 'College of Computer Studies',
            'course' => 'BS Information Systems',
            'section' => '4A',
            'status' => 'graduated',
        ]);
    }

    /** Nobody signed in means nobody to ask about. */
    public function test_the_request_is_refused_without_a_refused_sign_in(): void
    {
        $this->graduate();

        $this->post(route('auth.reactivation.request'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertSame(0, Notification::where('type', 'reactivation_request')->count());

        fwrite(STDERR, "  [reactivate] no request without a refused sign-in: YES\n");
    }

    /**
     * The identity comes from the session, never the form. A posted user_id
     * for somebody else must change nothing.
     */
    public function test_it_cannot_be_aimed_at_another_account(): void
    {
        $graduate = $this->graduate();
        $someoneElse = User::where('email', 'student@my.cspc.edu.ph')->firstOrFail();

        $this->withSession(['reactivation_candidate' => $graduate->id])
            ->post(route('auth.reactivation.request'), ['user_id' => $someoneElse->id, 'email' => $someoneElse->email])
            ->assertRedirect(route('login'));

        $request = Notification::where('type', 'reactivation_request')->firstOrFail();

        $this->assertStringContainsString($graduate->email, $request->message);
        $this->assertStringNotContainsString($someoneElse->email, $request->message);

        fwrite(STDERR, "  [reactivate] a posted id for somebody else is ignored: YES\n");
    }

    /** Every admin gets it, and it names who is asking. */
    public function test_it_reaches_the_admins(): void
    {
        $graduate = $this->graduate();
        $admin = User::where('email', 'admin@cspc.edu.ph')->firstOrFail();

        $this->withSession(['reactivation_candidate' => $graduate->id])
            ->post(route('auth.reactivation.request'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('success');

        $notification = Notification::where('user_id', $admin->id)
            ->where('type', 'reactivation_request')
            ->firstOrFail();

        $this->assertStringContainsString('Irregular Student', $notification->message);
        $this->assertStringContainsString('BS Information Systems', $notification->message);
        $this->assertFalse((bool) $notification->is_read);

        fwrite(STDERR, "  [reactivate] the admin is notified, with who and which programme: YES\n");
    }

    /** Pressing it five times must not bury the other students asking. */
    public function test_asking_twice_does_not_send_twice(): void
    {
        $graduate = $this->graduate();

        for ($i = 0; $i < 3; $i++) {
            $this->withSession(['reactivation_candidate' => $graduate->id])
                ->post(route('auth.reactivation.request'));
        }

        $this->assertSame(
            User::whereHas('role', fn ($q) => $q->where('name', 'System Admin'))->count(),
            Notification::where('type', 'reactivation_request')->count(),
            'three presses must leave one request per admin, not three'
        );

        fwrite(STDERR, "  [reactivate] pressing it repeatedly sends one request: YES\n");
    }

    /** The admin grants it, and the request clears itself. */
    public function test_reactivating_clears_the_request_and_tells_the_student(): void
    {
        $graduate = $this->graduate();
        $admin = User::where('email', 'admin@cspc.edu.ph')->firstOrFail();

        $this->withSession(['reactivation_candidate' => $graduate->id])
            ->post(route('auth.reactivation.request'));

        $this->actingAs($admin)
            ->post(route('admin.users.reactivate', $graduate))
            ->assertRedirect();

        $this->assertSame('approved', $graduate->fresh()->status);

        $this->assertSame(
            0,
            Notification::where('type', 'reactivation_request')->where('is_read', false)->count(),
            'a granted request must not keep sitting in the bell'
        );

        $this->assertTrue(
            Notification::where('user_id', $graduate->id)->where('type', 'account_reactivated')->exists(),
            'the student should find out it was acted on'
        );

        fwrite(STDERR, "  [reactivate] granted: request cleared, student told: YES\n");
    }

    /** The button is only offered to the account that was just refused. */
    public function test_the_login_page_offers_the_button_only_after_a_refusal(): void
    {
        $graduate = $this->graduate();

        $this->get(route('login'))->assertDontSee('Ask the admin to reactivate');

        $this->withSession(['reactivation_candidate' => $graduate->id])
            ->get(route('login'))
            ->assertSee('Ask the admin to reactivate my account')
            ->assertSee($graduate->email);

        fwrite(STDERR, "  [reactivate] the button appears only for the refused account: YES\n");
    }

    /** An account reopened in the meantime stops offering to ask. */
    public function test_the_button_disappears_once_the_account_is_open(): void
    {
        $graduate = $this->graduate();
        $graduate->forceFill(['status' => 'approved'])->save();

        $this->withSession(['reactivation_candidate' => $graduate->id])
            ->get(route('login'))
            ->assertDontSee('Ask the admin to reactivate');

        fwrite(STDERR, "  [reactivate] a reopened account is not offered the button: YES\n");
    }
}
