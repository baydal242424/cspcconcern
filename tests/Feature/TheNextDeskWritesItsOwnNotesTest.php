<?php

namespace Tests\Feature;

use App\Models\Concern;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The two note boxes open empty for the desk a referral handed the case to.
 *
 * They used to open with whatever was saved. For the person who wrote it
 * that is right -- it is how a typo gets fixed. For the next handler it
 * was the previous handler's words typed into their box, above a Save
 * button, where the quickest way through the form is to submit somebody
 * else's account of the case as your own.
 *
 * Nothing is hidden by this. The notes are shown in full further up the
 * page; what changes is only whether the form hands back a copy.
 */
class TheNextDeskWritesItsOwnNotesTest extends TestCase
{
    use RefreshDatabase;

    private const REVIEW = 'Looked into this and spoke with the people involved.';

    private const RESOLUTION = 'Recorded what is being done about it.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, UserSeeder::class]);
    }

    private function reporter(): User
    {
        return User::where('email', 'student@my.cspc.edu.ph')->firstOrFail();
    }

    /** Neither role is in the seed, so the pair is built here. */
    private function staff(string $role, string $email, string $name): User
    {
        return User::firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => \Illuminate\Support\Facades\Hash::make('not-used'),
                'role_id' => \App\Models\Role::where('name', $role)->firstOrFail()->id,
                'status' => 'approved',
                'department' => $this->reporter()->department,
            ]
        );
    }

    private function firstHandler(): User
    {
        return $this->staff('Adviser', 'the.adviser@cspc.edu.ph', 'The Class Adviser');
    }

    private function nextHandler(): User
    {
        return $this->staff('Program Chair', 'the.chair@cspc.edu.ph', 'The Programme Chair');
    }

    private function aConcernWith(User $handler): Concern
    {
        return Concern::create([
            'user_id' => $this->reporter()->id,
            'category' => 'Academic',
            'subcategory' => 'Grades',
            'department' => $this->reporter()->department,
            'course' => $this->reporter()->course,
            'section' => $this->reporter()->section,
            'description' => 'A mark that does not match the work handed in.',
            'urgency' => 'Medium',
            'status' => 'submitted',
            'is_anonymous' => false,
            'assigned_to' => $handler->id,
        ]);
    }

    /** What is actually sitting inside a named box on the page. */
    private function boxOn(string $html, string $name): string
    {
        $found = preg_match(
            '~<textarea[^>]*name="'.preg_quote($name, '~').'"[^>]*>(.*?)</textarea>~s',
            $html,
            $m
        );

        $this->assertSame(1, $found, "No {$name} box on the page.");

        return trim(html_entity_decode($m[1]));
    }

    private function saveNotes(User $as, Concern $concern, array $extra = []): void
    {
        $this->actingAs($as)->put("/concerns/{$concern->id}", array_merge([
            'status' => 'in_progress',
            'urgency' => 'Medium',
            'investigation_notes' => self::REVIEW,
            'resolution_notes' => self::RESOLUTION,
        ], $extra));
    }

    /**
     * Even for the person who wrote them. The saved notes are on the page
     * above; a second, editable copy above a Save button is what gets
     * submitted back unread as this visit's account of the case.
     */
    public function test_the_boxes_are_empty_for_the_author_too(): void
    {
        $concern = $this->aConcernWith($this->firstHandler());
        $this->saveNotes($this->firstHandler(), $concern);

        $html = $this->actingAs($this->firstHandler())
            ->get("/concerns/{$concern->id}")->assertOk()->getContent();

        $this->assertSame('', $this->boxOn($html, 'investigation_notes'));
        $this->assertSame('', $this->boxOn($html, 'resolution_notes'));

        // Written down, just not handed back to be retyped.
        $this->assertStringContainsString(self::REVIEW, $html);
        $this->assertStringContainsString(self::RESOLUTION, $html);
    }

    public function test_saving_notes_records_who_wrote_them(): void
    {
        $concern = $this->aConcernWith($this->firstHandler());
        $this->saveNotes($this->firstHandler(), $concern);

        $this->assertEquals($this->firstHandler()->id, $concern->fresh()->handling_notes_by);
    }

    public function test_the_desk_it_was_referred_to_opens_empty_boxes(): void
    {
        $concern = $this->aConcernWith($this->firstHandler());
        $this->saveNotes($this->firstHandler(), $concern);

        $this->saveNotes($this->firstHandler(), $concern, [
            'status' => 'referred',
            'referred_to' => 'Program Chair',
            'referred_to_user_id' => $this->nextHandler()->id,
        ]);

        $this->assertEquals($this->nextHandler()->id, $concern->fresh()->assigned_to);

        $html = $this->actingAs($this->nextHandler())
            ->get("/concerns/{$concern->id}")->assertOk()->getContent();

        $this->assertSame('', $this->boxOn($html, 'investigation_notes'));
        $this->assertSame('', $this->boxOn($html, 'resolution_notes'));
    }

    /** Empty box, but nothing lost: the previous notes are still on the page. */
    public function test_the_previous_notes_are_still_readable_above_the_form(): void
    {
        $concern = $this->aConcernWith($this->firstHandler());
        $this->saveNotes($this->firstHandler(), $concern);
        $this->saveNotes($this->firstHandler(), $concern, [
            'status' => 'referred',
            'referred_to' => 'Program Chair',
            'referred_to_user_id' => $this->nextHandler()->id,
        ]);

        $html = $this->actingAs($this->nextHandler())
            ->get("/concerns/{$concern->id}")->assertOk()->getContent();

        $this->assertStringContainsString(self::REVIEW, $html);
        $this->assertStringContainsString(self::RESOLUTION, $html);

        // And they are told where it went, so an empty box does not read
        // as the previous handler's work having been lost.
        $this->assertStringContainsString('always start empty', $html);
    }

    /** Writing their own moves the signature across, and saves the words. */
    public function test_the_new_handler_becomes_the_author_once_they_write(): void
    {
        $concern = $this->aConcernWith($this->firstHandler());
        $this->saveNotes($this->firstHandler(), $concern);
        $this->saveNotes($this->firstHandler(), $concern, [
            'status' => 'referred',
            'referred_to' => 'Program Chair',
            'referred_to_user_id' => $this->nextHandler()->id,
        ]);

        $mine = 'Checked the records myself and called the instructor.';

        $this->saveNotes($this->nextHandler(), $concern, [
            'investigation_notes' => $mine,
            'resolution_notes' => 'The mark is being recomputed this week.',
        ]);

        $this->assertEquals($this->nextHandler()->id, $concern->fresh()->handling_notes_by);
        $this->assertEquals($mine, $concern->fresh()->investigation_notes);

        $html = $this->actingAs($this->nextHandler())
            ->get("/concerns/{$concern->id}")->assertOk()->getContent();

        // Saved and shown on the page, with the box still empty for next time.
        $this->assertStringContainsString($mine, $html);
        $this->assertSame('', $this->boxOn($html, 'investigation_notes'));
    }

    /**
     * A save that bounces must not throw away what was typed.
     *
     * old() has to win over the blanking, or a handler who mistypes one
     * field loses the paragraph they wrote in the other.
     */
    public function test_a_bounced_save_keeps_what_was_typed(): void
    {
        $concern = $this->aConcernWith($this->firstHandler());
        $this->saveNotes($this->firstHandler(), $concern);
        $this->saveNotes($this->firstHandler(), $concern, [
            'status' => 'referred',
            'referred_to' => 'Program Chair',
            'referred_to_user_id' => $this->nextHandler()->id,
        ]);

        $typed = 'A long answer the chair typed out and does not want to lose.';

        $this->actingAs($this->nextHandler())
            ->put("/concerns/{$concern->id}", [
                'status' => 'in_progress',
                'urgency' => 'Medium',
                'investigation_notes' => $typed,
                // Too short: the save bounces.
                'resolution_notes' => 'x',
            ])->assertSessionHasErrors('resolution_notes');

        $html = $this->actingAs($this->nextHandler())
            ->get("/concerns/{$concern->id}")->assertOk()->getContent();

        $this->assertSame($typed, $this->boxOn($html, 'investigation_notes'));
    }

    /** A case nobody has written on yet opens empty, as it always did. */
    public function test_an_untouched_case_opens_empty(): void
    {
        $concern = $this->aConcernWith($this->firstHandler());

        $html = $this->actingAs($this->firstHandler())
            ->get("/concerns/{$concern->id}")->assertOk()->getContent();

        $this->assertSame('', $this->boxOn($html, 'investigation_notes'));
        $this->assertSame('', $this->boxOn($html, 'resolution_notes'));

        // Nothing to say about notes that do not exist.
        $this->assertStringNotContainsString('always start empty', $html);
    }
}
