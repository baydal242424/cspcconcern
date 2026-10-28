<?php

namespace Tests\Feature;

use App\Models\Concern;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The same thing happens again, months later.
 *
 * A student reports harassment, it is handled, the case is resolved. Some
 * months later the same person does it again. Filed as an unconnected new
 * report, the fact that matters most is missing: that it already happened
 * once and was upheld.
 *
 * Reopening the original was the obvious answer and the wrong one. It would
 * move the resolution date, overwrite the outcome and make "resolved" mean
 * nothing -- and the first finding is exactly the record a second incident
 * must not be allowed to erase. A new concern is opened instead, carrying a
 * link back, so the handler starts out knowing it is not the first time.
 */
class ReportingASettledConcernAgainTest extends TestCase
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

    private function aSettledConcern(array $overrides = []): Concern
    {
        return Concern::create(array_merge([
            'user_id' => $this->student()->id,
            'category' => 'Harassment',
            'department' => 'College of Computer Studies',
            'description' => 'What happened the first time.',
            'urgency' => 'High',
            'status' => 'resolved',
            'resolved_at' => now()->subMonths(4),
            'is_anonymous' => false,
        ], $overrides));
    }

    /** The button is on their own settled concern. */
    public function test_the_reporter_is_offered_it_once_the_case_is_settled(): void
    {
        $concern = $this->aSettledConcern();

        $this->actingAs($this->student())->get("/concerns/{$concern->id}")->assertOk()
            ->assertSee('Has this happened again?')
            ->assertSee('follows_up_on='.$concern->id, false);

        fwrite(STDERR, "  [again] offered on a settled concern\n");
    }

    /** Not while it is still open: there is nothing to repeat yet. */
    public function test_it_is_not_offered_while_the_case_is_open(): void
    {
        $concern = $this->aSettledConcern(['status' => 'in_progress', 'resolved_at' => null]);

        $this->actingAs($this->student())->get("/concerns/{$concern->id}")->assertOk()
            ->assertDontSee('Has this happened again?');

        fwrite(STDERR, "  [again] not offered while it is still open\n");
    }

    /** The form opens knowing which concern it repeats. */
    public function test_the_form_says_what_it_follows(): void
    {
        $concern = $this->aSettledConcern();

        $this->actingAs($this->student())
            ->get('/concerns/create?follows_up_on='.$concern->id)->assertOk()
            ->assertSee('This has happened again.')
            ->assertSee('name="follows_up_on_id" value="'.$concern->id.'"', false);

        fwrite(STDERR, "  [again] the form says what it follows\n");
    }

    /**
     * And it answers the questions it has already answered.
     *
     * The banner said "reporting #133 again -- Academic" while the
     * dropdown underneath still read "-- Select a category --". The
     * student had to answer a question the page had just answered for
     * them, and picking the wrong one sends the repeat to an office the
     * first report never went near.
     */
    public function test_the_category_and_the_people_are_already_filled_in(): void
    {
        $concern = $this->aSettledConcern([
            'category' => 'Academic',
            'subcategory' => 'Grades',
        ]);

        // Taken from the picker the form actually renders, rather than
        // named here: not every staff account appears in it -- instructors
        // are reached through the adviser question instead -- and a name
        // chosen in the test would be asserting against a checkbox that
        // was never drawn.
        $blank = $this->actingAs($this->student())
            ->get('/concerns/create')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/name="about_staff_id\[\]" value="(\d+)"/', $blank);

        preg_match('/name="about_staff_id\[\]" value="(\d+)"/', $blank, $who);

        $named = User::findOrFail((int) $who[1]);

        $concern->syncSubjects([$named->id]);

        $page = $this->actingAs($this->student())
            ->get('/concerns/create?follows_up_on='.$concern->id)->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/<option value="Academic"[^>]*selected/',
            $page,
            'the category was not carried over'
        );

        $this->assertStringContainsString('data-old="Grades"', $page,
            'the sub-category was not carried over');

        $this->assertMatchesRegularExpression(
            '/value="'.$named->id.'"[^>]*checked/',
            $page,
            'the person it was about was not carried over'
        );

        fwrite(STDERR, "  [again] the category, sub-category and people come with it\n");
    }

    /** And the form does not ask again: the category is shown locked. */
    public function test_the_category_cannot_be_changed_on_the_form(): void
    {
        $concern = $this->aSettledConcern(['category' => 'Academic']);

        $page = $this->actingAs($this->student())
            ->get('/concerns/create?follows_up_on='.$concern->id)->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/<select[^>]*id="category"[^>]*\sdisabled/',
            $page,
            'the category is still a question on a repeat'
        );

        // A disabled control submits nothing, so the value has to travel
        // some other way or the form arrives without a category at all.
        $this->assertStringContainsString(
            '<input type="hidden" name="category" value="Academic">',
            $page
        );

        fwrite(STDERR, "  [again] the category is shown locked\n");
    }

    /**
     * And locking it is enforced, not drawn.
     *
     * A disabled field is a picture: the POST can carry anything. If the
     * form were trusted, "reporting #133 again -- Academic" could file a
     * Harassment case, which routes to a different office entirely --
     * one the first report never reached.
     */
    public function test_a_crafted_repeat_cannot_change_the_category(): void
    {
        $concern = $this->aSettledConcern([
            'category' => 'Academic',
            'subcategory' => 'Grades',
        ]);

        $this->actingAs($this->student())->post('/concerns', [
            'follows_up_on_id' => $concern->id,
            'category' => 'Harassment',
            'subcategory' => 'Stalking',
            'description' => 'It has happened again and here is what has changed since.',
        ])->assertRedirect();

        $filed = Concern::latest('id')->firstOrFail();

        $this->assertSame($concern->id, $filed->follows_up_on_id);
        $this->assertSame('Academic', $filed->category, 'the repeat was filed under another category');
        $this->assertSame('Grades', $filed->subcategory);

        fwrite(STDERR, "  [again] a crafted repeat cannot change the category\n");
    }

    /**
     * The description does not come with it.
     *
     * The banner asks what has happened SINCE. Handing back the old
     * account to edit is an invitation to file the first report twice,
     * and the staff reading it can already see the first one.
     */
    public function test_the_old_description_is_not_carried_over(): void
    {
        $concern = $this->aSettledConcern(['description' => 'What happened the first time.']);

        $page = $this->actingAs($this->student())
            ->get('/concerns/create?follows_up_on='.$concern->id)->assertOk()->getContent();

        preg_match('/<textarea[^>]*id="description".*?<\/textarea>/s', $page, $m);

        $inner = preg_replace(['/^<textarea[^>]*>/s', '/<\/textarea>$/'], '', $m[0] ?? '');

        $this->assertSame('', trim($inner), 'the first report was handed back to be edited');

        fwrite(STDERR, "  [again] the old description is not carried over\n");
    }

    /** Filing it opens a NEW case, and the first one is untouched. */
    public function test_it_opens_a_new_case_and_leaves_the_first_alone(): void
    {
        $first = $this->aSettledConcern();
        $resolvedAt = $first->resolved_at;

        $this->actingAs($this->student())->post('/concerns', [
            'category' => 'Harassment',
            'subcategory' => 'Sexual harassment',
            'department' => 'College of Computer Studies',
            'description' => 'He did it again last week, after it was settled.',
            'follows_up_on_id' => $first->id,
        ])->assertSessionHasNoErrors();

        $second = Concern::latest('id')->first();

        $this->assertNotSame($first->id, $second->id, 'a new case, not the old one reopened');
        $this->assertSame($first->id, $second->follows_up_on_id);

        // The first finding stands.
        $first->refresh();
        $this->assertSame('resolved', $first->status);
        $this->assertTrue($resolvedAt->equalTo($first->resolved_at), 'its resolution date did not move');

        fwrite(STDERR, "  [again] a new case is opened; the first is untouched\n");
    }

    /** Both pages say so, which is the point of the link. */
    public function test_the_link_is_visible_from_both_ends(): void
    {
        $first = $this->aSettledConcern();

        $second = Concern::create([
            'user_id' => $this->student()->id,
            'follows_up_on_id' => $first->id,
            'category' => 'Harassment',
            'department' => 'College of Computer Studies',
            'description' => 'It happened again.',
            'urgency' => 'High',
            'status' => 'submitted',
            'is_anonymous' => false,
        ]);

        $this->actingAs($this->student())->get("/concerns/{$second->id}")->assertOk()
            ->assertSee('Reported before')
            ->assertSee('#'.$first->id);

        $this->flushSession();

        $this->actingAs($this->student())->get("/concerns/{$first->id}")->assertOk()
            ->assertSee('Reported again as');

        fwrite(STDERR, "  [again] the link shows from both ends\n");
    }

    /** Somebody else's concern cannot be followed up, however the id arrives. */
    public function test_it_must_be_their_own_concern(): void
    {
        $other = User::where('email', 'student2@my.cspc.edu.ph')->firstOrFail();
        $theirs = $this->aSettledConcern(['user_id' => $other->id]);

        // Not offered on the form...
        $this->actingAs($this->student())
            ->get('/concerns/create?follows_up_on='.$theirs->id)->assertOk()
            ->assertDontSee('This has happened again.');

        // ...and the link is dropped if the id is posted anyway.
        $this->actingAs($this->student())->post('/concerns', [
            'category' => 'Harassment',
            'subcategory' => 'Sexual harassment',
            'department' => 'College of Computer Studies',
            'description' => 'Trying to attach myself to another student case.',
            'follows_up_on_id' => $theirs->id,
        ])->assertSessionHasNoErrors();

        $this->assertNull(Concern::latest('id')->first()->follows_up_on_id);

        fwrite(STDERR, "  [again] it must be their own concern\n");
    }
}
