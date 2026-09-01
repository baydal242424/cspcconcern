<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\Faculty\CcsFacultySeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An admin can now set a person's college and programme, not just their role.
 *
 * This is the field routing actually turns on. findHandler() prefers a handler
 * from the reporter's own college, so an instructor left with no department is
 * skipped and their college's concerns land on whoever happens to sort first --
 * with nothing on screen suggesting anything is wrong.
 */
class AdminAssignsCollegeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, UserSeeder::class, CcsFacultySeeder::class]);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@cspc.edu.ph')->firstOrFail();
    }

    /** Role and college can be set together, in one action. */
    public function test_admin_can_assign_an_instructor_to_a_college(): void
    {
        $person = User::where('email', 'jeremyneo@cspc.edu.ph')->firstOrFail();
        $faculty = Role::where('name', 'Faculty/Staff')->firstOrFail();

        $this->actingAs($this->admin())->post("/admin/users/{$person->id}/role", [
            'role_id' => $faculty->id,
            'department' => 'College of Health Sciences',
            'course' => '',
        ])->assertRedirect();

        $person->refresh();
        $this->assertSame('Faculty/Staff', $person->role->name);
        $this->assertSame('College of Health Sciences', $person->department);

        fwrite(STDERR, "  [assign] instructor moved to {$person->department}\n");
    }

    /** A Program Chair can be pinned to the programme they cover. */
    public function test_admin_can_assign_a_chair_to_a_programme(): void
    {
        $person = User::where('email', 'jeremyneo@cspc.edu.ph')->firstOrFail();
        $chair = Role::where('name', 'Program Chair')->firstOrFail();

        $this->actingAs($this->admin())->post("/admin/users/{$person->id}/role", [
            'role_id' => $chair->id,
            'department' => 'College of Computer Studies',
            'course' => 'BS Information Systems',
        ])->assertRedirect();

        $person->refresh();
        $this->assertSame('Program Chair', $person->role->name);
        $this->assertSame('BS Information Systems', $person->course);

        fwrite(STDERR, "  [assign] chair pinned to {$person->course}\n");
    }

    /** A made-up programme is refused rather than stored. */
    public function test_an_unknown_programme_is_rejected(): void
    {
        $person = User::where('email', 'jeremyneo@cspc.edu.ph')->firstOrFail();

        $this->actingAs($this->admin())->post("/admin/users/{$person->id}/role", [
            'role_id' => $person->role_id,
            'department' => 'College of Computer Studies',
            'course' => 'BSIS',
        ])->assertSessionHasErrors('course');

        $this->assertNull($person->refresh()->course);

        fwrite(STDERR, "  [guard] 'BSIS' refused -- must match a real programme name\n");
    }

    /**
     * A role-only post must not blank the college. Wiping it silently removes
     * that person from their college's routing.
     */
    public function test_a_role_only_update_leaves_the_college_alone(): void
    {
        $person = User::where('email', 'ccs.instructor@cspc.edu.ph')->firstOrFail();
        $this->assertSame('College of Computer Studies', $person->department);

        $this->actingAs($this->admin())->post("/admin/users/{$person->id}/role", [
            'role_id' => Role::where('name', 'Dean')->firstOrFail()->id,
        ])->assertRedirect();

        $person->refresh();
        $this->assertSame('Dean', $person->role->name);
        $this->assertSame('College of Computer Studies', $person->department);

        fwrite(STDERR, "  [safety] role-only update kept the college: YES\n");
    }

    /** The college and programme pickers are actually on the page. */
    public function test_the_page_offers_college_and_programme(): void
    {
        $resp = $this->actingAs($this->admin())->get('/admin/users');
        $resp->assertOk();
        $resp->assertSee('name="department"', false);
        $resp->assertSee('name="course"', false);
        $resp->assertSee('College of Health Sciences', false);
        $resp->assertSee('BS Information Systems', false);

        fwrite(STDERR, "  [ui] college and programme pickers rendered: YES\n");
    }
}
