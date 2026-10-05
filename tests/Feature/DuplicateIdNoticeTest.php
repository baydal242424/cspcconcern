<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * When two accounts reach for one student number, the administrators hear.
 *
 * The second account is refused either way -- a student number identifies one
 * student. But the student cannot fix it: either they mistyped, or somebody
 * else did, and only an administrator can see both accounts and say which.
 * Left to the error message alone, the student is stuck at a form with no way
 * forward and nobody knows.
 */
class DuplicateIdNoticeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, UserSeeder::class]);
    }

    private function newStudent(string $email = 'arriving@my.cspc.edu.ph'): User
    {
        return User::create([
            'name' => 'Arriving Student',
            'email' => $email,
            'password' => Hash::make('not-used'),
            'role_id' => Role::where('name', 'Student')->firstOrFail()->id,
            'status' => 'approved',
            'google_id' => 'google-'.md5($email),
            'email_verified_at' => now(),
            'policy_accepted_at' => now(),
            'policy_version' => User::POLICY_VERSION,
        ]);
    }

    private function existingStudent(string $number): User
    {
        $student = User::where('email', 'student@my.cspc.edu.ph')->firstOrFail();

        $student->forceFill([
            'student_id' => $number,
            'department' => 'College of Computer Studies',
            'course' => 'BS Information Technology',
            'section' => '3A',
        ])->save();

        return $student->refresh();
    }

    private function completeProfileWith(User $user, string $number)
    {
        return $this->actingAsWithoutPolicy($user)->post('/complete-profile', [
            'student_id' => $number,
            'department' => 'College of Computer Studies',
            'course' => 'BS Information Technology',
            'year' => 1, 'section_letter' => 'A',
        ]);
    }

    public function test_the_administrators_are_told_and_the_student_is_refused(): void
    {
        $held = $this->existingStudent('231002370');
        $arriving = $this->newStudent();

        $this->completeProfileWith($arriving, '231002370')
            ->assertSessionHasErrors('student_id');

        $this->assertNull($arriving->fresh()->student_id, 'the number must not have been taken');

        $notices = Notification::where('type', 'id_number_clash')->get();

        $this->assertGreaterThan(0, $notices->count(), 'the administrators should have been told');

        // Both names, so an administrator can act without hunting.
        $message = $notices->first()->message;
        $this->assertStringContainsString('Arriving Student', $message);
        $this->assertStringContainsString($held->name, $message);
        $this->assertStringContainsString('231002370', $message);

        // It reaches every administrator, not just one who may be away.
        $admins = User::whereHas('role', fn ($q) => $q->whereIn('name', ['System Admin', 'Staff Admin']))
            ->where('status', 'approved')->pluck('id');

        $this->assertEqualsCanonicalizing($admins->all(), $notices->pluck('user_id')->unique()->values()->all());

        fwrite(STDERR, "  [clash] the administrators are told, with both names, and the number is not taken\n");
    }

    /** Pressing submit five times must not fill five bells. */
    public function test_the_same_clash_is_raised_once(): void
    {
        $this->existingStudent('231002370');
        $arriving = $this->newStudent();

        foreach (range(1, 3) as $attempt) {
            $this->completeProfileWith($arriving, '231002370')->assertSessionHasErrors('student_id');
        }

        $adminCount = User::whereHas('role', fn ($q) => $q->whereIn('name', ['System Admin', 'Staff Admin']))
            ->where('status', 'approved')->count();

        $this->assertSame(
            $adminCount,
            Notification::where('type', 'id_number_clash')->count(),
            'three attempts should leave one notice per administrator, not three'
        );

        fwrite(STDERR, "  [clash] three attempts leave one notice each, not three\n");
    }

    /** Once an administrator has read it, a later clash is raised again. */
    public function test_a_fresh_clash_is_raised_after_the_first_is_read(): void
    {
        $this->existingStudent('231002370');
        $arriving = $this->newStudent();

        $this->completeProfileWith($arriving, '231002370')->assertSessionHasErrors('student_id');

        Notification::where('type', 'id_number_clash')->update(['is_read' => true, 'read_at' => now()]);

        $this->completeProfileWith($arriving, '231002370')->assertSessionHasErrors('student_id');

        $this->assertGreaterThan(
            0,
            Notification::where('type', 'id_number_clash')->where('is_read', false)->count(),
            'a clash that is still happening must be raised again once the first was dealt with'
        );

        fwrite(STDERR, "  [clash] a clash still happening is raised again once the first notice is read\n");
    }

    /** A number nobody else holds raises nothing. */
    public function test_a_free_number_raises_nothing(): void
    {
        $this->existingStudent('231002370');
        $arriving = $this->newStudent();

        $this->completeProfileWith($arriving, '231009999')->assertSessionHasNoErrors();

        $this->assertSame('231009999', $arriving->fresh()->student_id);
        $this->assertSame(0, Notification::where('type', 'id_number_clash')->count());

        fwrite(STDERR, "  [clash] a free number is accepted quietly, as it should be\n");
    }
}
