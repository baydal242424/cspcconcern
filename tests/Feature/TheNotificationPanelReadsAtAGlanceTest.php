<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The bell, read the way a social app's is read.
 *
 * Ten rows of identical grey text, each ending in "16 minutes ago", is a
 * list nobody scans -- it is read line by line or not at all. The things
 * that fix that are small and all visual: a round mark that differs by
 * kind, an age short enough to sit on the same line, a split between what
 * arrived today and what did not, and a way to see only what is unread.
 */
class TheNotificationPanelReadsAtAGlanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, UserSeeder::class]);
    }

    private function student(): User
    {
        return User::where('email', 'student@my.cspc.edu.ph')->firstOrFail();
    }

    private function notify(array $overrides = []): Notification
    {
        $row = Notification::create(array_merge([
            'user_id' => $this->student()->id,
            'type' => 'status_update',
            'title' => 'Concern Status Updated',
            'message' => 'Your concern status has been updated to In progress',
            'is_read' => false,
        ], $overrides));

        if (isset($overrides['created_at'])) {
            $row->forceFill(['created_at' => $overrides['created_at']])->save();
        }

        return $row->refresh();
    }

    private function panel(): string
    {
        return $this->actingAs($this->student())->get('/concerns')
            ->assertOk()->getContent();
    }

    /** An age short enough to sit beside the text rather than under it. */
    public function test_the_age_is_written_short(): void
    {
        foreach ([
            '30 seconds' => 'now',
            '16 minutes' => '16m',
            '5 hours' => '5h',
            '2 days' => '2d',
            '3 weeks' => '3w',
        ] as $ago => $expected) {
            $row = $this->notify(['created_at' => now()->sub($ago)]);

            $this->assertSame($expected, $row->shortAge(), $ago);
        }

        fwrite(STDERR, "  [bell] the age is written short: now, 16m, 5h, 2d, 3w\n");
    }

    /** Today and before today, split, but only when there is both. */
    public function test_recent_and_older_are_split(): void
    {
        $this->notify(['created_at' => now()->subHours(2)]);
        $this->notify(['created_at' => now()->subWeek()]);

        $panel = $this->panel();

        $this->assertStringContainsString('>New</p>', $panel);
        $this->assertStringContainsString('>Earlier</p>', $panel);

        fwrite(STDERR, "  [bell] today and before today are split\n");
    }

    /**
     * One group gets no heading.
     *
     * "Earlier" over a list with nothing above it labels the whole panel
     * rather than a part of it, and says something that is not news.
     */
    public function test_one_group_is_left_unlabelled(): void
    {
        $this->notify(['created_at' => now()->subWeek()]);
        $this->notify(['created_at' => now()->subMonth()]);

        $panel = $this->panel();

        $this->assertStringNotContainsString('>Earlier</p>', $panel);
        $this->assertStringNotContainsString('>New</p>', $panel);

        fwrite(STDERR, "  [bell] a single group is left unlabelled\n");
    }

    /** All and Unread, with a count on the one that has it. */
    public function test_the_panel_offers_all_and_unread(): void
    {
        $this->notify();
        $this->notify(['is_read' => true]);

        $panel = $this->panel();

        $this->assertStringContainsString('data-filter="all"', $panel);
        $this->assertStringContainsString('data-filter="unread"', $panel);

        // Rows carry the state the filter reads, or it has nothing to go on.
        $this->assertStringContainsString('bell-row is-unread', $panel);
        $this->assertStringContainsString('bell-row is-read', $panel);

        fwrite(STDERR, "  [bell] the panel offers All and Unread\n");
    }

    /** With nothing to show, the tabs are not drawn at all. */
    public function test_an_empty_panel_offers_no_filter(): void
    {
        $panel = $this->panel();

        $this->assertStringNotContainsString('data-filter="unread"', $panel);
        $this->assertStringContainsString('No notifications yet', $panel);

        fwrite(STDERR, "  [bell] an empty panel offers no filter\n");
    }

    /**
     * The exact time is still there, in the title.
     *
     * "2w" is enough to place a notice in a week; it is not enough to
     * answer "when exactly", and that answer should not need another page.
     */
    public function test_the_exact_time_is_still_reachable(): void
    {
        $row = $this->notify(['created_at' => now()->subDays(3)]);

        $this->assertStringContainsString(
            'title="'.$row->created_at->local()->format('M j, Y g:i A').'"',
            $this->panel()
        );

        fwrite(STDERR, "  [bell] the exact time is still there, on hover\n");
    }
}
