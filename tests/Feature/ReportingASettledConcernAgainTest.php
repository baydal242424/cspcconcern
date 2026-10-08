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

    /** Filing it opens a NEW case, and the first one is untouched. */
    public function test_it_opens_a_new_case_and_leaves_the_first_alone(): void
    {
        $first = $this->aSettledConcern();
        $resolvedAt = $first->resolved_at;

        $this->actingAs($this->student())->post('/concerns', [
            'category' => 'Harassment',
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
            'department' => 'College of Computer Studies',
            'description' => 'Trying to attach myself to another student case.',
            'follows_up_on_id' => $theirs->id,
        ])->assertSessionHasNoErrors();

        $this->assertNull(Concern::latest('id')->first()->follows_up_on_id);

        fwrite(STDERR, "  [again] it must be their own concern\n");
    }
}
