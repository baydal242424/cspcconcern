<?php

namespace Tests\Feature;

use App\Models\Concern;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guards the office roles added for CSPC's real org chart --
 * General Services, Gender and Development.
 *
 * Two bugs are pinned here, both from adding those roles:
 *
 *  1. They were left out of ConcernController::STAFF_ROLES, so a student
 *     reporting one of those offices was told "The selected person is not a
 *     staff member". The conflict-of-interest flag never got set, and
 *     scopeVisibleTo() then handed the office its own complaint.
 *
 *  2. Several of the roles have exactly ONE holder, so a concern filed ABOUT
 *     that person left their role with nobody eligible. The escalation chain
 *     stopped at Head of School -- a role with no holder in production --
 *     after which the concern was created unassigned and visible to nobody
 *     but its reporter.
 */
class OfficeRoleRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    /**
     * UserFactory does not set `status`, and UpdateLastSeen signs out anyone
     * who is not 'approved' on their very next request -- so every account
     * here has to be created approved, or the POST is bounced to /login and
     * never reaches the controller.
     */
    private function officer(string $roleName, string $email, ?string $department = null): User
    {
        return User::factory()->create([
            'email' => $email,
            'role_id' => Role::where('name', $roleName)->value('id'),
            'department' => $department,
            'status' => 'approved',
        ]);
    }

    private function student(): User
    {
        return User::factory()->create([
            'email' => 'reporter@my.cspc.edu.ph',
            'role_id' => Role::where('name', 'Student')->value('id'),
            'department' => 'College of Computer Studies',
            'course' => 'BS Information Technology',
            // Sign-up requires a section: it identifies the class adviser,
            // who receives four of the eleven categories ahead of anybody
            // else. A student without one cannot reach the app.
            'section' => '3A',
            'student_id' => '2024-00001',
            'status' => 'approved',
        ]);
    }

    /** @return array<int, array{0: string, 1: string, 2: string}> */
    public static function officeRoles(): array
    {
        return [
            'General Services' => ['General Services', 'Facilities', 'gsu@cspc.edu.ph'],
            'Gender and Development' => ['Gender and Development', 'Bullying', 'gad@cspc.edu.ph'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('officeRoles')]
    public function test_a_student_can_report_an_office_holder(string $roleName, string $category, string $email): void
    {
        $student = $this->student();
        $officer = $this->officer($roleName, $email);

        $this->actingAs($student)->post('/concerns', [
            'category' => $category,
            'description' => 'A complaint about how this office handled my request.',
            'about_staff_id' => $officer->id,
        ])->assertSessionHasNoErrors();

        $concern = Concern::latest('id')->firstOrFail();
        $this->assertSame($officer->id, $concern->about_staff_id, "{$roleName} must be nameable as a concern's subject");
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('officeRoles')]
    public function test_the_reported_office_never_handles_its_own_complaint(string $roleName, string $category, string $email): void
    {
        $student = $this->student();
        $officer = $this->officer($roleName, $email);
        // Somebody for the escalation chain to land on.
        $this->officer('Dean', 'ccs@cspc.edu.ph', 'College of Computer Studies');

        $this->actingAs($student)->post('/concerns', [
            'category' => $category,
            'description' => 'A complaint about how this office handled my request.',
            'about_staff_id' => $officer->id,
        ]);

        $concern = Concern::latest('id')->firstOrFail();

        $this->assertNotNull($concern->assigned_to, 'A concern about the sole office holder must still reach somebody');
        $this->assertNotSame($officer->id, $concern->assigned_to, 'The reported person must never be the handler');
        $this->assertFalse(
            Concern::whereKey($concern->id)->visibleTo($officer)->exists(),
            'The reported person must be walled off from the complaint entirely'
        );
    }

    public function test_escalation_falls_through_to_an_admin_when_nobody_else_is_eligible(): void
    {
        // No Dean and no Head of School -- which is the real
        // production shape, since CSPC has not named a Head of School here.
        $student = $this->student();
        $officer = $this->officer('General Services', 'gsu.test@cspc.edu.ph');
        $admin = $this->officer('Admin', 'sysadmin@cspc.edu.ph');

        $this->actingAs($student)->post('/concerns', [
            'category' => 'Facilities',
            'description' => 'A complaint about how the maintenance office handled my request.',
            'about_staff_id' => $officer->id,
        ]);

        $concern = Concern::latest('id')->firstOrFail();

        // Without the Admin fallback this was NULL and the concern was
        // invisible to every account in the system.
        $this->assertSame($admin->id, $concern->assigned_to);
    }
}
