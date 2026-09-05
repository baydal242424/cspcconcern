<?php

namespace Tests\Feature;

use App\Models\Concern;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Two admin roles, doing two different jobs.
 *
 *   System Admin  runs the system: accounts, roles, bans, the start-of-year
 *                 promotion. Reads no category by default.
 *   Staff Admin   the administrative office: receives Administrative concerns
 *                 and refers them on. Manages nobody.
 *
 * They were one role, and it conflated the two. Whoever could delete accounts
 * and change roles was also reading every complaint about the registrar's
 * queue -- in practice the student who built the app. Splitting them means
 * each holds only what its job needs.
 */
class AdminTiersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, UserSeeder::class]);
    }

    private function systemAdmin(): User
    {
        return User::where('email', 'admin@cspc.edu.ph')->firstOrFail();
    }

    private function staffAdmin(): User
    {
        return User::where('email', 'staffadmin@cspc.edu.ph')->firstOrFail();
    }

    public function test_both_roles_exist_and_are_distinct(): void
    {
        $this->assertSame('System Admin', $this->systemAdmin()->role->name);
        $this->assertSame('Staff Admin', $this->staffAdmin()->role->name);

        fwrite(STDERR, "  [tiers] System Admin and Staff Admin are separate roles: YES\n");
    }

    /** Administrative concerns reach the office, not the system's operators. */
    public function test_administrative_concerns_go_to_the_office(): void
    {
        $this->actingAs(User::where('email', 'student@my.cspc.edu.ph')->firstOrFail())
            ->post('/concerns', [
                'category' => 'Administrative',
                'description' => 'I need a copy of my registration record for a scholarship.',
            ]);

        $concern = Concern::latest('id')->firstOrFail();

        $this->assertSame('Staff Admin', optional(optional($concern->assignedUser)->role)->name);

        fwrite(STDERR, '  [tiers] Administrative reached '.$concern->assignedUser->name."\n");
    }

    /** Both tiers manage accounts, so the office can cover. */
    public function test_both_tiers_can_manage_accounts(): void
    {
        $this->actingAs($this->systemAdmin())->get(route('admin.users'))->assertOk();
        $this->actingAs($this->staffAdmin())->get(route('admin.users'))->assertOk();

        fwrite(STDERR, "  [tiers] both tiers can open Manage Users: YES\n");
    }

    /** A Staff Admin does the everyday work. */
    public function test_a_staff_admin_can_change_an_ordinary_role(): void
    {
        $student = User::where('email', 'student@my.cspc.edu.ph')->firstOrFail();

        $this->actingAs($this->staffAdmin())
            ->post(route('admin.users.role', $student), [
                'role_id' => Role::where('name', 'Instructor')->value('id'),
                'department' => 'College of Computer Studies',
            ])
            ->assertRedirect();

        $this->assertSame('Instructor', $student->fresh()->role->name);

        fwrite(STDERR, "  [tiers] a Staff Admin can change an ordinary role: YES\n");
    }

    /**
     * The one line they may not cross. Without it a Staff Admin promotes
     * themselves and the two tiers stop meaning anything.
     */
    public function test_a_staff_admin_cannot_appoint_a_system_admin(): void
    {
        $student = User::where('email', 'student@my.cspc.edu.ph')->firstOrFail();

        $this->actingAs($this->staffAdmin())
            ->post(route('admin.users.role', $student), [
                'role_id' => Role::where('name', 'System Admin')->value('id'),
            ])
            ->assertForbidden();

        $this->assertSame('Student', $student->fresh()->role->name);

        fwrite(STDERR, "  [tiers] a Staff Admin cannot appoint a System Admin: YES\n");
    }

    /**
     * Nor take one out of the way. Banning the System Admin would leave the
     * Staff Admin as the only administrator -- covering turned into taking
     * over.
     */
    public function test_a_staff_admin_cannot_ban_or_demote_a_system_admin(): void
    {
        $systemAdmin = $this->systemAdmin();

        $this->actingAs($this->staffAdmin())
            ->post(route('admin.users.ban', $systemAdmin), ['reason' => 'testing'])
            ->assertForbidden();

        $this->actingAs($this->staffAdmin())
            ->post(route('admin.users.role', $systemAdmin), [
                'role_id' => Role::where('name', 'Student')->value('id'),
            ])
            ->assertForbidden();

        $this->actingAs($this->staffAdmin())
            ->delete(route('admin.users.destroy', $systemAdmin))
            ->assertForbidden();

        $systemAdmin->refresh();
        $this->assertSame('approved', $systemAdmin->status);
        $this->assertSame('System Admin', $systemAdmin->role->name);

        fwrite(STDERR, "  [tiers] a Staff Admin cannot ban, demote or delete a System Admin: YES\n");
    }

    /** A System Admin can appoint either tier. */
    public function test_the_system_admin_can_appoint_both_tiers(): void
    {
        $person = User::where('email', 'staff@cspc.edu.ph')->firstOrFail();

        foreach (['Staff Admin', 'System Admin'] as $target) {
            $this->actingAs($this->systemAdmin())
                ->post(route('admin.users.role', $person), [
                    'role_id' => Role::where('name', $target)->value('id'),
                    'department' => 'Academic Affairs',
                ])
                ->assertRedirect();

            $this->assertSame($target, $person->fresh()->role->name);
        }

        fwrite(STDERR, "  [tiers] a System Admin can appoint both admin roles: YES\n");
    }

    /**
     * The narrowing that came with the split. Running the system is not a
     * reason to read students' complaints.
     */
    public function test_the_system_admin_has_no_standing_view_of_any_category(): void
    {
        $student = User::where('email', 'student@my.cspc.edu.ph')->firstOrFail();

        foreach (Concern::CATEGORIES as $category) {
            $concern = Concern::create([
                'user_id' => $student->id,
                'category' => $category,
                'department' => 'College of Computer Studies',
                'description' => 'A concern filed to check what an operator can read.',
                'status' => 'submitted',
                'is_anonymous' => false,
            ]);

            $this->assertFalse(
                Concern::whereKey($concern->id)->visibleTo($this->systemAdmin())->exists(),
                "A System Admin must not have a standing view of {$category}"
            );
        }

        fwrite(STDERR, '  [tiers] a System Admin reads none of the '
            .count(Concern::CATEGORIES)." categories by default: YES\n");
    }

    /** What they do still see: what was handed to them personally. */
    public function test_the_system_admin_still_sees_what_is_assigned_to_them(): void
    {
        $admin = $this->systemAdmin();

        $concern = Concern::create([
            'user_id' => User::where('email', 'student@my.cspc.edu.ph')->firstOrFail()->id,
            'category' => 'Administrative',
            'department' => 'College of Computer Studies',
            'description' => 'A concern escalated to the system administrator.',
            'status' => 'submitted',
            'assigned_to' => $admin->id,
            'is_anonymous' => false,
        ]);

        $this->assertTrue(Concern::whereKey($concern->id)->visibleTo($admin)->exists());

        fwrite(STDERR, "  [tiers] a System Admin still sees what is assigned to them: YES\n");
    }

    /**
     * A complaint about the administration does not stay with the
     * administration. The office is small and its members answer to each
     * other, so it climbs instead.
     */
    public function test_a_concern_about_the_office_escalates_past_it(): void
    {
        $office = $this->staffAdmin();

        $this->actingAs(User::where('email', 'student@my.cspc.edu.ph')->firstOrFail())
            ->post('/concerns', [
                'category' => 'Administrative',
                'description' => 'The records office lost my form and was rude about it.',
                'about_staff_id' => $office->id,
            ]);

        $concern = Concern::latest('id')->firstOrFail();

        $this->assertNotNull($concern->assigned_to, 'it still has to reach somebody');
        $this->assertNotSame($office->id, $concern->assigned_to);
        $this->assertNotSame(
            'Staff Admin',
            optional(optional($concern->assignedUser)->role)->name,
            'a complaint about the office must not be handed to the office'
        );

        fwrite(STDERR, '  [tiers] a concern about the office went to '
            .optional(optional($concern->assignedUser)->role)->name."\n");
    }
}
