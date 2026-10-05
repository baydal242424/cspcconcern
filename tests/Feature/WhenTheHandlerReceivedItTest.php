<?php

namespace Tests\Feature;

use App\Models\Concern;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * When the desk holding a concern received it.
 *
 * Not the same as the filing date once a case has moved: a concern filed a
 * fortnight ago may have reached its present handler an hour ago, and "how
 * long have you had this" is the question the person waiting is really
 * asking. The page showed only the filing date, so a case that had just been
 * handed over read as two weeks old on the desk that got it this morning.
 *
 * Derived from the audit log rather than stored. There is no assigned_at
 * column, and the log already records every hand-off with its time.
 */
class WhenTheHandlerReceivedItTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, UserSeeder::class]);
    }

    private function u(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    private function aConcern(): Concern
    {
        return Concern::create([
            'user_id' => $this->u('student@my.cspc.edu.ph')->id,
            'category' => 'Academic',
            'department' => 'College of Computer Studies',
            'description' => 'Something happened in class.',
            'urgency' => 'Low',
            'status' => 'submitted',
            'is_anonymous' => false,
            'assigned_to' => $this->u('staff@cspc.edu.ph')->id,
        ]);
    }

    /** Never handed on: they have had it since it was filed. */
    public function test_with_no_hand_off_it_is_the_filing_time(): void
    {
        $concern = $this->aConcern();
        $concern->forceFill(['created_at' => now()->subDays(3)])->save();

        $this->assertTrue(
            $concern->load('auditLogs')->receivedAt()->equalTo($concern->created_at),
            'with nothing in the log, the handler has had it since it was filed'
        );

        fwrite(STDERR, "  [received] with no hand-off, it is the filing time\n");
    }

    /** Handed on: the clock starts at the hand-off, not at the filing. */
    public function test_a_hand_off_resets_it(): void
    {
        $concern = $this->aConcern();
        $concern->forceFill(['created_at' => now()->subDays(3)])->save();

        $this->actingAs($this->u('staff@cspc.edu.ph'))->patch("/concerns/{$concern->id}", [
            'investigation_notes' => 'Looked into this and spoke with the people involved.',
            'resolution_notes' => 'Recorded what is being done about it.',
            'status' => 'referred',
            'referred_to' => 'Guidance Counselor',
            'urgency' => 'Low',
        ])->assertRedirect();

        $received = $concern->fresh()->load('auditLogs')->receivedAt();

        $this->assertTrue($received->isToday(), 'the new handler received it today');
        $this->assertTrue(
            $received->greaterThan($concern->created_at),
            'three days after it was filed'
        );

        fwrite(STDERR, "  [received] a hand-off resets it to the hand-off time\n");
    }

    /** The latest hand-off wins, not the first. */
    public function test_the_most_recent_hand_off_is_the_one_that_counts(): void
    {
        $concern = $this->aConcern();

        foreach (['Guidance Counselor', 'Dean'] as $to) {
            $holder = $concern->fresh()->assignedUser;

            $this->actingAs($holder)->patch("/concerns/{$concern->id}", [
                'investigation_notes' => 'Looked into this and spoke with the people involved.',
                'resolution_notes' => 'Recorded what is being done about it.',
                'status' => 'referred',
                'referred_to' => $to,
                'urgency' => 'Low',
            ])->assertRedirect();
        }

        $concern = $concern->fresh()->load('auditLogs');

        $lastHandOff = $concern->auditLogs
            ->where('action', 'status_updated')
            ->sortByDesc('id')
            ->first();

        $this->assertTrue($concern->receivedAt()->equalTo($lastHandOff->created_at));

        fwrite(STDERR, "  [received] the most recent hand-off is the one that counts\n");
    }

    /** It is on the page, for staff and for the person who filed it. */
    public function test_both_sides_are_shown_when_it_was_received(): void
    {
        $concern = $this->aConcern();

        $this->actingAs($this->u('staff@cspc.edu.ph'))
            ->get("/concerns/{$concern->id}")->assertOk()->assertSee('Received');

        $this->flushSession();

        $this->actingAs($this->u('student@my.cspc.edu.ph'))
            ->get("/concerns/{$concern->id}")->assertOk()->assertSee('Received');

        fwrite(STDERR, "  [received] shown to staff and to the reporter\n");
    }
}
