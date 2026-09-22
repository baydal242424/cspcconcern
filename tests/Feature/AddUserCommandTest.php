<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Section;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * user:add puts a whole person on file in one line.
 *
 * The fields it writes are the ones routing reads, and three of them fail
 * silently when they are wrong: a college spelled differently from
 * COURSES_BY_COLLEGE, a programme filed under a college that does not offer
 * it, and a section in a shape Section::adviserFor() will not match. These
 * check that the command refuses those rather than writing them.
 */
class AddUserCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, UserSeeder::class]);
    }

    public function test_it_creates_a_student_with_the_details_routing_reads(): void
    {
        $this->artisan('user:add', [
            'email' => 'Juan.DelaCruz@my.cspc.edu.ph',
            '--name' => 'Juan Dela Cruz',
            '--college' => 'College of Computer Studies',
            '--program' => 'BS Information Technology',
            '--year' => '3',
            '--class' => 'a',
            '--student-id' => '231001234',
        ])->assertSuccessful();

        $user = User::where('email', 'juan.delacruz@my.cspc.edu.ph')->firstOrFail();

        $this->assertSame('Juan Dela Cruz', $user->name);
        $this->assertSame('Student', $user->role->name);
        $this->assertSame('College of Computer Studies', $user->department);
        $this->assertSame('BS Information Technology', $user->course);
        $this->assertSame('3A', $user->section);   // lower-cased class letter is normalised
        $this->assertSame('231001234', $user->student_id);

        // Dormant: it links to a Google identity on their first sign-in.
        $this->assertNull($user->google_id);

        fwrite(STDERR, "  [user:add] a student is created complete, and dormant until they sign in\n");
    }

    public function test_it_can_make_a_staff_member_the_adviser_of_a_class(): void
    {
        $this->artisan('user:add', [
            'email' => 'rosa@cspc.edu.ph',
            '--role' => 'Instructor',
            '--name' => 'Rosa Delgado',
            '--college' => 'College of Computer Studies',
            '--program' => 'BS Information Technology',
            '--year' => '2',
            '--class' => 'C',
            '--employee-id' => '2019-00456',
            '--advises' => true,
        ])->assertSuccessful();

        $rosa = User::where('email', 'rosa@cspc.edu.ph')->firstOrFail();
        $this->assertSame('Instructor', $rosa->role->name);
        $this->assertSame('2019-00456', $rosa->employee_id);

        // The row has to be in the shape adviserFor() looks up, not just present.
        $this->assertSame($rosa->id, optional(Section::adviserFor('BS Information Technology', '2C'))->id);

        fwrite(STDERR, "  [user:add] --advises records the class adviser the routing will find\n");
    }

    /** An address already on file is updated, and untouched fields stay. */
    public function test_it_updates_an_existing_account_without_wiping_what_is_there(): void
    {
        $student = User::where('email', 'student@my.cspc.edu.ph')->firstOrFail();
        $student->forceFill([
            'department' => 'College of Computer Studies',
            'course' => 'BS Information Technology',
            'section' => '1A',
            'student_id' => '231000001',
        ])->save();

        $this->artisan('user:add', [
            'email' => 'student@my.cspc.edu.ph',
            '--year' => '2',
            '--class' => 'B',
        ])->assertSuccessful();

        $student->refresh();

        $this->assertSame('2B', $student->section);
        $this->assertSame('BS Information Technology', $student->course);
        $this->assertSame('College of Computer Studies', $student->department);
        $this->assertSame('231000001', $student->student_id);
        $this->assertSame('Student', $student->role->name);

        fwrite(STDERR, "  [user:add] an existing account is updated, and omitted fields are kept\n");
    }

    public function test_it_refuses_the_mistakes_that_would_otherwise_fail_silently(): void
    {
        // Not a CSPC address: sign-in would turn it away, so the row is useless.
        $this->artisan('user:add', ['email' => 'someone@gmail.com'])->assertFailed();
        $this->assertNull(User::where('email', 'someone@gmail.com')->first());

        // A programme its college does not offer.
        $this->artisan('user:add', [
            'email' => 'wrong.college@my.cspc.edu.ph',
            '--college' => 'College of Computer Studies',
            '--program' => 'BS Nursing',
        ])->assertFailed();

        // A college nobody spells that way.
        $this->artisan('user:add', [
            'email' => 'wrong.college@my.cspc.edu.ph',
            '--college' => 'CCS',
        ])->assertFailed();

        // A year the programme does not run to.
        $this->artisan('user:add', [
            'email' => 'wrong.college@my.cspc.edu.ph',
            '--program' => 'BS Information Technology',
            '--year' => '6',
            '--class' => 'A',
        ])->assertFailed();

        // Half a section.
        $this->artisan('user:add', [
            'email' => 'wrong.college@my.cspc.edu.ph',
            '--year' => '2',
        ])->assertFailed();

        // A role that does not exist.
        $this->artisan('user:add', [
            'email' => 'wrong.college@my.cspc.edu.ph',
            '--role' => 'Supreme Leader',
        ])->assertFailed();

        $this->assertNull(User::where('email', 'wrong.college@my.cspc.edu.ph')->first());

        fwrite(STDERR, "  [user:add] wrong college, programme, year, half a section and an unknown role are all refused\n");
    }

    /** A student advises nobody; the flag is ignored rather than writing a bad row. */
    public function test_advises_is_ignored_for_a_student(): void
    {
        $this->artisan('user:add', [
            'email' => 'not.an.adviser@my.cspc.edu.ph',
            '--program' => 'BS Information Technology',
            '--year' => '4',
            '--class' => 'D',
            '--advises' => true,
        ])->assertSuccessful();

        $this->assertNull(Section::adviserFor('BS Information Technology', '4D'));

        fwrite(STDERR, "  [user:add] a student cannot be recorded as a class adviser\n");
    }

    public function test_list_prints_the_spellings_it_accepts(): void
    {
        // No address needed: it is what you run when you cannot remember one.
        $this->artisan('user:add', ['--list' => true])
            ->expectsOutputToContain('College of Computer Studies')
            ->expectsOutputToContain('Guidance Office')
            ->assertSuccessful();

        // Without --list, an address is still required rather than assumed.
        $this->artisan('user:add')->assertFailed();

        // --list only reports; it must not create anything.
        $this->assertSame(0, User::where('email', 'like', '%unused%')->count());

        fwrite(STDERR, "  [user:add] --list prints the exact colleges, programmes and offices\n");
    }
}
