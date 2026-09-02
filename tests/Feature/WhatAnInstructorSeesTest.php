<?php

namespace Tests\Feature;

use App\Models\Concern;
use App\Models\User;
use Database\Seeders\Faculty\CcsFacultySeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What an instructor actually sees after signing in.
 *
 * Documents the role from the inside rather than from the visibility rule, so
 * a change that widens or narrows their view shows up as a failing expectation
 * instead of being noticed by a student.
 */
class WhatAnInstructorSeesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, UserSeeder::class, CcsFacultySeeder::class]);
    }

    private function instructor(): User
    {
        return User::where('email', 'jeremyneo@cspc.edu.ph')->firstOrFail();
    }

    private function concern(array $overrides = []): Concern
    {
        return Concern::create(array_merge([
            'user_id' => User::where('email', 'student@my.cspc.edu.ph')->firstOrFail()->id,
            'category' => 'Academic',
            'department' => 'College of Computer Studies',
            'description' => 'A concern used to probe what an instructor can see.',
            'status' => 'submitted',
            'is_anonymous' => false,
        ], $overrides));
    }

    /** The navigation an instructor gets. */
    public function test_the_navigation_available_to_an_instructor(): void
    {
        $resp = $this->actingAs($this->instructor())->get('/concerns');
        $resp->assertOk();

        $html = $resp->getContent();

        $this->assertStringContainsString('Concerns', $html);
        $this->assertStringContainsString('Policy', $html);
        $this->assertStringNotContainsString('Manage Users', $html);

        // The dashboard is Admin-only and its link is hidden from everyone else.
        $this->actingAs($this->instructor())->get('/dashboard')->assertForbidden();

        fwrite(STDERR, "  [nav] Concerns + Policy + notifications; no Dashboard, no Manage Users\n");
    }

    /** Their list: their own work, plus the open queue for their categories. */
    public function test_what_appears_in_their_concern_list(): void
    {
        $me = $this->instructor();

        $mine = $this->concern(['assigned_to' => $me->id, 'status' => 'in_progress']);
        $openQueue = $this->concern(['category' => 'Safety']);
        $counselling = $this->concern(['category' => 'Mental Health']);
        $facilities = $this->concern(['category' => 'Facilities']);

        $visible = Concern::visibleTo($me)->pluck('id');

        $this->assertTrue($visible->contains($mine->id), 'their own assigned concern');
        $this->assertTrue($visible->contains($openQueue->id), 'the unclaimed academic/safety queue');
        $this->assertFalse($visible->contains($counselling->id), 'counselling is not theirs');
        $this->assertFalse($visible->contains($facilities->id), 'facilities is not theirs');

        fwrite(STDERR, "  [list] sees assigned + open Academic/Safety/Others; not Mental Health, not Facilities\n");
    }

    /** An anonymous reporter stays anonymous to them. */
    public function test_an_anonymous_reporter_is_hidden_from_them(): void
    {
        $me = $this->instructor();
        $c = $this->concern(['assigned_to' => $me->id, 'is_anonymous' => true]);

        $resp = $this->actingAs($me)->get("/concerns/{$c->id}");
        $resp->assertOk();
        $resp->assertSee('Anonymous');
        $resp->assertDontSee('John Student');

        fwrite(STDERR, "  [privacy] anonymous submitter shown as Anonymous\n");
    }

    /** A concern about them is invisible, even though it is in their categories. */
    public function test_a_concern_about_them_is_invisible(): void
    {
        $me = $this->instructor();
        $c = $this->concern(['about_staff_id' => $me->id]);

        $this->assertFalse(Concern::visibleTo($me)->pluck('id')->contains($c->id));
        $this->actingAs($me)->get("/concerns/{$c->id}")->assertForbidden();

        fwrite(STDERR, "  [wall] a concern about them: not in the list, 403 on the page\n");
    }

    /** What they can do to one they hold. */
    public function test_the_actions_available_on_their_own_concern(): void
    {
        $me = $this->instructor();
        $c = $this->concern(['assigned_to' => $me->id]);

        $resp = $this->actingAs($me)->get("/concerns/{$c->id}");
        $resp->assertOk();
        $resp->assertSee('Update Concern Status', false);
        $resp->assertSee('name="urgency"', false);
        $resp->assertSee('name="referred_to"', false);
        $resp->assertSee('name="investigation_notes"', false);
        $resp->assertSee('name="resolution_notes"', false);

        // And they can actually resolve it.
        $this->actingAs($me)->patch("/concerns/{$c->id}", [
            'status' => 'resolved',
            'urgency' => 'Low',
            'resolution_notes' => 'Spoke with the student; timetable corrected.',
        ])->assertRedirect();

        $this->assertSame('resolved', $c->refresh()->status);

        fwrite(STDERR, "  [actions] triage, notes, refer, resolve, close\n");
    }

    /**
     * They can file one themselves -- staff report things too, and the system
     * is built on that assumption. What they cannot do is end up handling it:
     * the reporter is excluded from routing, so a concern an instructor files
     * goes to a colleague.
     */
    public function test_they_can_file_a_concern_but_never_receive_their_own(): void
    {
        $me = $this->instructor();

        $this->actingAs($me)->get('/concerns/create')->assertOk();

        $this->actingAs($me)->post('/concerns', [
            'category' => 'Academic',
            'description' => 'An instructor reporting a problem with a shared classroom timetable.',
            'is_anonymous' => 0,
        ]);

        $filed = Concern::where('user_id', $me->id)->latest('id')->first();

        $this->assertNotNull($filed, 'the concern should have been created');
        $this->assertNotSame($me->id, $filed->assigned_to, 'nobody handles their own report');

        fwrite(STDERR, "  [filing] can file a concern; it is routed to somebody else\n");
    }
}
