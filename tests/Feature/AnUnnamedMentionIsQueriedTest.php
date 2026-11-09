<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Writing @Somebody is not the same as naming them, and the form says so.
 *
 * The @ completer writes text into the description so a name is spelled
 * right. The checkbox is what records who the concern is ABOUT -- which
 * walls that person out of the case, routes it over their head, and tells
 * them it is being looked into. Nothing on the form distinguished the two.
 *
 * The first bullying report filed here read "bullying behavior from
 * @Glaiza Pocaan" and named nobody at all, so nobody was ever told. The
 * person who built the form made that mistake on their own form, which is
 * the clearest evidence available that a student would.
 *
 * So a send that mentions somebody nobody has ticked is held once and
 * asked about. A prompt, not a rule: the server does not depend on it, and
 * pressing Send again goes through.
 */
class AnUnnamedMentionIsQueriedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, UserSeeder::class]);
    }

    private function form(): string
    {
        $student = User::where('email', 'student@my.cspc.edu.ph')->firstOrFail();

        return $this->actingAs($student)->get('/concerns/create')->assertOk()->getContent();
    }

    public function test_the_question_is_on_the_form_ready_to_be_asked(): void
    {
        $html = $this->form();

        $this->assertStringContainsString('id="mention-check"', $html);
        $this->assertStringContainsString('id="mention-check-list"', $html);
        $this->assertStringContainsString('id="mention-check-go"', $html);

        // Hidden until there is something to ask about.
        $this->assertMatchesRegularExpression('~id="mention-check"\s+hidden~', $html);
    }

    /** It says what the difference actually is, in words. */
    public function test_it_explains_that_a_mention_is_not_a_naming(): void
    {
        $html = $this->form();

        $this->assertStringContainsString('does not tell us the concern is', $html);
        $this->assertStringContainsString('You mentioned somebody by name', $html);
    }

    /**
     * The checkbox in the question drives the real one.
     *
     * Two lists of who a concern is about is how they disagree, and the
     * one that would lose is the one that gets submitted.
     */
    public function test_the_question_drives_the_real_checkbox(): void
    {
        $html = $this->form();

        $this->assertStringContainsString('person.box.checked = tick.checked', $html);
        $this->assertStringContainsString("dispatchEvent(new Event('change', { bubbles: true }))", $html);
    }

    /** Saving a draft is not filing, so it is never interrupted. */
    public function test_saving_a_draft_is_not_questioned(): void
    {
        $html = $this->form();

        $this->assertStringContainsString(
            "if (event.submitter && event.submitter.hasAttribute('formaction')) return;",
            $html
        );
    }

    /**
     * Asked once. A student who means only to spell a name presses Send
     * again and it goes; a question that cannot be dismissed is a wall.
     */
    public function test_it_asks_once_and_does_not_trap_them(): void
    {
        $html = $this->form();

        $this->assertStringContainsString('if (asked) return;', $html);
    }

    /**
     * Only people the form actually offers, matched longest name first.
     *
     * Shortest-first would match a short name inside a longer one and ask
     * about the wrong person.
     */
    public function test_it_matches_the_longest_name_first(): void
    {
        $html = $this->form();

        $this->assertStringContainsString('return b.name.length - a.name.length;', $html);
    }
}
