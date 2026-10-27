<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Concern;
use App\Models\Notification;
use App\Models\User;
use App\Services\ConcernNotificationService;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A concern is never deleted. It is withdrawn, or it is closed.
 *
 * Deleting was always a half-truth: the model soft-deletes, so the row,
 * its evidence and its audit trail all stayed exactly where they were.
 * What the student actually got was a report they could no longer find,
 * and nothing in the interface could bring it back. "Did I ever raise
 * this?" had no answer, and neither did "what happened to the one I filed
 * in March?" -- while the confirmation promised the thing was permanently
 * removed, which was never true of it.
 *
 * Withdrawing says what is really happening and leaves the record intact.
 */
class WithdrawingInsteadOfDeletingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, UserSeeder::class]);
        Storage::fake('local');
    }

    private function student(): User
    {
        return User::where('email', 'student@my.cspc.edu.ph')->firstOrFail();
    }

    private function handler(): User
    {
        return User::where('email', 'staff@cspc.edu.ph')->firstOrFail();
    }

    private function aConcern(array $overrides = []): Concern
    {
        return Concern::create(array_merge([
            'user_id' => $this->student()->id,
            'category' => 'Facilities',
            'department' => 'College of Computer Studies',
            'description' => 'The aircon in Room 204 has not worked for two weeks.',
            'urgency' => 'Low',
            'status' => 'submitted',
            'is_anonymous' => false,
            'assigned_to' => $this->handler()->id,
        ], $overrides));
    }

    /**
     * There is no way to delete a concern, by any verb.
     *
     * The guard that matters. The button is gone from both pages, but a
     * route left behind is still a route, and DELETE /concerns/{id} was
     * reachable by anybody who knew it was there.
     */
    public function test_the_delete_route_is_gone(): void
    {
        $concern = $this->aConcern();

        // 405, not 404: the URI still exists for GET and PATCH, and DELETE
        // is simply not among its methods. Either way nothing is routed to
        // a destroy action, which is what this is checking.
        $this->actingAs($this->student())
            ->delete("/concerns/{$concern->id}")
            ->assertMethodNotAllowed();

        $this->assertNotNull(Concern::withTrashed()->find($concern->id));
        $this->assertNull($concern->fresh()->deleted_at, 'the concern was deleted');

        fwrite(STDERR, "  [withdraw] there is no delete route\n");
    }

    /** And no page offers one. */
    public function test_no_page_offers_to_delete(): void
    {
        $concern = $this->aConcern();

        foreach (['/concerns', "/concerns/{$concern->id}"] as $url) {
            $page = $this->actingAs($this->student())->get($url)->assertOk()->getContent();

            $this->assertStringNotContainsString('Delete Concern', $page, $url);
            $this->assertStringNotContainsString('permanently removes it', $page, $url);
            $this->assertStringContainsString('Withdraw', $page, $url);
        }

        fwrite(STDERR, "  [withdraw] neither page offers to delete\n");
    }

    /** Withdrawing keeps everything and says so in the status. */
    public function test_withdrawing_keeps_the_concern(): void
    {
        $concern = $this->aConcern();

        Attachment::create([
            'concern_id' => $concern->id,
            'kind' => Attachment::KIND_EVIDENCE,
            'uploaded_by' => $this->student()->id,
            'original_name' => 'photo.jpg',
            'stored_path' => 'attachments/x.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 100,
        ]);

        $this->actingAs($this->student())
            ->post(route('concerns.withdraw', $concern))
            ->assertRedirect(route('concerns.show', $concern));

        $fresh = $concern->fresh();

        $this->assertSame(Concern::STATUS_WITHDRAWN, $fresh->status);
        $this->assertNull($fresh->deleted_at, 'withdrawing deleted the row');
        $this->assertNotNull($fresh->closed_at, 'nothing recorded when it ended');

        // The evidence and the trail survive, which is the whole point.
        $this->assertCount(1, $fresh->evidence);
        $this->assertDatabaseHas('audit_logs', [
            'concern_id' => $concern->id,
            'action' => 'concern_withdrawn',
        ]);

        fwrite(STDERR, "  [withdraw] the concern, its evidence and its trail all stay\n");
    }

    /** The student can still find it afterwards. */
    public function test_a_withdrawn_concern_is_still_theirs_to_read(): void
    {
        $concern = $this->aConcern();

        $this->actingAs($this->student())->post(route('concerns.withdraw', $concern));

        $this->actingAs($this->student())
            ->get(route('concerns.show', $concern))
            ->assertOk()
            ->assertSee('Withdrawn');

        // In the finished pile, with the resolved and closed ones -- not
        // in the active list, because nobody is working on it. Reachable
        // is the point; deleting left it reachable from nowhere.
        $finished = $this->actingAs($this->student())
            ->get('/concerns?show_resolved=1')->getContent();

        $this->assertStringContainsString("/concerns/{$concern->id}", $finished,
            'the withdrawn concern cannot be found anywhere');

        $active = $this->actingAs($this->student())->get('/concerns')->getContent();

        $this->assertStringNotContainsString("/concerns/{$concern->id}", $active,
            'a withdrawn concern is still sitting in the active list');

        fwrite(STDERR, "  [withdraw] they can still find and read it afterwards\n");
    }

    /** A reason is optional, and kept when given. */
    public function test_a_reason_is_optional_but_recorded(): void
    {
        $concern = $this->aConcern();

        $this->actingAs($this->student())
            ->post(route('concerns.withdraw', $concern), ['reason' => 'Sorted it with the office directly.']);

        $this->assertDatabaseHas('audit_logs', [
            'concern_id' => $concern->id,
            'action' => 'concern_withdrawn',
            'description' => 'Withdrawn by the student: Sorted it with the office directly.',
        ]);

        // And without one it still goes through.
        $second = $this->aConcern();

        $this->actingAs($this->student())->post(route('concerns.withdraw', $second));

        $this->assertSame(Concern::STATUS_WITHDRAWN, $second->fresh()->status);

        fwrite(STDERR, "  [withdraw] a reason is optional, and kept when given\n");
    }

    /** Once staff have started, it is no longer the student's to pull. */
    public function test_a_concern_being_worked_on_cannot_be_withdrawn(): void
    {
        $concern = $this->aConcern(['status' => 'in_progress']);

        $this->actingAs($this->student())
            ->post(route('concerns.withdraw', $concern))
            ->assertSessionHas('error');

        $this->assertSame('in_progress', $concern->fresh()->status);

        fwrite(STDERR, "  [withdraw] a concern already being worked on cannot be pulled\n");
    }

    /** Nobody else can withdraw somebody's concern -- not even an Admin. */
    public function test_only_the_reporter_can_withdraw(): void
    {
        $concern = $this->aConcern();

        $others = [
            'the handler' => $this->handler(),
            'a classmate' => User::where('email', 'student2@my.cspc.edu.ph')->firstOrFail(),
            'an admin' => User::where('email', 'admin@cspc.edu.ph')->firstOrFail(),
        ];

        foreach ($others as $who => $user) {
            $this->actingAs($user)
                ->post(route('concerns.withdraw', $concern))
                ->assertForbidden();

            $this->assertSame('submitted', $concern->fresh()->status, $who.' withdrew it');
        }

        fwrite(STDERR, "  [withdraw] only the reporter can withdraw, admins included\n");
    }

    /** The desk it had landed on is told to stop. */
    public function test_the_assigned_handler_is_told(): void
    {
        $concern = $this->aConcern();

        $this->actingAs($this->student())->post(route('concerns.withdraw', $concern));

        $row = Notification::where('concern_id', $concern->id)
            ->where('type', ConcernNotificationService::TYPE_CONCERN_WITHDRAWN)
            ->first();

        $this->assertNotNull($row, 'the handler was not told');
        $this->assertSame($this->handler()->id, $row->user_id);

        fwrite(STDERR, "  [withdraw] the desk it landed on is told to stop\n");
    }

    /** It is finished: staff cannot edit it and nothing more is sent. */
    public function test_a_withdrawn_concern_is_finished(): void
    {
        $concern = $this->aConcern();

        $this->actingAs($this->student())->post(route('concerns.withdraw', $concern));

        $this->assertFalse($concern->fresh()->acceptsNotices());

        $this->actingAs($this->handler())->patch("/concerns/{$concern->id}", [
            'investigation_notes' => 'Trying to pick this up again.',
            'resolution_notes' => 'And settle it.',
            'status' => 'in_progress',
            'urgency' => 'Low',
        ])->assertSessionHas('error');

        $this->assertSame(Concern::STATUS_WITHDRAWN, $concern->fresh()->status);

        fwrite(STDERR, "  [withdraw] a withdrawn concern is finished and cannot be reopened\n");
    }

    /**
     * But the same thing can be raised again.
     *
     * Withdrawing is not meant to shut the door. A student who pulls a
     * report because they were nervous should be able to file it again.
     */
    public function test_the_same_thing_can_be_reported_again(): void
    {
        $concern = $this->aConcern();

        $this->actingAs($this->student())->post(route('concerns.withdraw', $concern));

        $this->assertTrue($concern->fresh()->canBeReportedAgainBy($this->student()));

        fwrite(STDERR, "  [withdraw] the same thing can still be reported again\n");
    }

    /** A withdrawn concern is out of the open queue, not counted as work. */
    public function test_it_leaves_the_open_queue(): void
    {
        $concern = $this->aConcern();

        $this->actingAs($this->student())->post(route('concerns.withdraw', $concern));

        $open = Concern::whereNotIn('status', Concern::TERMINAL_STATUSES)->pluck('id');

        $this->assertFalse($open->contains($concern->id), 'a withdrawn concern is still queued as work');

        fwrite(STDERR, "  [withdraw] it leaves the open queue\n");
    }
}
