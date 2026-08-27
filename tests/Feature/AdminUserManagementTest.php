<?php

namespace Tests\Feature;

use App\Models\Concern;
use App\Models\Referral;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, UserSeeder::class]);
    }

    public function test_admin_can_change_a_users_role(): void
    {
        $admin = User::where('email', 'admin@cspc.edu.ph')->first();
        $student = User::where('email', 'student@my.cspc.edu.ph')->first();
        $staffRole = Role::where('name', 'Faculty/Staff')->first();

        $response = $this->actingAs($admin)
            ->from('/admin/users')
            ->post("/admin/users/{$student->id}/role", ['role_id' => $staffRole->id]);

        $response->assertRedirect('/admin/users');
        $this->assertSame($staffRole->id, $student->fresh()->role_id);
    }

    public function test_admin_cannot_change_their_own_role(): void
    {
        $admin = User::where('email', 'admin@cspc.edu.ph')->first();
        $staffRole = Role::where('name', 'Faculty/Staff')->first();

        $this->actingAs($admin)
            ->post("/admin/users/{$admin->id}/role", ['role_id' => $staffRole->id])
            ->assertStatus(422);

        $this->assertSame('Admin', $admin->fresh()->role->name);
    }

    public function test_deleting_a_user_removes_the_account_and_their_own_concerns(): void
    {
        $admin = User::where('email', 'admin@cspc.edu.ph')->first();
        $student = User::where('email', 'student@my.cspc.edu.ph')->first();

        $concern = Concern::create([
            'user_id' => $student->id,
            'category' => 'Academic',
            'department' => 'College of Computer Studies',
            'description' => 'fraudulent report',
            'urgency' => 'Low',
            'status' => 'submitted',
            'is_anonymous' => false,
        ]);

        $response = $this->actingAs($admin)
            ->from('/admin/users')
            ->delete("/admin/users/{$student->id}");

        $response->assertRedirect('/admin/users');
        $this->assertDatabaseMissing('users', ['id' => $student->id]);
        $this->assertDatabaseMissing('concerns', ['id' => $concern->id]);
    }

    public function test_deleting_a_user_involved_in_referrals_does_not_fail(): void
    {
        $admin = User::where('email', 'admin@cspc.edu.ph')->first();
        $staff = User::where('email', 'staff@cspc.edu.ph')->first();
        $counselor = User::where('email', 'counselor@cspc.edu.ph')->first();
        $student = User::where('email', 'student@my.cspc.edu.ph')->first();

        $concern = Concern::create([
            'user_id' => $student->id,
            'category' => 'Mental Health / Personal',
            'department' => 'College of Computer Studies',
            'description' => 'needs referral',
            'urgency' => 'Low',
            'status' => 'referred',
            'is_anonymous' => false,
        ]);

        $referral = Referral::create([
            'concern_id' => $concern->id,
            'referred_by' => $staff->id,
            'referred_to' => $counselor->id,
            'reason' => 'needs counseling',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($admin)
            ->from('/admin/users')
            ->delete("/admin/users/{$staff->id}");

        $response->assertRedirect('/admin/users');
        $this->assertDatabaseMissing('users', ['id' => $staff->id]);
        $this->assertDatabaseMissing('referrals', ['id' => $referral->id]);
        // The concern itself belongs to the student, not the deleted staff member.
        $this->assertDatabaseHas('concerns', ['id' => $concern->id]);
    }

    public function test_admin_cannot_delete_their_own_account(): void
    {
        $admin = User::where('email', 'admin@cspc.edu.ph')->first();

        $this->actingAs($admin)
            ->delete("/admin/users/{$admin->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_non_admin_cannot_change_role_or_delete(): void
    {
        $staff = User::where('email', 'staff@cspc.edu.ph')->first();
        $student = User::where('email', 'student@my.cspc.edu.ph')->first();
        $studentRole = Role::where('name', 'Student')->first();

        $this->actingAs($staff)
            ->post("/admin/users/{$student->id}/role", ['role_id' => $studentRole->id])
            ->assertForbidden();

        $this->actingAs($staff)
            ->delete("/admin/users/{$student->id}")
            ->assertForbidden();
    }
}
