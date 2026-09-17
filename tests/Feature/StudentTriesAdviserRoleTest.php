<?php

namespace Tests\Feature;

use App\Models\Concern;
use App\Models\Role;
use App\Models\Section;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * An administrator turns a student into an Adviser to try the role out, then
 * turns them back. The round trip has to work, and it has to leave nothing
 * behind: no class still pointing at a student, no student number lost.
 */
class StudentTriesAdviserRoleTest extends TestCase
{
    use RefreshDatabase;

    private const CCS = 'College of Computer Studies';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, UserSeeder::class]);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@cspc.edu.ph')->firstOrFail();
    }

    private function role(string $name): int
    {
        return Role::where('name', $name)->firstOrFail()->id;
    }

    private function tester(): User
    {
        return User::create([
            'name' => 'Student Trying A Role',
            'email' => 'tries.role@my.cspc.edu.ph',
            'password' => Hash::make('not-used'),
            'role_id' => $this->role('Student'),
            'department' => self::CCS,
            'course' => 'BS Information Systems',
            'section' => '4A',
            'student_id' => '231009999',
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);
    }

    public function test_a_student_made_adviser_can_be_given_a_class_and_receives_its_concerns(): void
    {
        $tester = $this->tester();

        $this->actingAs($this->admin())->post(route('admin.users.role', $tester), [
            'role_id' => $this->role('Adviser'),
            'department' => self::CCS,
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->admin())->post(route('admin.users.sections.assign', $tester), [
            'course' => 'BS Information Systems',
            'year' => '2',
            'section_letter' => 'C',
        ])->assertSessionHasNoErrors();

        $this->assertSame($tester->id, optional(Section::adviserFor('BS Information Systems', '2C'))->id);

        $student = User::where('email', 'student@my.cspc.edu.ph')->firstOrFail();
        $student->forceFill(['department' => self::CCS, 'course' => 'BS Information Systems', 'section' => '2C'])->save();

        $this->actingAs($student->refresh())->post('/concerns', [
            'category' => 'Academic',
            'description' => 'A concern from BSIS 2C, to see the test adviser receive it.',
        ]);

        $this->assertSame($tester->id, Concern::latest('id')->firstOrFail()->assigned_to);

        fwrite(STDERR, "  [try-role] student -> Adviser -> Add class -> receives that class's concern\n");
    }

    public function test_turning_them_back_into_a_student_leaves_nothing_behind(): void
    {
        $tester = $this->tester();

        $this->actingAs($this->admin())->post(route('admin.users.role', $tester), [
            'role_id' => $this->role('Adviser'),
            'department' => self::CCS,
        ]);
        $this->actingAs($this->admin())->post(route('admin.users.sections.assign', $tester), [
            'course' => 'BS Information Systems',
            'year' => '2',
            'section_letter' => 'C',
        ]);

        $this->assertSame('231009999', $tester->fresh()->student_id, 'the student number survives the staff role');

        $this->actingAs($this->admin())->post(route('admin.users.role', $tester), [
            'role_id' => $this->role('Student'),
            'department' => self::CCS,
            'course' => 'BS Information Systems',
            'year' => '4',
            'section_letter' => 'A',
            'student_id' => '231009999',
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, Section::where('adviser_id', $tester->id)->count(), 'no class still points at a student');
        $this->assertSame('231009999', $tester->fresh()->student_id);
        $this->assertSame('Student', $tester->fresh()->role->name);

        fwrite(STDERR, "  [try-role] back to Student -> classes released, student number kept\n");
    }

    /** A staff address still holds one number, as before. */
    public function test_a_staff_address_still_drops_a_student_number(): void
    {
        $staff = User::create([
            'name' => 'Staff With Stray Number',
            'email' => 'stray.number@cspc.edu.ph',
            'password' => Hash::make('not-used'),
            'role_id' => $this->role('Student'),
            'department' => self::CCS,
            'student_id' => '231008888',
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($this->admin())->post(route('admin.users.role', $staff), [
            'role_id' => $this->role('Instructor'),
            'department' => self::CCS,
        ])->assertSessionHasNoErrors();

        $this->assertNull($staff->fresh()->student_id);

        fwrite(STDERR, "  [try-role] a cspc.edu.ph staff account still holds no student number\n");
    }
}
