<?php

namespace Tests\Feature;

use App\Models\Concern;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\Faculty\CcsFacultySeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * What happens, step by step, when an administrator replaces a seeded dean
 * with the real one from Manage Users.
 *
 * Routing and the pickers read roles at the moment they run, so a new holder is
 * picked up with no other change. The two things worth pinning down are the
 * gap in between -- while both accounts hold the role -- and what deleting the
 * old account does to the work it was holding.
 */
class ReplacingADeanTest extends TestCase
{
    use RefreshDatabase;

    private const CCS = 'College of Computer Studies';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, UserSeeder::class, CcsFacultySeeder::class]);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@cspc.edu.ph')->firstOrFail();
    }

    private function oldDean(): User
    {
        return User::where('email', 'ccs@cspc.edu.ph')->firstOrFail();
    }

    private function student(): User
    {
        $student = User::where('email', 'student@my.cspc.edu.ph')->firstOrFail();
        $student->forceFill([
            'department' => self::CCS,
            'course' => 'BS Information Technology',
            'section' => '3A',
        ])->save();

        return $student->refresh();
    }

    /** A concern naming a chair goes to the dean -- the path that shows who holds the post. */
    private function fileAboutAChair(User $student): Concern
    {
        $chair = User::whereHas('role', fn ($q) => $q->where('name', 'Program Chair'))
            ->where('department', self::CCS)
            ->firstOrFail();

        $this->actingAs($student)->post('/concerns', [
            'category' => 'Academic',
            'description' => 'A concern about a program chair, to see which dean receives it.',
            'about_staff_id' => [$chair->id],
        ]);

        return Concern::latest('id')->firstOrFail();
    }

    /** Somebody who signed in with their own address and was made Dean by an admin. */
    private function promoteNewDean(): User
    {
        $newDean = User::create([
            'name' => 'The Real Dean',
            'email' => 'real.dean@cspc.edu.ph',
            'password' => Hash::make('not-used'),
            'role_id' => Role::where('name', 'Faculty/Staff')->firstOrFail()->id,
            'department' => self::CCS,
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($this->admin())->post("/admin/users/{$newDean->id}/role", [
            'role_id' => Role::where('name', 'Dean')->firstOrFail()->id,
            'department' => self::CCS,
            'course' => '',
        ])->assertSessionHasNoErrors();

        $this->assertSame('Dean', $newDean->refresh()->role->name);

        return $newDean;
    }

    /** Step 1: while BOTH accounts hold the role, the older one still receives. */
    public function test_while_both_exist_the_old_dean_still_receives(): void
    {
        $oldDean = $this->oldDean();
        $newDean = $this->promoteNewDean();

        $concern = $this->fileAboutAChair($this->student());

        $this->assertSame($oldDean->id, $concern->assigned_to);
        $this->assertNotSame($newDean->id, $concern->assigned_to);

        fwrite(STDERR, "  [swap] both hold Dean -> the OLD dean still receives (first on record)\n");
    }

    /** Step 2: once the old account is gone, routing and the picker both switch. */
    public function test_after_deleting_the_old_dean_the_new_one_receives_and_is_listed(): void
    {
        $oldDean = $this->oldDean();
        $newDean = $this->promoteNewDean();
        $student = $this->student();

        $this->actingAs($this->admin())->delete("/admin/users/{$oldDean->id}")->assertRedirect();
        $this->assertNull(User::find($oldDean->id));

        $concern = $this->fileAboutAChair($student);
        $this->assertSame($newDean->id, $concern->assigned_to, 'new concerns reach the new dean');

        $offered = $this->actingAs($student)->get('/concerns/create')
            ->assertOk()
            ->viewData('otherStaffByOffice')
            ->flatten()
            ->pluck('id');

        $this->assertTrue($offered->contains($newDean->id), 'the new dean is in the student list');
        $this->assertFalse($offered->contains($oldDean->id), 'the old one is not');

        fwrite(STDERR, "  [swap] old dean deleted -> new concerns and the student picker use the new dean\n");
    }

    /**
     * Step 3, the catch: deleting the old account does not hand its open work
     * to anybody. Those concerns are left assigned to nobody.
     */
    public function test_deleting_the_old_dean_leaves_their_open_concerns_unassigned(): void
    {
        $oldDean = $this->oldDean();
        $this->promoteNewDean();

        $concern = $this->fileAboutAChair($this->student());
        $concern->update(['status' => 'in_progress']);
        $this->assertSame($oldDean->id, $concern->assigned_to);

        $this->actingAs($this->admin())->delete("/admin/users/{$oldDean->id}")->assertRedirect();

        $this->assertNull($concern->refresh()->assigned_to, 'the open concern is now assigned to nobody');

        fwrite(STDERR, "  [swap] CATCH: the old dean's open concern is left unassigned after delete\n");
    }

    /** Changing the role instead of deleting keeps the account, and hands the post over. */
    public function test_demoting_the_old_dean_instead_of_deleting_also_hands_the_post_over(): void
    {
        $oldDean = $this->oldDean();
        $newDean = $this->promoteNewDean();

        $this->actingAs($this->admin())->post("/admin/users/{$oldDean->id}/role", [
            'role_id' => Role::where('name', 'Faculty/Staff')->firstOrFail()->id,
            'department' => self::CCS,
            'course' => '',
        ])->assertSessionHasNoErrors();

        $concern = $this->fileAboutAChair($this->student());

        $this->assertSame($newDean->id, $concern->assigned_to);
        $this->assertNotNull(User::find($oldDean->id), 'the old account still exists');

        fwrite(STDERR, "  [swap] old dean demoted, not deleted -> new dean receives, history kept\n");
    }
}
