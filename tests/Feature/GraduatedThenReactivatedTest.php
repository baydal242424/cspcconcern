<?php

namespace Tests\Feature;

use App\Models\Concern;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Graduating closes an account; it does not erase anybody.
 *
 * A graduate who comes back -- for a transcript, a clearance, an unfinished
 * case -- finds everything they filed still there. Graduation flips a status
 * and nothing else.
 *
 * Deletion is the heavier of the two and is still not destruction: it takes
 * the account out of every list and hides what it filed, and it can be
 * undone. Only erasing a deleted account destroys anything.
 */
class GraduatedThenReactivatedTest extends TestCase
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

    /**
     * @return array{0: User, 1: Concern}
     */
    private function aFinalYearStudentWithAConcern(): array
    {
        $student = User::create([
            'name' => 'Final Year Student',
            'email' => 'finalyear@my.cspc.edu.ph',
            'password' => Hash::make('not-used'),
            'role_id' => Role::where('name', 'Student')->firstOrFail()->id,
            'status' => 'approved',
            'student_id' => '221000111',
            'department' => 'College of Computer Studies',
            'course' => 'BS Information Technology',
            'section' => '4A',
            'google_id' => 'google-finalyear',
        ]);

        $concern = Concern::create([
            'user_id' => $student->id,
            'category' => 'Academic',
            'department' => 'College of Computer Studies',
            'course' => 'BS Information Technology',
            'section' => '4A',
            'description' => 'A concern I filed in my last year of study.',
            'status' => 'resolved',
            'resolved_at' => now()->subWeek(),
            'is_anonymous' => false,
        ]);

        return [$student, $concern];
    }

    public function test_graduating_closes_the_account_without_touching_the_concerns(): void
    {
        [$student, $concern] = $this->aFinalYearStudentWithAConcern();

        $this->actingAs($this->admin())->post('/admin/students/promote')->assertRedirect();

        $student->refresh();

        $this->assertSame('graduated', $student->status, 'the account is closed, not removed');
        $this->assertNotNull(User::find($student->id), 'the account itself survives');
        $this->assertNotNull(Concern::find($concern->id), 'and so does everything they filed');
        $this->assertSame($student->id, $concern->fresh()->user_id);

        fwrite(STDERR, "  [graduated] graduating closes the account and keeps every concern\n");
    }

    /** Reactivated, they find their history exactly as they left it. */
    public function test_reactivating_brings_their_concerns_back_into_view(): void
    {
        [$student, $concern] = $this->aFinalYearStudentWithAConcern();

        $this->actingAs($this->admin())->post('/admin/students/promote')->assertRedirect();
        $this->assertSame('graduated', $student->fresh()->status);

        $this->actingAs($this->admin())->post("/admin/users/{$student->id}/reactivate")->assertRedirect();

        $student->refresh();
        $this->assertSame('approved', $student->status);

        // Same row, same id: nothing was recreated, so nothing had to be
        // reattached.
        $this->assertTrue(
            Concern::whereKey($concern->id)->visibleTo($student)->exists(),
            'their own concern must be readable again'
        );

        $this->actingAs($student)->get('/concerns?show_resolved=1')->assertOk()
            ->assertSee('#'.$concern->id, false);

        $this->actingAs($student)->get("/concerns/{$concern->id}")->assertOk()
            ->assertSee('A concern I filed in my last year of study.', false);

        fwrite(STDERR, "  [graduated] reactivating brings the whole history back, intact\n");
    }

    /**
     * The distinction worth keeping straight: both can be undone, by
     * different people and in different ways.
     *
     * Graduation closes an account that is still listed; deletion takes it
     * out of every list, releases the work it was holding, and hides what it
     * filed. Neither destroys anything -- only erasing does, and that is a
     * separate decision made about an account already deleted.
     */
    public function test_deletion_hides_an_account_where_graduation_only_closes_it(): void
    {
        [$student, $concern] = $this->aFinalYearStudentWithAConcern();

        $this->actingAs($this->admin())->delete("/admin/users/{$student->id}")->assertRedirect();

        // Out of every normal query...
        $this->assertNull(User::find($student->id));

        // ...but nothing destroyed: the row and the concern are both here.
        $this->assertNotNull(User::withTrashed()->find($student->id));
        $this->assertNotNull(Concern::find($concern->id));

        // Reactivate is for graduates and does not reach a deleted account;
        // restoring is what brings this one back.
        $this->actingAs($this->admin())->post("/admin/users/{$student->id}/reactivate")->assertNotFound();

        $this->actingAs($this->admin())->post("/admin/users/{$student->id}/restore")->assertRedirect();

        $this->assertNotNull(User::find($student->id), 'the account is back');
        $this->assertSame($student->id, Concern::find($concern->id)->user_id);

        fwrite(STDERR, "  [graduated] deletion hides, graduation closes -- neither destroys\n");
    }
}
