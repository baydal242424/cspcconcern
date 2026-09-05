<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One button moves every student up a year level.
 *
 * The alternative was an admin opening 500-odd accounts and editing a digit in
 * each, which nobody does -- so sections went stale, and a stale section is
 * worse than none: it routes a student's academic concerns to the adviser of
 * the class they left a year ago.
 *
 * The edges carry the risk here, not the happy path. A bulk write over every
 * student is only safe if it moves exactly who it should and can be put back.
 */
class YearLevelPromotionTest extends TestCase
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

    private function student(string $section, string $course = 'BS Information Technology'): User
    {
        return User::factory()->create([
            'role_id' => Role::where('name', 'Student')->value('id'),
            'department' => 'College of Computer Studies',
            'course' => $course,
            'section' => $section,
            'status' => 'approved',
        ]);
    }

    public function test_it_moves_every_student_up_one_year(): void
    {
        $first = $this->student('1A');
        $second = $this->student('2C');
        $third = $this->student('3B');

        $this->actingAs($this->admin())
            ->post(route('admin.students.promote'))
            ->assertRedirect();

        $this->assertSame('2A', $first->fresh()->section);
        $this->assertSame('3C', $second->fresh()->section, 'the class letter must not change');
        $this->assertSame('4B', $third->fresh()->section);

        fwrite(STDERR, "  [promote] 1A→2A, 2C→3C, 3B→4B in one action: YES\n");
    }

    /**
     * The rule that decides who is closed out.
     *
     * A fourth year is graduating, not advancing. Pushing them into a fifth
     * year would invent a section nobody advises and quietly drop them out of
     * adviser routing into the college fallback. The account closes instead,
     * which is what takes them off the list.
     */
    public function test_a_final_year_student_is_closed_as_graduated(): void
    {
        $graduating = $this->student('4A');

        $this->actingAs($this->admin())->post(route('admin.students.promote'));

        $graduating->refresh();
        $this->assertSame('4A', $graduating->section, 'there is no year above theirs');
        $this->assertSame('graduated', $graduating->status);

        fwrite(STDERR, "  [promote] a final-year account closes as graduated: YES\n");
    }

    /**
     * The irregular student's way back.
     *
     * Nothing in the data tells them apart from a graduate — the system holds
     * a year and a section, not a curriculum — so they are closed with
     * everybody else, told to ask, and a person decides.
     */
    public function test_a_graduated_student_is_refused_and_can_be_reactivated(): void
    {
        $irregular = $this->student('4A');

        $this->actingAs($this->admin())->post(route('admin.students.promote'));
        $this->assertSame('graduated', $irregular->fresh()->status);

        // Closed: an open session ends on the very next request.
        $this->actingAs($irregular->fresh())->get('/concerns')->assertRedirect(route('login'));

        $this->actingAs($this->admin())
            ->post(route('admin.users.reactivate', $irregular))
            ->assertRedirect();

        $this->assertSame('approved', $irregular->fresh()->status);
        $this->actingAs($irregular->fresh())->get('/concerns')->assertOk();

        fwrite(STDERR, "  [promote] graduated is refused, then reactivated by the admin: YES\n");
    }

    /** Architecture runs five years, so its fourth years still have one to go. */
    public function test_a_five_year_programme_promotes_into_fifth_year(): void
    {
        $architect = $this->student('4A', 'BS Architecture');
        $engineer = $this->student('4A', 'BS Civil Engineering');

        $this->actingAs($this->admin())->post(route('admin.students.promote'));

        $this->assertSame('5A', $architect->fresh()->section, 'Architecture runs five years');
        $this->assertSame('approved', $architect->fresh()->status, 'still enrolled, not graduating');

        $this->assertSame('4A', $engineer->fresh()->section, 'Civil Engineering runs four');
        $this->assertSame('graduated', $engineer->fresh()->status);

        fwrite(STDERR, "  [promote] architecture 4A→5A, engineering 4A stays: YES\n");
    }

    /** Staff have no year level and must not be touched. */
    public function test_staff_are_untouched(): void
    {
        $instructor = User::factory()->create([
            'role_id' => Role::where('name', 'Instructor')->value('id'),
            'department' => 'College of Computer Studies',
            'section' => '1A',
            'status' => 'approved',
        ]);

        $this->actingAs($this->admin())->post(route('admin.students.promote'));

        $this->assertSame('1A', $instructor->fresh()->section);

        fwrite(STDERR, "  [promote] staff sections are not touched: YES\n");
    }

    /** An unreadable section is skipped, never guessed at. */
    public function test_an_unreadable_section_is_skipped(): void
    {
        $odd = $this->student('1A');
        $odd->forceFill(['section' => 'irregular'])->save();

        $this->actingAs($this->admin())->post(route('admin.students.promote'));

        $this->assertSame('irregular', $odd->fresh()->section);

        fwrite(STDERR, "  [promote] an unreadable section is left as it is: YES\n");
    }

    /** One button over every student needs a way back. */
    public function test_the_last_run_can_be_undone(): void
    {
        $student = $this->student('2A');

        $this->actingAs($this->admin())->post(route('admin.students.promote'));
        $this->assertSame('3A', $student->fresh()->section);

        $this->actingAs($this->admin())
            ->post(route('admin.students.promote.undo'))
            ->assertRedirect();

        $this->assertSame('2A', $student->fresh()->section);

        fwrite(STDERR, "  [promote] the run can be put back exactly: YES\n");
    }

    /**
     * The undo reverses what was recorded, not "one year off everybody".
     * A student edited since keeps the newer value -- that edit is later
     * information than the undo.
     */
    public function test_undo_leaves_alone_anyone_edited_since(): void
    {
        $moved = $this->student('1A');
        $editedSince = $this->student('1A');

        $this->actingAs($this->admin())->post(route('admin.students.promote'));

        $editedSince->forceFill(['section' => '5D'])->save();

        $this->actingAs($this->admin())->post(route('admin.students.promote.undo'));

        $this->assertSame('1A', $moved->fresh()->section);
        $this->assertSame('5D', $editedSince->fresh()->section, 'a later edit outranks the undo');

        fwrite(STDERR, "  [promote] undo skips anyone edited since: YES\n");
    }

    /** Who did it, when, and exactly what moved. */
    public function test_the_run_is_recorded(): void
    {
        $student = $this->student('1A');
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.students.promote'));

        $log = AuditLog::where('action', 'students_promoted')->latest('id')->firstOrFail();

        $this->assertSame($admin->id, $log->user_id);

        $changes = json_decode($log->changes, true);
        $this->assertSame(['from' => '1A', 'to' => '2A'], $changes['moved'][$student->id]);

        fwrite(STDERR, "  [promote] recorded in audit_logs with before and after: YES\n");
    }

    /** Only an Admin. */
    public function test_a_non_admin_cannot_run_it(): void
    {
        $student = $this->student('1A');

        $this->actingAs(User::where('email', 'staff@cspc.edu.ph')->firstOrFail())
            ->post(route('admin.students.promote'))
            ->assertForbidden();

        $this->assertSame('1A', $student->fresh()->section);

        fwrite(STDERR, "  [promote] refused to a non-admin: YES\n");
    }
}
