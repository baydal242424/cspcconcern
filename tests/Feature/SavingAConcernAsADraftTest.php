<?php

namespace Tests\Feature;

use App\Models\Concern;
use App\Models\ConcernDraft;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Starting a concern now and finishing it later.
 *
 * The form asks for an account of something that happened, with dates,
 * names and what was said. That is not a thing anybody finishes between
 * classes -- and until now leaving the page lost every word of it, so a
 * student either wrote the whole report in one sitting or filed a thinner
 * version than they meant to.
 *
 * The draft lives in its own table, not as a status on concerns. A
 * concern row is read by routing, the dashboard counts, every office's
 * queue and scopeVisibleTo, and a draft must appear in none of them: as a
 * status, a half-written accusation naming somebody would be one
 * forgotten `where` away from a staff queue.
 */
class SavingAConcernAsADraftTest extends TestCase
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

    /**
     * @return array<string, mixed>
     */
    private function halfWritten(array $overrides = []): array
    {
        return array_merge([
            'category' => 'Academic',
            'description' => 'It started in the second week of the term and I am still',
        ], $overrides);
    }

    /**
     * Saved, and waiting behind the Draft button when they come back.
     *
     * Saving leaves the form. Being handed the same half-filled page
     * back reads as nothing having happened -- there is no way to tell
     * the draft was kept, so the student saves again.
     */
    public function test_a_draft_is_kept_and_opened_from_the_button(): void
    {
        $this->actingAs($this->student())
            ->post(route('concerns.draft.save'), $this->halfWritten())
            ->assertRedirect(route('concerns.index'));

        $list = $this->actingAs($this->student())
            ->get(route('concerns.index'))->assertOk()->getContent();

        $this->assertStringContainsString(route('concerns.drafts'), $list,
            'the list does not offer the drafts anywhere');

        // Through the folder, which is what the button opens.
        $draft = ConcernDraft::where('user_id', $this->student()->id)->sole();

        $this->actingAs($this->student())->get(route('concerns.drafts'))
            ->assertOk()
            ->assertSee(route('concerns.create', ['draft' => $draft->id]), false);

        $page = $this->actingAs($this->student())
            ->get(route('concerns.create', ['draft' => $draft->id]))->assertOk()->getContent();

        $this->assertStringContainsString('still', $page);
        $this->assertStringContainsString('Carried on from the draft you saved', $page);

        fwrite(STDERR, "  [draft] a draft is kept and put back on the form\n");
    }

    /**
     * A draft is saved BECAUSE it is unfinished.
     *
     * Filing needs a category and twenty characters. Applying that here
     * would refuse to save exactly the state worth saving.
     */
    public function test_an_unfinished_draft_is_accepted(): void
    {
        $this->actingAs($this->student())
            ->post(route('concerns.draft.save'), ['description' => 'I am not sure how to say this'])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, ConcernDraft::where('user_id', $this->student()->id)->count());

        // Even with nothing in it at all.
        $this->actingAs($this->student())
            ->post(route('concerns.draft.save'), [])
            ->assertSessionHasNoErrors();

        fwrite(STDERR, "  [draft] an unfinished draft is accepted, which is the point\n");
    }

    /**
     * Saving the one you opened replaces it; saving a blank form adds.
     *
     * The distinction the folder exists for. While there could only be
     * one, starting a second report silently overwrote the first.
     */
    public function test_saving_updates_the_one_you_opened_and_adds_a_new_one_otherwise(): void
    {
        $this->actingAs($this->student())
            ->post(route('concerns.draft.save'), $this->halfWritten(['description' => 'The first attempt at writing this']));

        $first = ConcernDraft::where('user_id', $this->student()->id)->sole();

        // The same draft, carried back by the hidden field on the form.
        $this->actingAs($this->student())->post(route('concerns.draft.save'), $this->halfWritten([
            'draft_id' => $first->id,
            'description' => 'The same one, written better',
        ]));

        $this->assertSame(1, ConcernDraft::where('user_id', $this->student()->id)->count());
        $this->assertSame('The same one, written better', $first->fresh()->description);

        // A different report: a second row, and the first left alone.
        $this->actingAs($this->student())->post(route('concerns.draft.save'), $this->halfWritten([
            'category' => 'Facilities',
            'subcategory' => 'Water',
            'description' => 'Something else entirely',
        ]));

        $this->assertSame(2, ConcernDraft::where('user_id', $this->student()->id)->count(),
            'starting a second report overwrote the first');
        $this->assertSame('The same one, written better', $first->fresh()->description);

        fwrite(STDERR, "  [draft] saving updates the one you opened; a new report adds a row\n");
    }

    /**
     * A draft is not a concern.
     *
     * The guard this whole design exists for. Nothing routes it, nothing
     * counts it, and no office can see it.
     */
    public function test_a_draft_is_invisible_to_everybody_else(): void
    {
        $this->actingAs($this->student())->post(route('concerns.draft.save'), $this->halfWritten([
            'description' => 'A serious allegation I have not decided whether to make',
        ]));

        // Not a concern row, so nothing that reads concerns can find it.
        $this->assertSame(0, Concern::withTrashed()->count());

        foreach ([
            'the handler' => 'staff@cspc.edu.ph',
            'a counsellor' => 'counselor@cspc.edu.ph',
            'an admin' => 'admin@cspc.edu.ph',
            'the head of school' => 'head@cspc.edu.ph',
        ] as $who => $email) {
            $staff = User::where('email', $email)->firstOrFail();

            $list = $this->actingAs($staff)->get('/concerns')->getContent();

            $this->assertStringNotContainsString('A serious allegation', $list, $who.' can see a draft');
        }

        fwrite(STDERR, "  [draft] a draft reaches no queue, no count and no office\n");
    }

    /** And nobody else's draft comes back on your form. */
    public function test_a_draft_belongs_to_the_student_who_wrote_it(): void
    {
        $this->actingAs($this->student())->post(route('concerns.draft.save'), $this->halfWritten([
            'description' => 'Something only I should be able to read back',
        ]));

        $classmate = User::where('email', 'student2@my.cspc.edu.ph')->firstOrFail();

        $page = $this->actingAs($classmate)->get(route('concerns.create'))->assertOk()->getContent();

        $this->assertStringNotContainsString('only I should be able to read back', $page);

        fwrite(STDERR, "  [draft] a draft is only ever offered back to its author\n");
    }

    /**
     * Filing clears the draft it came from -- and only that one.
     *
     * Clearing the folder would delete every other report the student
     * had started, which is the thing a folder exists to stop.
     */
    public function test_submitting_clears_only_the_draft_it_came_from(): void
    {
        $this->actingAs($this->student())->post(route('concerns.draft.save'), $this->halfWritten());

        $filed = ConcernDraft::where('user_id', $this->student()->id)->sole();

        $this->actingAs($this->student())->post(route('concerns.draft.save'), $this->halfWritten([
            'category' => 'Facilities',
            'subcategory' => 'Water',
            'description' => 'A different report I have not finished',
        ]));

        $this->actingAs($this->student())->post('/concerns', [
            'draft_id' => $filed->id,
            'category' => 'Academic',
            'subcategory' => 'Grades',
            'description' => 'The whole account of what happened, written out properly this time.',
        ])->assertRedirect();

        $left = ConcernDraft::where('user_id', $this->student()->id)->get();

        $this->assertCount(1, $left, 'filing one concern emptied the folder');
        $this->assertSame('A different report I have not finished', $left->first()->description);

        fwrite(STDERR, "  [draft] filing clears the draft it came from, and leaves the rest\n");
    }

    /** Throwing it away works, and only on your own. */
    public function test_a_draft_can_be_discarded(): void
    {
        $this->actingAs($this->student())->post(route('concerns.draft.save'), $this->halfWritten());

        $draft = ConcernDraft::where('user_id', $this->student()->id)->sole();

        $this->actingAs($this->student())
            ->delete(route('concerns.draft.discard', $draft))
            ->assertRedirect(route('concerns.drafts'));

        $this->assertSame(0, ConcernDraft::where('user_id', $this->student()->id)->count());

        fwrite(STDERR, "  [draft] a draft can be thrown away\n");
    }

    /** Discarding is scoped to the person doing it. */
    public function test_discarding_does_not_touch_anybody_elses(): void
    {
        $this->actingAs($this->student())->post(route('concerns.draft.save'), $this->halfWritten());

        $draft = ConcernDraft::where('user_id', $this->student()->id)->sole();

        $classmate = User::where('email', 'student2@my.cspc.edu.ph')->firstOrFail();

        // Named outright now, since deleting takes an id -- so this is no
        // longer "it happened to miss", it is a refusal.
        $this->actingAs($classmate)
            ->delete(route('concerns.draft.discard', $draft))
            ->assertForbidden();

        $this->assertSame(1, ConcernDraft::where('user_id', $this->student()->id)->count(),
            'somebody else discarding cleared this student\'s draft');

        fwrite(STDERR, "  [draft] discarding touches only your own\n");
    }

    /**
     * A bounced submission is not overwritten by an older draft.
     *
     * The one thing worse than losing what somebody typed: replacing it
     * with something they typed last week, at the moment they are trying
     * to correct a validation error.
     */
    public function test_a_failed_submission_keeps_what_they_just_typed(): void
    {
        $this->actingAs($this->student())->post(route('concerns.draft.save'), $this->halfWritten([
            'description' => 'The older draft from last week entirely',
        ]));

        // Too short, so it bounces back with old input.
        $this->actingAs($this->student())->post('/concerns', [
            'category' => 'Academic',
            'subcategory' => 'Grades',
            'description' => 'too short',
        ])->assertSessionHasErrors('description');

        // Asking for the draft explicitly, which is the only case where
        // this can still go wrong -- and the one worth testing.
        $draft = ConcernDraft::where('user_id', $this->student()->id)->sole();

        $page = $this->actingAs($this->student())
            ->get(route('concerns.create', ['draft' => $draft->id]))->assertOk()->getContent();

        $this->assertStringContainsString('too short', $page);
        $this->assertStringNotContainsString('The older draft from last week', $page);

        fwrite(STDERR, "  [draft] a bounced submission is not overwritten by an older draft\n");
    }

    /**
     * "New Concern" means a blank form, draft or no draft.
     *
     * This is what the button buys. While the draft loaded itself, a
     * student starting something unrelated found last week's half-written
     * report already in the box -- and the quickest way past that is to
     * type over it, which loses the draft to save the new one.
     */
    public function test_starting_a_new_concern_does_not_open_the_draft(): void
    {
        $this->actingAs($this->student())->post(route('concerns.draft.save'), $this->halfWritten([
            'description' => 'Something unrelated I started last week',
        ]));

        $page = $this->actingAs($this->student())
            ->get(route('concerns.create'))->assertOk()->getContent();

        $this->assertStringNotContainsString('Something unrelated I started last week', $page);
        $this->assertStringNotContainsString('Carried on from the draft', $page);

        fwrite(STDERR, "  [draft] New Concern opens a blank form, draft or no draft\n");
    }

    /** No draft, no button. */
    public function test_the_button_appears_only_when_there_is_a_draft(): void
    {
        $withNone = $this->actingAs($this->student())
            ->get(route('concerns.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString(route('concerns.drafts'), $withNone);

        $this->actingAs($this->student())->post(route('concerns.draft.save'), $this->halfWritten());

        $withOne = $this->actingAs($this->student())
            ->get(route('concerns.index'))->assertOk()->getContent();

        $this->assertStringContainsString(route('concerns.drafts'), $withOne);
        $this->assertStringContainsString('>1</span>', $withOne, 'the button does not say how many');

        fwrite(STDERR, "  [draft] the button appears only when there is a draft\n");
    }

    /** And it is not offered to somebody else. */
    public function test_the_button_is_not_shown_to_another_student(): void
    {
        $this->actingAs($this->student())->post(route('concerns.draft.save'), $this->halfWritten());

        $classmate = User::where('email', 'student2@my.cspc.edu.ph')->firstOrFail();

        $page = $this->actingAs($classmate)
            ->get(route('concerns.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString(route('concerns.drafts'), $page);

        fwrite(STDERR, "  [draft] somebody else's draft is not offered\n");
    }

    /**
     * An empty draft does not announce itself.
     *
     * Saving an untouched form would otherwise greet them with "carried
     * on from the draft you saved" on a page that looks exactly as it
     * would have anyway.
     */
    public function test_an_empty_draft_says_nothing(): void
    {
        $this->actingAs($this->student())->post(route('concerns.draft.save'), ['category' => 'Academic']);

        $page = $this->actingAs($this->student())
            ->get(route('concerns.create'))->assertOk()->getContent();

        $this->assertStringNotContainsString('Carried on from the draft', $page);

        fwrite(STDERR, "  [draft] an empty draft does not announce itself\n");
    }

    /** The button is on the form, and will submit an incomplete one. */
    public function test_the_form_offers_to_save_a_draft(): void
    {
        $page = $this->actingAs($this->student())
            ->get(route('concerns.create'))->assertOk()->getContent();

        $this->assertStringContainsString(route('concerns.draft.save'), $page);
        $this->assertStringContainsString('Save as draft', $page);

        // Without formnovalidate the browser enforces the form's own
        // required fields, and the button silently does nothing on
        // exactly the incomplete form a draft is for.
        $this->assertStringContainsString('formnovalidate', $page);

        fwrite(STDERR, "  [draft] the form offers to save one, and will save an incomplete one\n");
    }

    /**
     * The folder lists them, newest first, and says what each one is.
     *
     * A draft has no title -- the student never gave it one, and asking
     * for one would be a field to fill in before they can save the thing
     * they are saving because they have not finished. So a row is named
     * after what it is about.
     */
    public function test_the_folder_lists_every_draft(): void
    {
        $this->actingAs($this->student())->post(route('concerns.draft.save'), $this->halfWritten([
            'subcategory' => 'Grades',
            'description' => 'The older one, about a grade',
        ]));

        $this->travel(2)->minutes();

        $this->actingAs($this->student())->post(route('concerns.draft.save'), $this->halfWritten([
            'category' => 'Facilities',
            'subcategory' => 'Water',
            'description' => 'The newer one, about the water',
        ]));

        $page = $this->actingAs($this->student())
            ->get(route('concerns.drafts'))->assertOk()->getContent();

        $this->assertStringContainsString('Academic · Grades', $page);
        $this->assertStringContainsString('Water', $page);
        $this->assertStringContainsString('The older one, about a grade', $page);

        // Newest first: what somebody coming back for "the one I was
        // writing" is looking for.
        $this->assertLessThan(
            strpos($page, 'The older one, about a grade'),
            strpos($page, 'The newer one, about the water'),
            'the oldest draft is at the top'
        );

        fwrite(STDERR, "  [draft] the folder lists every draft, newest first\n");
    }

    /** And only ever your own. */
    public function test_the_folder_holds_nobody_elses(): void
    {
        $this->actingAs($this->student())->post(route('concerns.draft.save'), $this->halfWritten([
            'description' => 'Something only I should be able to read back',
        ]));

        $classmate = User::where('email', 'student2@my.cspc.edu.ph')->firstOrFail();

        $this->actingAs($classmate)->get(route('concerns.drafts'))
            ->assertOk()
            ->assertDontSee('Something only I should be able to read back');

        fwrite(STDERR, "  [draft] the folder holds nobody else's\n");
    }

    /** An empty draft is not a row. */
    public function test_an_empty_draft_is_not_listed(): void
    {
        $this->actingAs($this->student())->post(route('concerns.draft.save'), ['category' => 'Academic']);

        $this->actingAs($this->student())->get(route('concerns.drafts'))
            ->assertOk()
            ->assertSee('Nothing saved yet');

        fwrite(STDERR, "  [draft] an empty draft is not given a row\n");
    }

    /** Signed out, there is nothing to save to. */
    public function test_it_needs_an_account(): void
    {
        $this->post(route('concerns.draft.save'), $this->halfWritten())
            ->assertRedirect(route('login'));

        fwrite(STDERR, "  [draft] saving a draft needs an account\n");
    }
}
