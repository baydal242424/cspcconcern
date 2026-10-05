<?php

namespace Tests\Feature;

use App\Models\Concern;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * One person, two hats.
 *
 * An office staff member who also covers Staff Admin should see what either
 * role sees and open what either role opens -- not whichever happened to be
 * stored in users.role_id.
 *
 * The primary role is deliberately left alone: it is what the account is
 * listed as, and what routing matches on when choosing a handler. A second hat
 * grants reading and access, not work.
 */
class TwoRolesAtOnceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, UserSeeder::class]);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@cspc.edu.ph')->firstOrFail();
    }

    private function staffMember(): User
    {
        return User::create([
            'name' => 'Office Staff Who Also Covers Admin',
            'email' => 'two.hats@cspc.edu.ph',
            'password' => Hash::make('not-used'),
            'role_id' => Role::where('name', 'Faculty/Staff')->firstOrFail()->id,
            'status' => 'approved',
            'department' => 'College of Computer Studies',
        ]);
    }

    private function give(User $user, string $role): void
    {
        $user->additionalRoles()->syncWithoutDetaching(
            Role::where('name', $role)->firstOrFail()->id
        );

        $user->load('additionalRoles');
    }

    public function test_both_roles_are_recorded_and_the_main_one_is_untouched(): void
    {
        $user = $this->staffMember();

        $this->give($user, 'Staff Admin');

        $this->assertSame('Faculty/Staff', $user->role->name, 'the primary role stays put');
        $this->assertSame(['Faculty/Staff', 'Staff Admin'], $user->allRoleNames());
        $this->assertTrue($user->hasRole('Staff Admin'));
        $this->assertTrue($user->hasRole('Faculty/Staff'));
        $this->assertFalse($user->hasRole('Dean'));

        fwrite(STDERR, "  [two hats] both roles are held, and the main one is unchanged\n");
    }

    /** The pages the second role opens. */
    public function test_the_second_role_opens_its_pages(): void
    {
        $user = $this->staffMember();

        // Faculty/Staff alone reaches neither.
        $this->actingAs($user)->get('/admin/users')->assertForbidden();
        $this->actingAs($user)->get('/dashboard')->assertForbidden();

        $this->give($user, 'Staff Admin');

        $this->actingAs($user->fresh())->get('/admin/users')->assertOk();
        $this->actingAs($user->fresh())->get('/dashboard')->assertOk();

        fwrite(STDERR, "  [two hats] the second role opens Manage Users and the dashboard\n");
    }

    /**
     * And what it can read: the union of both rules, not one or the other.
     */
    public function test_they_see_what_either_role_sees(): void
    {
        $user = $this->staffMember();
        $student = User::where('email', 'student@my.cspc.edu.ph')->firstOrFail();

        $make = fn (array $extra) => Concern::create(array_merge([
            'user_id' => $student->id,
            'category' => 'Academic',
            'department' => 'College of Computer Studies',
            'description' => 'A concern to check who can read it.',
            'status' => 'submitted',
            'is_anonymous' => false,
        ], $extra));

        // Faculty/Staff sees what is referred to Faculty/Staff.
        $theirs = $make(['referred_to' => 'Faculty/Staff', 'status' => 'referred']);

        // Staff Admin sees what is referred to Staff Admin.
        $adminsOwn = $make(['referred_to' => 'Staff Admin', 'status' => 'referred']);

        // Neither sees a counselling case nobody sent them.
        $counselling = $make(['category' => 'Harassment']);

        $this->give($user, 'Staff Admin');
        $user = $user->fresh();

        $visible = Concern::visibleTo($user)->pluck('id');

        $this->assertTrue($visible->contains($theirs->id), 'what their own role is sent');
        $this->assertTrue($visible->contains($adminsOwn->id), 'and what the second role is sent');
        $this->assertFalse($visible->contains($counselling->id), 'but not a case neither role may read');

        fwrite(STDERR, "  [two hats] they read the union of both roles, and nothing beyond it\n");
    }

    /**
     * The wall that must not move: a person never reads a concern about
     * themselves, however many roles they hold.
     */
    public function test_a_second_role_never_opens_a_concern_about_them(): void
    {
        $user = $this->staffMember();
        $student = User::where('email', 'student@my.cspc.edu.ph')->firstOrFail();

        $aboutThem = Concern::create([
            'user_id' => $student->id,
            'category' => 'Academic',
            'department' => 'College of Computer Studies',
            'description' => 'A complaint about the person who holds two roles.',
            'status' => 'referred',
            'referred_to' => 'Staff Admin',
            'about_staff_id' => $user->id,
            'is_anonymous' => false,
        ]);

        $aboutThem->subjects()->attach($user->id);

        $this->give($user, 'Staff Admin');

        $this->assertFalse(
            Concern::whereKey($aboutThem->id)->visibleTo($user->fresh())->exists(),
            'a second hat must not become a way to read the complaint about yourself'
        );

        fwrite(STDERR, "  [two hats] neither hat opens the complaint about the person wearing them\n");
    }

    // --------------------------------------------------------- managing them

    public function test_an_admin_can_grant_and_remove_a_second_role(): void
    {
        $user = $this->staffMember();
        $staffAdmin = Role::where('name', 'Staff Admin')->firstOrFail();

        $this->actingAs($this->admin())
            ->post("/admin/users/{$user->id}/additional-roles", ['role_ids' => [$staffAdmin->id]])
            ->assertRedirect();

        $this->assertTrue($user->fresh()->hasRole('Staff Admin'));

        // Recorded, like every other change to what somebody may do.
        $this->assertSame(
            1,
            DB::table('audit_logs')->where('action', 'additional_roles_updated')->count()
        );

        // And taken away again by sending none.
        $this->actingAs($this->admin())
            ->post("/admin/users/{$user->id}/additional-roles", [])
            ->assertRedirect();

        $this->assertFalse($user->fresh()->hasRole('Staff Admin'));
        $this->assertSame('Faculty/Staff', $user->fresh()->role->name, 'the main role survives either way');

        fwrite(STDERR, "  [two hats] an admin grants and removes the second role, and it is logged\n");
    }

    /** Only a System Admin hands out System Admin, by any route. */
    public function test_a_staff_admin_cannot_hand_out_system_admin_as_an_extra(): void
    {
        $user = $this->staffMember();

        $staffAdmin = User::where('email', 'staffadmin@cspc.edu.ph')->firstOrFail();
        $this->assertSame('Staff Admin', $staffAdmin->role->name);

        $this->actingAs($staffAdmin)
            ->post("/admin/users/{$user->id}/additional-roles", [
                'role_ids' => [Role::where('name', 'System Admin')->firstOrFail()->id],
            ])
            ->assertForbidden();

        $this->assertFalse($user->fresh()->hasRole('System Admin'));

        fwrite(STDERR, "  [two hats] the System Admin guard holds on the extra-roles form too\n");
    }

    /** The role they already hold is not added a second time. */
    public function test_their_own_role_is_not_duplicated(): void
    {
        $user = $this->staffMember();

        $this->actingAs($this->admin())
            ->post("/admin/users/{$user->id}/additional-roles", [
                'role_ids' => [$user->role_id, Role::where('name', 'Staff Admin')->firstOrFail()->id],
            ])
            ->assertRedirect();

        $this->assertSame(['Faculty/Staff', 'Staff Admin'], $user->fresh()->allRoleNames());
        $this->assertSame(1, $user->fresh()->additionalRoles()->count());

        fwrite(STDERR, "  [two hats] the role they already hold is not stored again as an extra\n");
    }
}
