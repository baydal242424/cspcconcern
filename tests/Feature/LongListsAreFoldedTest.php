<?php

namespace Tests\Feature;

use App\Models\Concern;
use App\Models\ConcernNote;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Lists that grow without limit, and the page underneath them.
 *
 * A case worked over several weeks collects a dozen handling notes and as
 * many timeline entries. At full size that is two columns of short entries
 * pushing the action form off the bottom of the page, and a handler
 * scrolling past their own notes from last month to reach the box they came
 * to type in.
 *
 * Five showing, the rest behind a button, and the whole list set more
 * tightly once it is that long. Nothing is withheld: the hidden entries are
 * in the page, so Ctrl+F finds them and opening them needs no second trip.
 */
class LongListsAreFoldedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, UserSeeder::class]);
    }

    private function handler(): User
    {
        return User::where('email', 'staff@cspc.edu.ph')->firstOrFail();
    }

    private function aConcernWith(int $reviewNotes): Concern
    {
        $concern = Concern::create([
            'user_id' => User::where('email', 'student@my.cspc.edu.ph')->firstOrFail()->id,
            'category' => 'Personal',
            'department' => 'College of Computer Studies',
            'description' => 'Something happened that I would like looked into.',
            'urgency' => 'Low',
            'status' => 'in_progress',
            'is_anonymous' => false,
            'assigned_to' => $this->handler()->id,
        ]);

        for ($i = 1; $i <= $reviewNotes; $i++) {
            $concern->addNote(ConcernNote::KIND_REVIEW, "Review note number {$i}.", $this->handler()->id);
        }

        return $concern->fresh();
    }

    private function page(Concern $concern): string
    {
        return $this->actingAs($this->handler())
            ->get("/concerns/{$concern->id}")->assertOk()->getContent();
    }

    /** Six notes: five showing, one folded, and a button to open it. */
    public function test_a_long_notes_list_folds(): void
    {
        $html = $this->page($this->aConcernWith(6));

        // All six are in the page -- hidden, not left out.
        foreach (range(1, 6) as $i) {
            $this->assertStringContainsString("Review note number {$i}.", $html);
        }

        // The sixth is the one folded away.
        $this->assertSame(
            1,
            preg_match_all('~fold-entry fold-rest~', $html),
            'exactly one note past the fifth should be folded'
        );

        // With a button pointing at that list by name.
        $this->assertStringContainsString('data-fold-toggle="notes-review"', $html);

        fwrite(STDERR, "  [fold] six notes fold to five with a button\n");
    }

    /** Four notes: nothing folded, and no button that would do nothing. */
    public function test_a_short_notes_list_is_left_alone(): void
    {
        $html = $this->page($this->aConcernWith(4));

        $this->assertStringNotContainsString('fold-entry fold-rest', $html);
        $this->assertStringNotContainsString('data-fold-toggle="notes-review"', $html);

        fwrite(STDERR, "  [fold] four notes are left alone\n");
    }

    /**
     * The count the script reads is the real one.
     *
     * It decides both the fold and the tighter type from data-total, so a
     * wrong number there is a list that stays large or shrinks for no
     * reason -- and nothing else on the page would look wrong.
     */
    public function test_the_list_declares_how_many_it_has(): void
    {
        $html = $this->page($this->aConcernWith(7));

        $this->assertMatchesRegularExpression(
            '~id="notes-review" class="foldable"\s+data-total="7"~',
            $html
        );

        fwrite(STDERR, "  [fold] the list declares its real count\n");
    }

    /**
     * The two notes lists are separate lists.
     *
     * They render from one loop, so an id written once would be the same id
     * twice -- and one button would open the other list, or neither.
     */
    public function test_the_two_notes_lists_do_not_share_an_id(): void
    {
        $concern = $this->aConcernWith(6);

        foreach (range(1, 6) as $i) {
            $concern->addNote(ConcernNote::KIND_RESOLUTION, "Resolution note {$i}.", $this->handler()->id);
        }

        $html = $this->page($concern->fresh());

        $this->assertSame(1, substr_count($html, 'id="notes-review"'));
        $this->assertSame(1, substr_count($html, 'id="notes-resolution"'));
        $this->assertStringContainsString('data-fold-toggle="notes-resolution"', $html);

        fwrite(STDERR, "  [fold] the two notes lists are separate\n");
    }

    /** The timeline uses the same mechanism, counted in steps. */
    public function test_the_timeline_folds_the_same_way(): void
    {
        $concern = $this->aConcernWith(1);

        // Enough activity to pass five entries.
        foreach (range(1, 8) as $i) {
            \App\Models\AuditLog::create([
                'user_id' => $this->handler()->id,
                'concern_id' => $concern->id,
                'action' => 'status_updated',
                'description' => "Status changed from submitted to in_progress",
            ]);
        }

        $html = $this->page($concern->fresh());

        $this->assertStringContainsString('id="timeline-list" class="foldable"', $html);
        $this->assertStringContainsString('data-noun="steps"', $html);
        $this->assertStringContainsString('data-fold-toggle="timeline-list"', $html);

        fwrite(STDERR, "  [fold] the timeline folds the same way\n");
    }

    /**
     * The student's copy folds too.
     *
     * Their timeline is shorter than staff's, but a case that moved eight
     * times still gives them eight lines, and theirs is the page most
     * likely to be read on a phone.
     */
    public function test_the_reporter_gets_the_same_treatment(): void
    {
        $concern = $this->aConcernWith(1);

        foreach (range(1, 8) as $i) {
            \App\Models\AuditLog::create([
                'user_id' => $this->handler()->id,
                'concern_id' => $concern->id,
                'action' => 'status_updated',
                'description' => "Referred to Guidance Counselor (Dr. Maria Reyes, Guidance Office) {$i}",
            ]);
        }

        $this->flushSession();

        $html = $this->actingAs(User::where('email', 'student@my.cspc.edu.ph')->firstOrFail())
            ->get("/concerns/{$concern->id}")->assertOk()->getContent();

        $this->assertStringContainsString('id="timeline-list" class="foldable"', $html);

        fwrite(STDERR, "  [fold] the reporter's timeline folds as well\n");
    }
}
