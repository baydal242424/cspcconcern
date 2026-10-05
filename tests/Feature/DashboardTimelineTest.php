<?php

namespace Tests\Feature;

use App\Models\Concern;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The dashboard's Gantt view of the last fortnight.
 *
 * The tiles above it say how many concerns there are and what kind. None of
 * them can say how LONG, which is the question an administrator actually has:
 * a bar reaching today from the far left of the chart is a case nobody has
 * closed in two weeks, and it looks like one without being counted.
 */
class DashboardTimelineTest extends TestCase
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

    private function aConcern(array $overrides = []): Concern
    {
        return Concern::create(array_merge([
            'user_id' => User::where('email', 'student@my.cspc.edu.ph')->firstOrFail()->id,
            'category' => 'Academic',
            'department' => 'College of Computer Studies',
            'description' => 'Something happened in class.',
            'urgency' => 'Low',
            'status' => 'submitted',
            'is_anonymous' => false,
        ], $overrides));
    }

    /** Fourteen columns, ending today. */
    public function test_the_chart_covers_a_fortnight_ending_today(): void
    {
        $this->aConcern();

        $days = $this->actingAs($this->admin())->get('/dashboard')->assertOk()
            ->viewData('timelineDays');

        $this->assertCount(14, $days);
        $this->assertTrue(end($days)->isToday(), 'the last column is today');
        $this->assertSame(13, (int) $days[0]->diffInDays($days[13]));

        fwrite(STDERR, "  [timeline] fourteen columns, ending today\n");
    }

    /**
     * An open case runs to today, however long ago it was filed.
     *
     * This is the whole point of the panel: the length of the bar IS the
     * backlog.
     */
    public function test_an_open_concern_runs_from_its_day_to_today(): void
    {
        $concern = $this->aConcern();
        $concern->forceFill(['created_at' => now()->subDays(5)])->save();

        $rows = $this->actingAs($this->admin())->get('/dashboard')->assertOk()
            ->viewData('timelineRows');

        $row = $rows->firstWhere('concern.id', $concern->id);

        $this->assertNotNull($row, 'the concern should be on the chart');
        $this->assertSame(9, $row['column'], 'five days ago is the ninth of fourteen columns');
        $this->assertSame(6, $row['span'], 'an open case reaches today');
        $this->assertTrue($row['open']);

        fwrite(STDERR, "  [timeline] an open concern runs to today: column {$row['column']}, span {$row['span']}\n");
    }

    /** A settled one stops on the day it was settled. */
    public function test_a_resolved_concern_stops_when_it_was_resolved(): void
    {
        $concern = $this->aConcern(['status' => 'resolved']);
        $concern->forceFill([
            'created_at' => now()->subDays(5),
            'resolved_at' => now()->subDays(3),
        ])->save();

        $rows = $this->actingAs($this->admin())->get('/dashboard')->assertOk()
            ->viewData('timelineRows');

        $row = $rows->firstWhere('concern.id', $concern->id);

        $this->assertSame(9, $row['column']);
        $this->assertSame(3, $row['span'], 'five days ago to three days ago is three columns');
        $this->assertFalse($row['open']);

        fwrite(STDERR, "  [timeline] a resolved concern stops at its resolution\n");
    }

    /**
     * Institution-wide, like the rest of the page.
     *
     * An administrator's own standing window is the Administrative category
     * alone. Scoping this panel to what they may personally open would leave
     * it permanently empty -- which is exactly what Recent Concerns below it
     * does.
     */
    public function test_it_shows_the_whole_queue_not_only_what_the_admin_may_open(): void
    {
        $concern = $this->aConcern(['category' => 'Academic']);

        $admin = $this->admin();

        $this->assertFalse(
            Concern::visibleTo($admin)->where('id', $concern->id)->exists(),
            'this is a concern the admin cannot open, which is the point'
        );

        $rows = $this->actingAs($admin)->get('/dashboard')->assertOk()
            ->viewData('timelineRows');

        $row = $rows->firstWhere('concern.id', $concern->id);

        $this->assertNotNull($row, 'it still belongs on the chart');
        $this->assertFalse($row['canOpen'], 'but it is not offered as a link');

        fwrite(STDERR, "  [timeline] the whole queue is charted; unopenable rows are not links\n");
    }

    /** Still admin-only, like everything else here. */
    public function test_a_student_cannot_reach_it(): void
    {
        $this->aConcern();

        $this->actingAs(User::where('email', 'student@my.cspc.edu.ph')->firstOrFail())
            ->get('/dashboard')->assertForbidden();

        fwrite(STDERR, "  [timeline] the dashboard stays admin-only\n");
    }
}
