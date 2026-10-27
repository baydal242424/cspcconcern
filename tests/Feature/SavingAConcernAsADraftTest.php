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

    /** Saved, and waiting on the form when they come back. */
    public function test_a_draft_is_kept_and_put_back_on_the_form(): void
    {
        $this->actingAs($this->student())
            ->post(route('concerns.draft.save'), $this->halfWritten())
            ->assertRedirect(route('concerns.create'));

        $page = $this->actingAs($this->student())
            ->get(route('concerns.create'))->assertOk()->getContent();

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

    /** One each. Saving again replaces. */
    public function test_saving_again_replaces_the_draft(): void
    {
        $this->actingAs($this->student())
            ->post(route('concerns.draft.save'), $this->halfWritten(['description' => 'The first attempt at writing this']));

        $this->actingAs($this->student())
            ->post(route('concerns.draft.save'), $this->halfWritten(['description' => 'The second attempt, which is better']));

        $drafts = ConcernDraft::where('user_id', $this->student()->id)->get();

        $this->assertCount(1, $drafts);
        $this->assertSame('The second attempt, which is better', $drafts->first()->description);

        fwrite(STDERR, "  [draft] saving again replaces rather than piling up\n");
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

    /** Filing it clears the draft. */
    public function test_submitting_clears_the_draft(): void
    {
        $this->actingAs($this->student())->post(route('concerns.draft.save'), $this->halfWritten());

        $this->actingAs($this->student())->post('/concerns', [
            'category' => 'Academic',
            'subcategory' => 'Grades',
            'description' => 'The whole account of what happened, written out properly this time.',
        ])->assertRedirect();

        $this->assertSame(0, ConcernDraft::where('user_id', $this->student()->id)->count(),
            'the draft outlived the concern it became');

        fwrite(STDERR, "  [draft] filing the concern clears the draft behind it\n");
    }

    /** Throwing it away works, and only on your own. */
    public function test_a_draft_can_be_discarded(): void
    {
        $this->actingAs($this->student())->post(route('concerns.draft.save'), $this->halfWritten());

        $this->actingAs($this->student())
            ->delete(route('concerns.draft.discard'))
            ->assertRedirect(route('concerns.create'));

        $this->assertSame(0, ConcernDraft::where('user_id', $this->student()->id)->count());

        fwrite(STDERR, "  [draft] a draft can be thrown away\n");
    }

    /** Discarding is scoped to the person doing it. */
    public function test_discarding_does_not_touch_anybody_elses(): void
    {
        $this->actingAs($this->student())->post(route('concerns.draft.save'), $this->halfWritten());

        $classmate = User::where('email', 'student2@my.cspc.edu.ph')->firstOrFail();
        $this->actingAs($classmate)->delete(route('concerns.draft.discard'));

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

        $page = $this->actingAs($this->student())
            ->get(route('concerns.create'))->assertOk()->getContent();

        $this->assertStringContainsString('too short', $page);
        $this->assertStringNotContainsString('The older draft from last week', $page);

        fwrite(STDERR, "  [draft] a bounced submission is not overwritten by an older draft\n");
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

    /** Signed out, there is nothing to save to. */
    public function test_it_needs_an_account(): void
    {
        $this->post(route('concerns.draft.save'), $this->halfWritten())
            ->assertRedirect(route('login'));

        fwrite(STDERR, "  [draft] saving a draft needs an account\n");
    }
}
