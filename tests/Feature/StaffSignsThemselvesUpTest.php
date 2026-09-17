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
 * A new staff member fills in their own details, and asks for a role.
 *
 * An employee account arrives knowing only the email address: the domain
 * proves they work at CSPC and nothing else, because a dean, a counsellor and
 * an instructor all share cspc.edu.ph. Everything else used to be typed in by
 * an admin, who does not know it.
 *
 * College, programme and section are saved as given -- they describe where
 * somebody works and grant nothing. ROLE is a request, and that distinction is
 * the point of this file. Role IS permission here: scopeVisibleTo() reads
 * nothing else, so a self-assigned Guidance Counselor would read every
 * mental-health and harassment report in the college.
 */
class StaffSignsThemselvesUpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, UserSeeder::class]);
    }

    /** A brand-new employee, exactly as the Google callback leaves them. */
    private function newStaff(): User
    {
        return User::factory()->create([
            'name' => 'New Employee',
            'email' => 'new.employee@cspc.edu.ph',
            'role_id' => Role::where('name', 'Faculty/Staff')->value('id'),
            'department' => null,
            'status' => 'approved',
        ]);
    }

    public function test_a_new_staff_member_is_asked_before_they_reach_the_app(): void
    {
        $staff = $this->newStaff();

        $this->actingAs($staff)
            ->get('/concerns')
            ->assertRedirect(route('profile.complete'));

        $this->actingAs($staff)
            ->get(route('profile.complete'))
            ->assertOk()
            ->assertSee('What is your role?')
            ->assertSee('College or office');

        fwrite(STDERR, "  [signup] a new staff account is asked where they work: YES\n");
    }

    /** Their own details go in as given. The role does not. */
    public function test_the_details_are_saved_but_the_role_is_only_requested(): void
    {
        $staff = $this->newStaff();
        $instructor = Role::where('name', 'Instructor')->value('id');

        $this->actingAs($staff)->post(route('profile.complete.post'), [
            'requested_role_id' => $instructor,
            'employee_id' => '2019-00456',
            'department' => 'College of Computer Studies',
            'section' => '3a',
        ])->assertRedirect(route('concerns.index'));

        $staff->refresh();

        $this->assertSame('College of Computer Studies', $staff->department);
        $this->assertSame('3A', $staff->section, 'the section is normalised');
        $this->assertSame('2019-00456', $staff->employee_id, 'their own staff number, as they entered it');

        $this->assertSame('Faculty/Staff', $staff->role->name, 'the role is NOT granted by asking');
        $this->assertSame($instructor, $staff->requested_role_id);
        $this->assertNotNull($staff->role_requested_at);

        fwrite(STDERR, "  [signup] college saved, role held as a request: YES\n");
    }

    /**
     * The escalation this exists to prevent. Anybody with a staff address
     * could otherwise pick the role that reads every mental-health report.
     */
    public function test_a_role_cannot_be_granted_by_asking_for_it(): void
    {
        $staff = $this->newStaff();

        $this->actingAs($staff)->post(route('profile.complete.post'), [
            'requested_role_id' => Role::where('name', 'Guidance Counselor')->value('id'),
            'employee_id' => '2019-00456',
            'department' => 'Guidance Office',
        ]);

        $staff->refresh();

        $this->assertSame('Faculty/Staff', $staff->role->name);

        // And the thing that actually matters: they still cannot read one.
        $confidential = \App\Models\Concern::create([
            'user_id' => User::where('email', 'student@my.cspc.edu.ph')->firstOrFail()->id,
            'category' => 'Mental Health',
            'department' => 'College of Computer Studies',
            'description' => 'A confidential concern filed while a role request is pending.',
            'status' => 'submitted',
            'is_anonymous' => false,
        ]);

        $this->assertFalse(
            \App\Models\Concern::whereKey($confidential->id)->visibleTo($staff)->exists(),
            'a pending Guidance Counselor request must grant nothing'
        );

        fwrite(STDERR, "  [signup] asking for Guidance Counselor grants no access: YES\n");
    }

    /** The keys are not on the menu at all. */
    public function test_the_admin_roles_cannot_be_requested(): void
    {
        $staff = $this->newStaff();

        foreach (['System Admin', 'Staff Admin', 'Head of School'] as $forbidden) {
            $this->actingAs($staff)->post(route('profile.complete.post'), [
                'requested_role_id' => Role::where('name', $forbidden)->value('id'),
            'employee_id' => '2019-00456',
                'department' => 'Academic Affairs',
            ])->assertSessionHasErrors('requested_role_id');
        }

        $this->assertNull($staff->fresh()->requested_role_id);

        fwrite(STDERR, "  [signup] System Admin, Staff Admin and Head of School cannot be requested: YES\n");
    }

    /**
     * The staff number is asked for, not optional.
     *
     * CSPC's own records key on it, and two people in this database already
     * share a name -- so it is what tells an admin which account is which. The
     * person signing up is the one who knows it.
     */
    public function test_the_employee_id_is_required(): void
    {
        $staff = $this->newStaff();

        $this->actingAs($staff)->post(route('profile.complete.post'), [
            'requested_role_id' => Role::where('name', 'Instructor')->value('id'),
            'department' => 'College of Computer Studies',
        ])->assertSessionHasErrors('employee_id');

        $this->assertNull($staff->fresh()->employee_id);
        $this->assertNull($staff->fresh()->department, 'nothing is saved when the form is refused');

        fwrite(STDERR, "  [signup] the employee ID is required: YES\n");
    }

    /** A programme has to belong to the college they picked. */
    public function test_a_programme_from_another_college_is_refused(): void
    {
        $this->actingAs($this->newStaff())->post(route('profile.complete.post'), [
            'requested_role_id' => Role::where('name', 'Program Chair')->value('id'),
            'employee_id' => '2019-00456',
            'department' => 'College of Computer Studies',
            'course' => 'BS Nursing',
        ])->assertSessionHasErrors('course');

        fwrite(STDERR, "  [signup] a programme from another college is refused: YES\n");
    }

    /** The admins are told, rather than the request sitting unseen. */
    public function test_the_administrators_are_notified(): void
    {
        $staff = $this->newStaff();

        $this->actingAs($staff)->post(route('profile.complete.post'), [
            'requested_role_id' => Role::where('name', 'Instructor')->value('id'),
            'employee_id' => '2019-00456',
            'department' => 'College of Computer Studies',
        ]);

        $notification = Notification::where('type', 'role_request')->firstOrFail();

        $this->assertStringContainsString('New Employee', $notification->message);
        $this->assertStringContainsString('Instructor', $notification->message);

        fwrite(STDERR, "  [signup] the administrators are notified: YES\n");
    }

    /** One press makes it real. */
    public function test_an_admin_grants_the_request(): void
    {
        $staff = $this->newStaff();

        $this->actingAs($staff)->post(route('profile.complete.post'), [
            'requested_role_id' => Role::where('name', 'Instructor')->value('id'),
            'employee_id' => '2019-00456',
            'department' => 'College of Computer Studies',
        ]);

        $this->actingAs(User::where('email', 'admin@cspc.edu.ph')->firstOrFail())
            ->post(route('admin.users.roleRequest', $staff), ['decision' => 'grant'])
            ->assertRedirect();

        $staff->refresh();

        $this->assertSame('Instructor', $staff->role->name);
        $this->assertNull($staff->requested_role_id, 'the answered request is cleared');

        $this->assertTrue(
            Notification::where('user_id', $staff->id)->where('type', 'role_granted')->exists(),
            'the person should be told'
        );

        fwrite(STDERR, "  [signup] granted in one press, and the person is told: YES\n");
    }

    /** Refusing leaves them exactly as they were. */
    public function test_an_admin_refuses_the_request(): void
    {
        $staff = $this->newStaff();

        $this->actingAs($staff)->post(route('profile.complete.post'), [
            'requested_role_id' => Role::where('name', 'Dean')->value('id'),
            'employee_id' => '2019-00456',
            'department' => 'College of Computer Studies',
        ]);

        $this->actingAs(User::where('email', 'admin@cspc.edu.ph')->firstOrFail())
            ->post(route('admin.users.roleRequest', $staff), ['decision' => 'refuse'])
            ->assertRedirect();

        $staff->refresh();

        $this->assertSame('Faculty/Staff', $staff->role->name);
        $this->assertNull($staff->requested_role_id);

        fwrite(STDERR, "  [signup] refused: role unchanged, request cleared: YES\n");
    }

    /** Answered once. A second press must not re-apply anything. */
    public function test_a_request_cannot_be_answered_twice(): void
    {
        $staff = $this->newStaff();

        $this->actingAs($staff)->post(route('profile.complete.post'), [
            'requested_role_id' => Role::where('name', 'Instructor')->value('id'),
            'employee_id' => '2019-00456',
            'department' => 'College of Computer Studies',
        ]);

        $admin = User::where('email', 'admin@cspc.edu.ph')->firstOrFail();

        $this->actingAs($admin)->post(route('admin.users.roleRequest', $staff), ['decision' => 'grant']);
        $this->actingAs($admin)->post(route('admin.users.roleRequest', $staff), ['decision' => 'refuse'])
            ->assertSessionHas('error');

        $this->assertSame('Instructor', $staff->fresh()->role->name);

        fwrite(STDERR, "  [signup] a second decision changes nothing: YES\n");
    }

    /** Once they have said where they work, they are not asked again. */
    public function test_they_are_not_asked_twice(): void
    {
        $staff = $this->newStaff();

        $this->actingAs($staff)->post(route('profile.complete.post'), [
            'requested_role_id' => Role::where('name', 'Instructor')->value('id'),
            'employee_id' => '2019-00456',
            'department' => 'College of Computer Studies',
        ]);

        $this->actingAs($staff->fresh())->get('/concerns')->assertOk();

        fwrite(STDERR, "  [signup] not asked again while the request is pending: YES\n");
    }
}
