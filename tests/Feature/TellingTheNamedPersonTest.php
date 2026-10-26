<?php

namespace Tests\Feature;

use App\Models\Concern;
use App\Models\Notification;
use App\Models\User;
use App\Services\ConcernNotificationService;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The person a concern names is told it is being looked into.
 *
 * Natural justice: somebody should not learn months afterwards that a
 * complaint naming them was investigated. They are told when it starts.
 *
 * What they are not told is who raised it. The subject of a concern is
 * walled out of the case entirely by the conflict-of-interest rule, and this
 * notice must not undo that in its text -- no reporter, no description, no
 * category, no class. A category alone can be enough: an academic concern
 * naming an instructor points at the one class they teach.
 */
class TellingTheNamedPersonTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, UserSeeder::class]);
        Storage::fake('local');
    }

    private function reporter(): User
    {
        return User::where('email', 'student@my.cspc.edu.ph')->firstOrFail();
    }

    private function handler(): User
    {
        return User::where('email', 'counselor@cspc.edu.ph')->firstOrFail();
    }

    private function named(): User
    {
        return User::where('email', 'staff@cspc.edu.ph')->firstOrFail();
    }

    private function aConcernNaming(User $subject): Concern
    {
        $concern = Concern::create([
            'user_id' => $this->reporter()->id,
            'category' => 'Harassment',
            'department' => 'College of Computer Studies',
            'description' => 'What happened, described at length by the student.',
            'urgency' => 'High',
            'status' => 'submitted',
            'is_anonymous' => false,
            'assigned_to' => $this->handler()->id,
        ]);

        $concern->syncSubjects([$subject->id]);

        return $concern->fresh();
    }

    private function beginInvestigation(Concern $concern): void
    {
        $this->actingAs($this->handler())->patch("/concerns/{$concern->id}", [
            'investigation_notes' => 'Looked into this and spoke with the people involved.',
            'resolution_notes' => 'Recorded what is being done about it.',
            'status' => 'in_progress',
            'urgency' => 'High',
        ])->assertRedirect();
    }

    /** They get a notice when work starts. */
    public function test_the_named_person_is_told_when_it_starts(): void
    {
        $named = $this->named();
        $concern = $this->aConcernNaming($named);

        $this->assertSame(0, Notification::where('user_id', $named->id)->count());

        $this->beginInvestigation($concern);

        $notice = Notification::where('user_id', $named->id)
            ->where('type', ConcernNotificationService::TYPE_NAMED_IN_CONCERN)
            ->first();

        $this->assertNotNull($notice, 'the person named should have been told');
        $this->assertStringContainsString('naming you', $notice->title);

        fwrite(STDERR, "  [named] the person named is told when it starts\n");
    }

    /** And the notice gives nothing away. */
    public function test_the_notice_identifies_nobody(): void
    {
        $named = $this->named();
        $concern = $this->aConcernNaming($named);

        $this->beginInvestigation($concern);

        $notice = Notification::where('user_id', $named->id)
            ->where('type', ConcernNotificationService::TYPE_NAMED_IN_CONCERN)
            ->firstOrFail();

        $text = $notice->title.' '.$notice->message;

        foreach ([
            $this->reporter()->name,
            $this->reporter()->email,
            'Harassment',
            'College of Computer Studies',
            'What happened, described at length',
        ] as $secret) {
            $this->assertStringNotContainsString($secret, $text, 'the notice gave away: '.$secret);
        }

        fwrite(STDERR, "  [named] the notice names nobody and says nothing of the case\n");
    }

    /** They still cannot open the concern, and the bell does not try. */
    public function test_the_bell_does_not_send_them_to_a_concern_they_cannot_open(): void
    {
        $named = $this->named();
        $concern = $this->aConcernNaming($named);

        $this->beginInvestigation($concern);

        $notice = Notification::where('user_id', $named->id)
            ->where('type', ConcernNotificationService::TYPE_NAMED_IN_CONCERN)
            ->firstOrFail();

        $this->flushSession();

        // The conflict-of-interest rule stands.
        $this->assertFalse(
            Concern::visibleTo($named)->whereKey($concern->id)->exists(),
            'the person a concern is about must not be able to read it'
        );

        // So the notice sends them to their own list, not to a refusal.
        $this->actingAs($named)->post("/notifications/{$notice->id}/read")
            ->assertRedirect(route('concerns.index'));

        $this->actingAs($named)->get("/concerns/{$concern->id}")->assertForbidden();

        fwrite(STDERR, "  [named] the bell sends them to their list, not to a 403\n");
    }

    /** Only once, however many times the case moves. */
    public function test_they_are_not_told_twice(): void
    {
        $named = $this->named();
        $concern = $this->aConcernNaming($named);

        $this->beginInvestigation($concern);

        // Referred away and picked up again.
        $this->actingAs($this->handler())->patch("/concerns/{$concern->id}", [
            'investigation_notes' => 'Looked into this and spoke with the people involved.',
            'resolution_notes' => 'Recorded what is being done about it.',
            'status' => 'referred',
            'referred_to' => 'Dean',
            'urgency' => 'High',
        ])->assertRedirect();

        $holder = $concern->fresh()->assignedUser;

        $this->actingAs($holder)->patch("/concerns/{$concern->id}", [
            'investigation_notes' => 'Looked into this and spoke with the people involved.',
            'resolution_notes' => 'Recorded what is being done about it.',
            'status' => 'in_progress',
            'urgency' => 'High',
        ])->assertRedirect();

        $this->assertSame(
            1,
            Notification::where('user_id', $named->id)
                ->where('type', ConcernNotificationService::TYPE_NAMED_IN_CONCERN)
                ->count(),
            'one notice per person per concern'
        );

        fwrite(STDERR, "  [named] told once, however often the case moves\n");
    }

    /** A concern naming nobody tells nobody. */
    public function test_a_concern_naming_nobody_sends_nothing(): void
    {
        $concern = Concern::create([
            'user_id' => $this->reporter()->id,
            'category' => 'Facilities',
            'department' => 'College of Computer Studies',
            'description' => 'The aircon in Room 204 has not worked for two weeks.',
            'urgency' => 'Low',
            'status' => 'submitted',
            'is_anonymous' => false,
            'assigned_to' => $this->handler()->id,
        ]);

        $this->beginInvestigation($concern);

        $this->assertSame(
            0,
            Notification::where('type', ConcernNotificationService::TYPE_NAMED_IN_CONCERN)->count()
        );

        fwrite(STDERR, "  [named] a concern naming nobody sends nothing\n");
    }
}
