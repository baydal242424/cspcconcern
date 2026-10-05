<?php

namespace Tests\Feature;

use App\Models\Concern;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Deleting an account is a mistake worth being able to take back.
 *
 * It used to be a real DELETE. The foreign key on concerns.user_id cascaded,
 * and every concern that person had ever filed left the database with their
 * row; the files they had uploaded were then swept off disk. All of it
 * correct for a deliberate erasure, and all of it reached by one click on the
 * wrong row in a list of several hundred near-identical names.
 *
 * Deleting now retires the account instead. It cannot sign in, it is gone
 * from every list, and the concerns it filed drop out of every queue -- but
 * nothing is destroyed, and the person signing in again finds all of it
 * waiting. Erasing for good is still available, as a separate decision made
 * about an account that is already deleted.
 *
 * This file replaces DeletedAccountDoesNotComeBackTest, which pinned the
 * behaviour above as intended.
 */
class AnAccidentalDeletionCanBeUndoneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, UserSeeder::class]);
        Storage::fake('local');
    }

    private function admin(): User
    {
        return User::where('email', 'admin@cspc.edu.ph')->firstOrFail();
    }

    /**
     * @return array{0: User, 1: Concern}
     */
    private function aStudentWithAConcern(): array
    {
        $student = User::create([
            'name' => 'Juan Dela Cruz',
            'email' => 'juan.delacruz@my.cspc.edu.ph',
            'password' => Hash::make('not-used'),
            'role_id' => Role::where('name', 'Student')->firstOrFail()->id,
            'status' => 'approved',
            'student_id' => '231009999',
            'department' => 'College of Computer Studies',
            'course' => 'BS Information Technology',
            'section' => '3A',
            'google_id' => 'google-juan',
        ]);

        $concern = Concern::create([
            'user_id' => $student->id,
            'category' => 'Academic',
            'department' => 'College of Computer Studies',
            'course' => 'BS Information Technology',
            'section' => '3A',
            'description' => 'Something I reported before my account was deleted.',
            'status' => 'submitted',
            'is_anonymous' => false,
        ]);

        return [$student, $concern];
    }

    /** The account stops working; what it filed is kept. */
    public function test_deleting_retires_the_account_without_destroying_anything(): void
    {
        [$student, $concern] = $this->aStudentWithAConcern();

        $this->actingAs($this->admin())->delete("/admin/users/{$student->id}")->assertRedirect();

        // Gone from every ordinary query...
        $this->assertNull(User::find($student->id), 'the account is out of use');

        // ...and still there underneath, with its concern attached.
        $this->assertNotNull(User::withTrashed()->find($student->id), 'the row is kept');
        $this->assertNotNull(Concern::find($concern->id), 'the concern is kept');
        $this->assertSame($student->id, Concern::find($concern->id)->user_id);

        fwrite(STDERR, "  [undo] deleting keeps the account and its concerns\n");
    }

    /** While deleted, their concerns are nobody's to work on. */
    public function test_a_deleted_reporters_concerns_leave_every_queue(): void
    {
        [$student, $concern] = $this->aStudentWithAConcern();

        $head = User::whereHas('role', fn ($q) => $q->where('name', 'Head of School'))->firstOrFail();

        $this->assertTrue(
            Concern::visibleTo($head)->where('id', $concern->id)->exists(),
            'visible to begin with'
        );

        $this->actingAs($this->admin())->delete("/admin/users/{$student->id}")->assertRedirect();

        $this->assertFalse(
            Concern::visibleTo($head)->where('id', $concern->id)->exists(),
            'a deleted reporter takes their concerns out of sight, even from the Head of School'
        );

        fwrite(STDERR, "  [undo] a deleted reporter's concerns leave every queue\n");
    }

    /**
     * The question this file exists to answer: same person, same address,
     * same student number -- does their history come back?
     */
    public function test_the_admin_can_put_the_account_back_with_everything_on_it(): void
    {
        [$student, $concern] = $this->aStudentWithAConcern();

        $this->actingAs($this->admin())->delete("/admin/users/{$student->id}")->assertRedirect();
        $this->actingAs($this->admin())->post("/admin/users/{$student->id}/restore")->assertRedirect();

        $restored = User::find($student->id);

        $this->assertNotNull($restored, 'the account is back');
        $this->assertSame('231009999', $restored->student_id, 'same person, same student number');

        // Same row, so nothing had to be reattached.
        $this->assertSame($student->id, Concern::find($concern->id)->user_id);

        // And it is back in the queues it left.
        $head = User::whereHas('role', fn ($q) => $q->where('name', 'Head of School'))->firstOrFail();

        $this->assertTrue(Concern::visibleTo($head)->where('id', $concern->id)->exists());

        // Their own list shows it again, rather than the empty state they
        // would have been shown as a brand new account.
        $this->actingAs($restored)->get('/concerns')->assertOk()
            ->assertDontSee("You haven't submitted any concerns yet", false);

        $this->assertTrue(Concern::visibleTo($restored)->where('id', $concern->id)->exists());

        fwrite(STDERR, "  [undo] restoring brings the account and its history back\n");
    }

    /** Pretend Google authenticated this address and hand it to the callback. */
    private function signInWithGoogle(string $email, string $name = 'Juan Dela Cruz')
    {
        $socialite = new \Laravel\Socialite\Two\User();
        $socialite->map([
            'id' => 'google-juan',
            'name' => $name,
            'email' => $email,
        ]);

        $provider = \Mockery::mock('Laravel\Socialite\Contracts\Provider');
        $provider->shouldReceive('user')->andReturn($socialite);
        \Laravel\Socialite\Facades\Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        return $this->get('/auth/google/callback');
    }

    /**
     * The whole journey: deleted, signs up again, same ID, history back.
     *
     * This is the rule the feature settled on, after two wrong answers.
     * Signing in once silently reinstated the old account, which made
     * deletion meaningless -- the deleted party could undo it. Then it was
     * refused outright, which locked the person out for good, because the
     * deleted row still held the only CSPC address they have.
     *
     * Deletion is real and nothing reinstates the account. The person is not
     * deleted, so they sign in and get a NEW one, with a blank profile to
     * fill in. Their own ID number is what reunites them with what they
     * filed.
     */
    public function test_a_deleted_person_signs_up_again_and_their_id_brings_the_history_back(): void
    {
        [$old, $concern] = $this->aStudentWithAConcern();

        $this->actingAs($this->admin())->delete("/admin/users/{$old->id}")->assertRedirect();

        \Illuminate\Support\Facades\Auth::logout();
        $this->flushSession();

        // Same person, same address, same Google account.
        $this->signInWithGoogle('juan.delacruz@my.cspc.edu.ph');

        $returning = User::where('email', 'juan.delacruz@my.cspc.edu.ph')->first();

        $this->assertNotNull($returning, 'they can sign up again on their own address');
        $this->assertNotSame($old->id, $returning->id, 'a new account, not the old one back');

        // The old account is still deleted, and has parked its address so
        // this sign-up could have it.
        $deleted = User::withTrashed()->find($old->id);
        $this->assertNotNull($deleted->deleted_at, 'the deletion still stands');
        $this->assertStringStartsWith('deleted-', $deleted->email);

        // A blank profile, exactly as a newcomer gets.
        $this->assertNull($returning->student_id);

        // They fill it in with their own student number.
        $this->actingAsWithoutPolicy($returning)->post('/complete-profile', [
            'student_id' => '231009999',
            'department' => 'College of Computer Studies',
            'course' => 'BS Information Systems',
            'year' => '4',
            'section_letter' => 'A',
        ])->assertRedirect();

        // And everything they filed before is theirs again.
        $this->assertSame($returning->id, Concern::find($concern->id)->user_id);
        $this->assertTrue(Concern::visibleTo($returning->fresh())->where('id', $concern->id)->exists());
        $this->assertStringContainsString('you filed before', (string) session('success'));

        fwrite(STDERR, "  [undo] deleted, signed up again, same ID -- the history comes back\n");
    }

    /**
     * Restoring must not steal an address somebody is already using.
     *
     * A deleted account parks its email so the person can sign up again on
     * it. If nobody has, restoring takes the address back -- otherwise the
     * account returns unable to sign in. If somebody has, the newer account
     * keeps it and the administrator is told.
     */
    public function test_restoring_does_not_take_back_an_address_in_use(): void
    {
        [$student] = $this->aStudentWithAConcern();

        $this->actingAs($this->admin())->delete("/admin/users/{$student->id}")->assertRedirect();

        \Illuminate\Support\Facades\Auth::logout();
        $this->flushSession();
        $this->signInWithGoogle('juan.delacruz@my.cspc.edu.ph');

        $replacement = User::where('email', 'juan.delacruz@my.cspc.edu.ph')->firstOrFail();

        $this->actingAs($this->admin())
            ->post("/admin/users/{$student->id}/restore")->assertRedirect();

        $this->assertStringStartsWith('deleted-', User::find($student->id)->email);
        $this->assertSame(
            'juan.delacruz@my.cspc.edu.ph',
            $replacement->fresh()->email,
            'the live account keeps the address'
        );
        $this->assertStringContainsString('signed up again', (string) session('success'));

        fwrite(STDERR, "  [undo] restoring does not steal an address in use\n");
    }

    /** With nobody on it, restoring gives the address back. */
    public function test_restoring_returns_a_parked_address_nobody_took(): void
    {
        [$student] = $this->aStudentWithAConcern();

        $this->actingAs($this->admin())->delete("/admin/users/{$student->id}")->assertRedirect();

        // Nothing parks the address until somebody signs in, so park it by
        // hand to stand for the case where they did and then left.
        $deleted = User::withTrashed()->find($student->id);
        $deleted->forceFill(['email' => 'deleted-'.$student->id.'-juan.delacruz@my.cspc.edu.ph'])
            ->saveQuietly();

        $this->actingAs($this->admin())
            ->post("/admin/users/{$student->id}/restore")->assertRedirect();

        $this->assertSame('juan.delacruz@my.cspc.edu.ph', User::find($student->id)->email);

        fwrite(STDERR, "  [undo] restoring gives back an address nobody took\n");
    }

    /**
     * The demo dropdown obeys the same rule.
     *
     * It is the quickest way to notice a deletion that does not hold: if a
     * deleted account could be signed into from here, deleting one would
     * look broken in exactly the place it gets tested.
     */
    public function test_the_demo_dropdown_cannot_sign_into_a_deleted_account(): void
    {
        config(['auth.demo_login' => true]);

        [$student] = $this->aStudentWithAConcern();

        // Demo sign-in is only ever offered for accounts nobody has signed
        // into with Google.
        $student->forceFill(['google_id' => null])->save();

        $this->actingAs($this->admin())->delete("/admin/users/{$student->id}")->assertRedirect();

        // Signed out properly: /login redirects anyone already carrying a
        // session, and actingAs leaves one behind that flushSession does not
        // clear on its own.
        \Illuminate\Support\Facades\Auth::logout();
        $this->flushSession();

        // Off the list entirely.
        $this->get('/login')->assertOk()
            ->assertDontSee('value="'.$student->id.'"', false);

        // And refused if the id is posted anyway.
        $this->post('/auth/demo', ['user_id' => $student->id])->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertNull(User::find($student->id), 'still deleted');

        fwrite(STDERR, "  [undo] the demo dropdown cannot sign into a deleted account\n");
    }

    /**
     * Registering again on a new address, with the same student number.
     *
     * The address is the ordinary way back, and it needs none of this. This
     * is the other way: a fresh Google account, a blank profile form, and the
     * same student number typed into it -- which is what "register again with
     * the same id" means, and what broke twice over.
     *
     * First the validator refused it: Rule::unique counts rows, not accounts
     * in use, so the deleted row went on owning the number and the student
     * was told it had already been taken -- by an account they could not see
     * and nobody could release. Then, with that fixed, the UNIQUE index on
     * the column refused the write itself.
     *
     * A student number belongs to one person, so the deleted account holding
     * it is them. It gives the number up, and what it filed moves across.
     */
    public function test_the_same_student_number_on_a_new_account_brings_the_history_across(): void
    {
        [$old, $concern] = $this->aStudentWithAConcern();

        $this->actingAs($this->admin())->delete("/admin/users/{$old->id}")->assertRedirect();
        \Illuminate\Support\Facades\Auth::logout();
        $this->flushSession();

        $returning = User::create([
            'name' => 'Juan Dela Cruz',
            'email' => 'juan.delacruz.new@my.cspc.edu.ph',
            'password' => Hash::make('not-used'),
            'role_id' => Role::where('name', 'Student')->firstOrFail()->id,
            'status' => 'approved',
            'google_id' => 'google-juan-new',
        ]);

        $this->actingAsWithoutPolicy($returning)->post('/complete-profile', [
            'student_id' => '231009999',
            'department' => 'College of Computer Studies',
            'course' => 'BS Information Systems',
            'year' => '4',
            'section_letter' => 'A',
        ])->assertRedirect();

        $returning->refresh();

        $this->assertSame('231009999', $returning->student_id, 'the number was not refused');

        // What they filed before is on the account they are signed in to.
        $this->assertSame($returning->id, Concern::find($concern->id)->user_id);
        $this->assertTrue(Concern::visibleTo($returning)->where('id', $concern->id)->exists());

        // The husk has let the number go, so it cannot block anybody again.
        $this->assertNull(User::withTrashed()->find($old->id)->student_id);

        // And they are told, rather than left to wonder where it came from.
        $this->assertStringContainsString('you filed before', (string) session('success'));

        fwrite(STDERR, "  [undo] the same student number brings the old concerns across\n");
    }

    /** Work they were holding does not go with them. */
    public function test_cases_the_deleted_account_was_handling_go_back_to_the_queue(): void
    {
        [$student, $concern] = $this->aStudentWithAConcern();
        $staff = User::where('email', 'staff@cspc.edu.ph')->firstOrFail();

        $concern->forceFill(['assigned_to' => $staff->id, 'status' => 'in_progress'])->save();

        $this->actingAs($this->admin())->delete("/admin/users/{$staff->id}")->assertRedirect();

        $this->assertNull(
            $concern->refresh()->assigned_to,
            'a case cannot stay with an account that can no longer sign in'
        );

        fwrite(STDERR, "  [undo] cases held by a deleted account are released\n");
    }

    /**
     * Erasing is the one that destroys, and it is deliberate.
     *
     * The concern rows cascade away, and the FILES go with them. Evidence
     * used to outlive the concern, the account and the audit trail --
     * thirteen such files had collected on disk before it was noticed.
     */
    public function test_erasing_a_deleted_account_destroys_it_and_its_evidence(): void
    {
        [$student, $concern] = $this->aStudentWithAConcern();

        Storage::disk('local')->put('attachments/their-evidence.pdf', 'a scan they uploaded');

        DB::table('attachments')->insert([
            'concern_id' => $concern->id,
            'uploaded_by' => $student->id,
            'original_name' => 'evidence.pdf',
            'stored_path' => 'attachments/their-evidence.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 20,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Deleting alone leaves the evidence where it is: the account may
        // still come back for it.
        $this->actingAs($this->admin())->delete("/admin/users/{$student->id}")->assertRedirect();

        Storage::disk('local')->assertExists('attachments/their-evidence.pdf');
        $this->assertSame(1, DB::table('attachments')->count());

        // Erasing is the decision that destroys.
        $this->actingAs($this->admin())->delete("/admin/users/{$student->id}/erase")->assertRedirect();

        $this->assertNull(User::withTrashed()->find($student->id), 'the row is gone for good');
        $this->assertNull(Concern::withTrashed()->find($concern->id), 'the concern cascaded');
        $this->assertSame(0, DB::table('attachments')->count(), 'the attachment row goes');

        Storage::disk('local')->assertMissing('attachments/their-evidence.pdf');

        // The admin is told, so "erased" is not taken on trust.
        $this->assertStringContainsString('1 uploaded file removed', session('success'));

        fwrite(STDERR, "  [undo] erasing destroys the account, its concerns and its evidence\n");
    }

    /** Erasing is only ever reached through a deletion. */
    public function test_an_account_in_use_cannot_be_erased_outright(): void
    {
        [$student] = $this->aStudentWithAConcern();

        $this->actingAs($this->admin())
            ->delete("/admin/users/{$student->id}/erase")
            ->assertStatus(422);

        $this->assertNotNull(User::find($student->id), 'still here, and still in use');

        fwrite(STDERR, "  [undo] an account in use cannot be erased outright\n");
    }
}
