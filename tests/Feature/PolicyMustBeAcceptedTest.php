<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Nobody files a concern before being shown what the system does with it.
 *
 * The policy page existed and nothing led anyone to it. A student could file
 * without ever learning that it is read under their own name, that it reaches
 * an office rather than one person, or that the person it is about can never
 * see it -- all of which change what somebody chooses to write.
 *
 * Shown ONCE, and recorded. A notice at every sign-in is a door people push
 * through without reading, and the agreement it collects is worth nothing.
 */
class PolicyMustBeAcceptedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, UserSeeder::class]);
    }

    private function newStudent(): User
    {
        return User::create([
            'name' => 'Brand New Student',
            'email' => 'brandnew@my.cspc.edu.ph',
            'password' => Hash::make('not-used'),
            'role_id' => Role::where('name', 'Student')->firstOrFail()->id,
            'status' => 'approved',
            'student_id' => '241000777',
            'department' => 'College of Computer Studies',
            'course' => 'BS Information Technology',
            'section' => '1A',
            'google_id' => 'google-brandnew',
            'email_verified_at' => now(),
        ]);
    }

    public function test_a_new_student_is_shown_the_policy_before_anything_else(): void
    {
        $student = $this->newStudent();

        $this->assertFalse($student->hasAcceptedPolicy());

        $this->actingAsWithoutPolicy($student)->get('/concerns')->assertRedirect(route('policy'));
        $this->actingAsWithoutPolicy($student)->get('/concerns/create')->assertRedirect(route('policy'));

        // And the page carries the agreement, not just the text.
        $this->actingAsWithoutPolicy($student)->get('/policy')->assertOk()
            ->assertSee('I have read and accept this policy', false)
            ->assertSee('You will only be asked once.', false);

        fwrite(STDERR, "  [policy] a new student meets the policy before they can file\n");
    }

    /** Filing is blocked too, not just the page that leads to it. */
    public function test_they_cannot_file_by_posting_straight_past_it(): void
    {
        $student = $this->newStudent();

        $this->actingAsWithoutPolicy($student)->post('/concerns', [
            'category' => 'Academic',
            'description' => 'A concern posted without ever seeing the policy.',
        ])->assertRedirect(route('policy'));

        $this->assertSame(0, \App\Models\Concern::count());

        fwrite(STDERR, "  [policy] posting straight to the form does not get past it either\n");
    }

    public function test_accepting_it_records_who_and_when_and_opens_the_form(): void
    {
        $student = $this->newStudent();

        $this->actingAsWithoutPolicy($student)->post('/policy/accept')
            ->assertRedirect(route('concerns.create'));

        $student->refresh();

        $this->assertNotNull($student->policy_accepted_at);
        $this->assertSame(User::POLICY_VERSION, $student->policy_version);
        $this->assertTrue($student->hasAcceptedPolicy());

        // Recorded where decisions are recorded, so it can be shown later.
        $this->assertSame(
            1,
            DB::table('audit_logs')->where('user_id', $student->id)->where('action', 'policy_accepted')->count()
        );

        fwrite(STDERR, "  [policy] accepting is stamped with the date and the version, and opens the form\n");
    }

    /** Asked once. The second visit goes where it was going. */
    public function test_it_is_never_shown_again(): void
    {
        $student = $this->newStudent();

        $this->actingAsWithoutPolicy($student)->post('/policy/accept')->assertRedirect();

        $this->actingAsWithoutPolicy($student->refresh())->get('/concerns')->assertOk();
        $this->actingAsWithoutPolicy($student)->get('/concerns/create')->assertOk();

        $this->actingAsWithoutPolicy($student)->get('/policy')->assertOk()
            ->assertSee('You accepted this policy on', false)
            ->assertDontSee('I have read and accept this policy', false);

        fwrite(STDERR, "  [policy] once accepted, it never interrupts them again\n");
    }

    /**
     * A rewrite is a different promise, so it is put again.
     *
     * The September rewrite dropped an offer of anonymity the system had
     * stopped keeping; an agreement to that wording is not an agreement to
     * this one.
     */
    public function test_a_rewritten_policy_is_put_to_them_again(): void
    {
        $student = $this->newStudent();

        $this->actingAsWithoutPolicy($student)->post('/policy/accept')->assertRedirect();

        // The wording moves on.
        $student->forceFill(['policy_version' => '2025-01'])->save();

        $this->assertFalse($student->fresh()->hasAcceptedPolicy());
        $this->actingAsWithoutPolicy($student->fresh())->get('/concerns')->assertRedirect(route('policy'));

        fwrite(STDERR, "  [policy] a rewritten policy is put to everybody again\n");
    }

    /** They can still read it, and still sign out. Nobody is trapped. */
    public function test_they_are_not_trapped_on_the_page(): void
    {
        $student = $this->newStudent();

        $this->actingAsWithoutPolicy($student)->get('/policy')->assertOk();
        $this->actingAsWithoutPolicy($student)->post('/logout')->assertRedirect();

        $this->assertGuest();

        fwrite(STDERR, "  [policy] somebody who will not agree can still read it and leave\n");
    }

    /** Guests still read it without signing in -- it is how you decide to. */
    public function test_it_stays_public(): void
    {
        $this->get('/policy')->assertOk()
            ->assertSee('Data Privacy')
            ->assertSee('Sign in to Report', false);

        fwrite(STDERR, "  [policy] a guest can still read the whole thing before signing in\n");
    }
}
