<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BanEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, UserSeeder::class]);
    }

    public function test_admin_can_ban_a_user_and_it_is_reflected_in_the_users_list(): void
    {
        $admin = User::where('email', 'admin@cspc.edu.ph')->first();
        $student = User::where('email', 'student@my.cspc.edu.ph')->first();

        $response = $this->actingAs($admin)
            ->from('/admin/users')
            ->post("/admin/users/{$student->id}/ban", [
                'reason' => 'Suspected fake CSPC account',
            ]);

        $response->assertRedirect('/admin/users');
        $student->refresh();
        $this->assertSame('banned', $student->status);
        $this->assertSame($admin->id, $student->banned_by);
        $this->assertSame('Suspected fake CSPC account', $student->ban_reason);
        $this->assertNotNull($student->banned_at);
    }

    public function test_a_banned_user_with_an_active_session_is_signed_out_on_next_request(): void
    {
        $admin = User::where('email', 'admin@cspc.edu.ph')->first();
        $student = User::where('email', 'student@my.cspc.edu.ph')->first();

        // Simulate the student already being logged in (an existing session)
        // before the ban happens.
        $this->actingAs($student);

        $student->update([
            'status' => 'banned',
            'banned_by' => $admin->id,
            'banned_at' => now(),
            'ban_reason' => 'fraud',
        ]);

        $response = $this->get('/concerns');

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_there_is_no_password_login_to_bypass_the_ban_with(): void
    {
        // CSPC Mail is the only way in, so a ban cannot be worked around by
        // falling back to a password. The ban itself is enforced on every
        // authenticated request by the UpdateLastSeen middleware, which the
        // test above covers; this one guards the door that used to exist.
        $student = User::where('email', 'student@my.cspc.edu.ph')->first();
        $student->update(['status' => 'banned']);

        // 405: /login still answers GET, but no longer accepts a POST.
        $this->post('/login', [
            'email' => 'student@my.cspc.edu.ph',
            'password' => 'password',
        ])->assertStatus(405);

        $this->assertGuest();
    }

    public function test_the_password_reset_flow_is_gone(): void
    {
        // No passwords exist to reset. Leaving these routes live would be a
        // way to set a usable password on an account and sign in around
        // Google entirely.
        $this->get('/forgot-password')->assertNotFound();
        $this->post('/forgot-password', ['email' => 'student@my.cspc.edu.ph'])->assertNotFound();
        $this->post('/reset-password', [])->assertNotFound();
    }

    public function test_unban_restores_access(): void
    {
        $admin = User::where('email', 'admin@cspc.edu.ph')->first();
        $student = User::where('email', 'student@my.cspc.edu.ph')->first();
        $student->update([
            'status' => 'banned',
            'banned_by' => $admin->id,
            'banned_at' => now(),
            'ban_reason' => 'fraud',
        ]);

        $response = $this->actingAs($admin)
            ->from('/admin/users')
            ->post("/admin/users/{$student->id}/unban");

        $response->assertRedirect('/admin/users');
        $student->refresh();
        $this->assertSame('approved', $student->status);
        $this->assertNull($student->banned_by);
        $this->assertNull($student->ban_reason);
    }

    public function test_non_admin_cannot_access_the_users_list_or_ban(): void
    {
        $staff = User::where('email', 'staff@cspc.edu.ph')->first();
        $student = User::where('email', 'student@my.cspc.edu.ph')->first();

        $this->actingAs($staff)->get('/admin/users')->assertForbidden();
        $this->actingAs($staff)->post("/admin/users/{$student->id}/ban")->assertForbidden();
    }

    public function test_online_flag_reflects_recent_activity(): void
    {
        $student = User::where('email', 'student@my.cspc.edu.ph')->first();

        $student->last_seen_at = now();
        $this->assertTrue($student->is_online);

        $student->last_seen_at = now()->subMinutes(30);
        $this->assertFalse($student->is_online);

        $student->last_seen_at = null;
        $this->assertFalse($student->is_online);
    }
}
