<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Naming somebody with "@" while writing the description.
 *
 * Every registered account is reachable. The part that matters is HOW: a
 * search, not a list shipped with the page. Embedding every account would
 * put the roster -- names, programmes, sections -- into the HTML of a form
 * any student can open, readable in full without typing a character.
 *
 * Two letters at least, eight results at most. Somebody looking up a person
 * they mean to name has a name in mind; neither limit gets in their way, and
 * together they stop the box being paged through as a directory.
 */
class CompletingANameInTheDescriptionTest extends TestCase
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

    private function page(): string
    {
        return $this->actingAs($this->student())->get('/concerns/create')
            ->assertOk()->getContent();
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function lookup(string $term): array
    {
        return $this->actingAs($this->student())
            ->getJson('/concerns-mentionable?q='.urlencode($term))
            ->assertOk()
            ->json();
    }

    /** The box, the trigger, and the hint that it exists. */
    public function test_the_completer_is_rendered(): void
    {
        $html = $this->page();

        $this->assertStringContainsString('id="mention-box"', $html);

        // Opened by an @, not by every word typed: matching each word made
        // the list appear over and over while somebody wrote a sentence.
        $this->assertStringContainsString('function currentMention()', $html);
        $this->assertStringContainsString('before.match(/@(', $html);

        // And the form says it is there, or nobody finds it.
        $this->assertStringContainsString('to name a staff member', $html);

        fwrite(STDERR, "  [mention] the @ completer is on the form\n");
    }

    /**
     * The roster is not in the page.
     *
     * This is the guard worth keeping. The filing form is reachable by every
     * student; a list rendered into it would answer "view source" with the
     * whole college.
     */
    public function test_the_page_does_not_ship_the_roster(): void
    {
        $html = $this->page();

        $this->assertStringNotContainsString('var PEOPLE = [{', $html);

        $others = User::whereHas('role', fn ($q) => $q->where('name', 'Student'))
            ->where('id', '!=', $this->student()->id)
            ->pluck('name');

        foreach ($others as $name) {
            $this->assertStringNotContainsString($name, $html, $name.' is rendered into the form');
        }

        fwrite(STDERR, "  [mention] the page ships no roster\n");
    }

    /** Anyone registered can be found, students included. */
    public function test_any_registered_account_can_be_found(): void
    {
        $staff = User::where('email', 'counselor@cspc.edu.ph')->firstOrFail();
        $classmate = User::where('email', 'student2@my.cspc.edu.ph')->firstOrFail();

        foreach ([$staff, $classmate] as $person) {
            $found = collect($this->lookup(mb_substr($person->name, 0, 4)))->pluck('name');

            $this->assertTrue($found->contains($person->name), $person->name.' was not found');
        }

        fwrite(STDERR, "  [mention] staff and students are both findable\n");
    }

    /** One letter returns nothing: that would be the directory. */
    public function test_a_single_letter_returns_nothing(): void
    {
        $this->assertSame([], $this->lookup('a'));
        $this->assertSame([], $this->lookup(''));

        fwrite(STDERR, "  [mention] one letter returns nothing\n");
    }

    /** And a broad match is capped. */
    public function test_results_are_capped(): void
    {
        $this->assertLessThanOrEqual(8, count($this->lookup('an')));

        fwrite(STDERR, "  [mention] a broad search is capped at eight\n");
    }

    /** Each result says who the person is, and nothing beyond that. */
    public function test_a_result_identifies_without_oversharing(): void
    {
        $person = User::where('email', 'student2@my.cspc.edu.ph')->firstOrFail();

        $row = collect($this->lookup(mb_substr($person->name, 0, 4)))
            ->firstWhere('name', $person->name);

        $this->assertNotNull($row);
        $this->assertNotSame('', trim($row['who']), 'a bare name cannot be told from a namesake');

        $encoded = json_encode($row);

        $this->assertStringNotContainsString($person->email, $encoded);

        if ($person->student_id) {
            $this->assertStringNotContainsString((string) $person->student_id, $encoded);
        }

        fwrite(STDERR, "  [mention] a result identifies without oversharing\n");
    }

    /** Signed out, it answers nobody. */
    public function test_it_needs_an_account(): void
    {
        $this->get('/concerns-mentionable?q=jon')->assertRedirect(route('login'));

        fwrite(STDERR, "  [mention] the lookup needs an account\n");
    }

    /**
     * It inserts text, and only text.
     *
     * Naming somebody in the description is not naming them as the SUBJECT
     * of the concern -- that is the checkbox, and it walls the person out of
     * the case and routes it over their head.
     */
    public function test_it_only_inserts_text(): void
    {
        $html = $this->page();

        $this->assertStringContainsString('area.dispatchEvent(new Event(\'input\'', $html);

        $this->assertStringNotContainsString('about_staff_id', substr(
            $html,
            strpos($html, 'function choose(name)'),
            600
        ));

        fwrite(STDERR, "  [mention] choosing a name inserts text and nothing else\n");
    }
}
