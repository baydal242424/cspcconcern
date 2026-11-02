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
 * Every handling note is kept, and the student can read all of them.
 *
 * The two note columns held one value each, so every save wrote over the
 * last. A case that took several rounds -- the adviser looks, asks a
 * question, looks again, hands it to the chair -- ended up showing only
 * whoever saved most recently. For the student watching their own report
 * that is the worst version: they are told something is being worked on,
 * and each time they look, what they read last has gone.
 */
class TheHandlingRecordIsKeptInFullTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, UserSeeder::class]);
    }

    private function reporter(): User
    {
        return User::where('email', 'student@my.cspc.edu.ph')->firstOrFail();
    }

    private function handler(): User
    {
        return User::where('email', 'staff@cspc.edu.ph')->firstOrFail();
    }

    private function counsellor(): User
    {
        return User::where('email', 'counselor@cspc.edu.ph')->firstOrFail();
    }

    private function aConcern(): Concern
    {
        return Concern::create([
            'user_id' => $this->reporter()->id,
            'category' => 'Academic',
            'subcategory' => 'Grades',
            'department' => 'College of Computer Studies',
            'description' => 'A mark that does not match the work handed in.',
            'urgency' => 'Low',
            'status' => 'submitted',
            'is_anonymous' => false,
            'assigned_to' => $this->handler()->id,
        ]);
    }

    private function save(User $as, Concern $concern, string $review, string $resolution, array $extra = [])
    {
        return $this->actingAs($as)->patch("/concerns/{$concern->id}", array_merge([
            'status' => 'in_progress',
            'urgency' => 'Low',
            'investigation_notes' => $review,
            'resolution_notes' => $resolution,
        ], $extra));
    }

    public function test_a_second_round_does_not_wipe_the_first(): void
    {
        $concern = $this->aConcern();

        $this->save($this->handler(), $concern, 'First look: pulled the class record.', 'Asked for a recheck.');
        $this->save($this->handler(), $concern, 'Second look: the rubric changed mid-term.', 'Waiting on the rubric.');

        $reviews = $concern->reviewNotes()->pluck('body')->all();

        $this->assertSame([
            'First look: pulled the class record.',
            'Second look: the rubric changed mid-term.',
        ], $reviews);

        $this->assertSame([
            'Asked for a recheck.',
            'Waiting on the rubric.',
        ], $concern->resolutionNotes()->pluck('body')->all());
    }

    /** The columns still answer "what does this case say now". */
    public function test_the_columns_hold_the_latest_entry(): void
    {
        $concern = $this->aConcern();

        $this->save($this->handler(), $concern, 'First look.', 'First plan.');
        $this->save($this->handler(), $concern, 'Second look.', 'Second plan.');

        $concern->refresh();

        $this->assertSame('Second look.', $concern->investigation_notes);
        $this->assertSame('Second plan.', $concern->resolution_notes);
    }

    /** The whole point: the student reads all of it, not just the last line. */
    public function test_the_student_reads_every_entry(): void
    {
        $concern = $this->aConcern();

        $this->save($this->handler(), $concern, 'First look: pulled the class record.', 'Asked for a recheck.');
        $this->save($this->handler(), $concern, 'Second look: the rubric changed mid-term.', 'Waiting on the rubric.');

        $html = $this->actingAs($this->reporter())
            ->get("/concerns/{$concern->id}")->assertOk()->getContent();

        foreach ([
            'First look: pulled the class record.',
            'Asked for a recheck.',
            'Second look: the rubric changed mid-term.',
            'Waiting on the rubric.',
        ] as $entry) {
            $this->assertStringContainsString($entry, $html, "The student must still be able to read: {$entry}");
        }
    }

    /** Written by two different desks, both kept, in order. */
    public function test_entries_from_two_handlers_both_survive(): void
    {
        $concern = $this->aConcern();

        $this->save($this->handler(), $concern, 'The adviser looked.', 'Handing it on.');

        // Handed over. Done directly rather than through a referral, so
        // the test is about the record surviving a change of desk and not
        // about which destinations an Academic concern may be sent to.
        $concern->update(['assigned_to' => $this->counsellor()->id]);

        $this->save($this->counsellor(), $concern, 'Guidance looked too.', 'Guidance is on it.');

        $notes = $concern->reviewNotes()->with('author')->get();

        $this->assertCount(2, $notes);
        $this->assertSame('The adviser looked.', $notes[0]->body);
        $this->assertSame($this->handler()->id, $notes[0]->user_id);
        $this->assertSame('Guidance looked too.', $notes[1]->body);
        $this->assertSame($this->counsellor()->id, $notes[1]->user_id);
    }

    /**
     * Both boxes are required on every save, so a handler who only
     * changes the urgency would otherwise file the same paragraph again
     * and bury the real entries under repeats.
     */
    public function test_saving_the_same_words_again_adds_nothing(): void
    {
        $concern = $this->aConcern();

        $this->save($this->handler(), $concern, 'Only thing I found.', 'Only thing being done.');
        $this->assertSame(2, $concern->notes()->count());

        $this->save($this->handler(), $concern, 'Only thing I found.', 'Only thing being done.', [
            'urgency' => 'Medium',
        ]);

        $this->assertSame(2, $concern->notes()->count(), 'A repeat must not be filed twice.');
    }

    /** Only the changed one is added when a handler updates a single box. */
    public function test_only_the_box_that_changed_is_added(): void
    {
        $concern = $this->aConcern();

        $this->save($this->handler(), $concern, 'What I found.', 'What is being done.');
        $this->save($this->handler(), $concern, 'What I found.', 'A new plan, now that the rubric arrived.');

        $this->assertSame(1, $concern->reviewNotes()->count());
        $this->assertSame(2, $concern->resolutionNotes()->count());
    }

    /** Staff see who wrote each entry; the student sees only when. */
    public function test_the_author_is_named_to_staff_and_not_to_the_student(): void
    {
        $concern = $this->aConcern();

        $concern->addNote(ConcernNote::KIND_REVIEW, 'ENTRY ONE.', $this->handler()->id);
        $concern->addNote(ConcernNote::KIND_REVIEW, 'ENTRY TWO.', $this->counsellor()->id);
        $concern->addNote(ConcernNote::KIND_RESOLUTION, 'Being dealt with.', $this->counsellor()->id);

        $byline = function (string $html): string {
            preg_match('~ENTRY ONE\.</p>\s*<p[^>]*>(.*?)</p>~s', $html, $m);

            return trim(preg_replace('~\s+~', ' ', strip_tags($m[1] ?? '')));
        };

        $staffHtml = $this->actingAs($this->handler())
            ->get("/concerns/{$concern->id}")->assertOk()->getContent();
        $this->assertStringContainsString($this->handler()->name, $byline($staffHtml));

        $studentHtml = $this->actingAs($this->reporter())
            ->get("/concerns/{$concern->id}")->assertOk()->getContent();
        $this->assertStringNotContainsString($this->handler()->name, $byline($studentHtml));
        $this->assertStringNotContainsString($this->counsellor()->name, $byline($studentHtml));
    }

    /** A single entry reads as a plain paragraph, as it always did. */
    public function test_one_entry_shows_without_a_date_line(): void
    {
        $concern = $this->aConcern();

        $this->save($this->handler(), $concern, 'The only note on this case.', 'The only plan.');

        $html = $this->actingAs($this->reporter())
            ->get("/concerns/{$concern->id}")->assertOk()->getContent();

        $this->assertStringContainsString('The only note on this case.', $html);
        $this->assertDoesNotMatchRegularExpression(
            '~The only note on this case\.</p>\s*<p[^>]*>\s*[A-Z][a-z]{2} \d~',
            $html,
            'A lone entry needs no date line under it.'
        );
    }

    /** Nothing in the record is ever edited away by a later save. */
    public function test_entries_are_never_rewritten(): void
    {
        $concern = $this->aConcern();

        $this->save($this->handler(), $concern, 'The original finding.', 'The original plan.');
        $first = $concern->reviewNotes()->first();

        $this->save($this->handler(), $concern, 'A completely different finding.', 'A different plan.');

        $this->assertSame(
            'The original finding.',
            $first->fresh()->body,
            'An entry already written must not change.'
        );
    }
}
