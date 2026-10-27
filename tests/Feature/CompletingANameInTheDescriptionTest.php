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
        // The WHOLE literal, not its opening. A partial match passed while
        // the regex was split across two lines by a stray newline -- the
        // break fell just after the part being asserted.
        $this->assertStringContainsString('before.match(/(?:^|\s)@([^@\n]{0,40})$/)', $html);

        // And the form says it is there, or nobody finds it.
        $this->assertStringContainsString('to name a staff member', $html);

        fwrite(STDERR, "  [mention] the @ completer is on the form\n");
    }

    /**
     * Every regex in the mention script closes on the line it opens.
     *
     * A JavaScript regex literal cannot span lines. One did: a patch wrote
     * a real newline where "\n" was meant, so the source held
     *
     *     before.match(/(?:^|\s)@([^@
     *     ]{0,40})$/);
     *
     * which is a SyntaxError. The browser discarded the ENTIRE script
     * block -- not just that function -- so nothing in it ran, and the
     * only symptom was that typing "@" did nothing at all. No request, no
     * console output anybody thought to look at.
     *
     * Checked here as structure rather than as one more exact string,
     * because the next stray newline will land somewhere else.
     */
    public function test_no_regex_in_the_mention_script_spans_a_line(): void
    {
        $html = $this->page();

        $at = strpos($html, "getElementById('mention-box')");
        $this->assertNotFalse($at, 'the mention script is not on the page');

        $open = strrpos(substr($html, 0, $at), '<script');
        $close = strpos($html, '</script>', $at);
        $script = substr($html, $open, $close - $open);

        foreach (explode("\n", $script) as $i => $line) {
            // Where a regex literal starts: .match(/ .replace(/ .split(/ .test(/
            if (! preg_match('/\.(?:match|replace|split|test|search)\(\//', $line)) {
                continue;
            }

            $body = substr($line, strpos($line, '(/') + 2);

            $this->assertStringContainsString(
                '/',
                $body,
                'line '.($i + 1).' opens a regex and never closes it: '.trim($line)
            );
        }

        fwrite(STDERR, "  [mention] every regex closes on its own line\n");
    }

    /**
     * The script runs after the box it drives.
     *
     * It sat after @endsection, and Blade emits anything outside a section
     * BEFORE the layout -- so the whole feature was served at the very top
     * of the page, above even the doctype. It looked for an element the
     * browser had not reached yet, found null, and returned. Typing "@"
     * did nothing, and nothing said why: no error, no failed request, just
     * a list that never came.
     *
     * Hence the position check rather than a second "is it present" one.
     * Both halves were always present; only the order was wrong.
     */
    public function test_the_script_comes_after_the_box(): void
    {
        $html = $this->page();

        $this->assertStringStartsWith('<!DOCTYPE html>', ltrim($html));

        $box = strpos($html, 'id="mention-box"');
        $script = strpos($html, "getElementById('mention-box')");

        $this->assertNotFalse($box, 'the list is not in the markup');
        $this->assertNotFalse($script, 'the script is not on the page');
        $this->assertLessThan($script, $box, 'the script runs before the box exists');

        fwrite(STDERR, "  [mention] the script runs after the box it drives\n");
    }

    /**
     * When the list opens, and when it stays shut.
     *
     * The rule lives in the page as a regex and a word count. Exercised here
     * against the cases that got it wrong: a three-word Filipino name closed
     * the list halfway through, so the student finished typing by hand --
     * the one thing this exists to prevent -- and an email address
     * mid-sentence held it open on "mail.com".
     *
     * @return array<string, array{0: string, 1: string|null}>
     */
    public static function typing(): array
    {
        return [
            'two letters' => ['@ch', 'ch'],
            'a name with an initial' => ['@ma.', 'ma.'],
            'three words of a name' => ['@ma. lovella brondial', 'ma. lovella brondial'],
            'mid-sentence' => ['it was @ch', 'ch'],
            'after a line break' => ["first line\n@ch", 'ch'],
            'an email address' => ['write to e@mail.com here', null],
            'a paragraph after a stray @' => ['@ one two three four five', null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('typing')]
    public function test_the_list_opens_only_where_it_should(string $typed, ?string $expected): void
    {
        $opens = preg_match('/(?:^|\s)@([^@\n]{0,40})$/', $typed, $m) === 1
            && count(preg_split('/\s+/', $m[1])) <= 4;

        $this->assertSame($expected !== null, $opens, json_encode($typed));

        if ($expected !== null) {
            $this->assertSame($expected, $m[1]);
        }


        fwrite(STDERR, '  [mention] '.json_encode($typed).' -> '
            .($expected === null ? "no list\n" : 'list on '.json_encode($expected)."\n"));
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

    /**
     * Where the match falls decides the order.
     *
     * Only eight rows are offered, and sorted by name alone the cap filled
     * with the wrong thing. "ch" matches dozens of accounts; alphabetically
     * that gave every "BS ... Program Chair" in the college -- they sort
     * under B -- and not one person called Ch-anything. Somebody reaching
     * for Christian saw no Christians until they had typed three letters.
     *
     * So the name starting with it comes first, then a later word starting
     * with it, then it buried mid-word. The three accounts here are built
     * rather than seeded so each tier is represented exactly once and the
     * order asserted is the ranking, not the alphabet: "Quilantang" would
     * sort before "Quiles" on name alone.
     */
    public function test_a_name_beginning_with_the_term_is_offered_first(): void
    {
        $tiers = [
            'starts the name' => 'Quiles Mariano',
            'starts a later word' => 'Ramon Quilantang',
            'buried mid-word' => 'Pedro Aquilino',
        ];

        foreach ($tiers as $name) {
            User::factory()->create([
                'name' => $name,
                'role_id' => $this->student()->role_id,
                'status' => 'approved',
            ]);
        }

        $offered = collect($this->lookup('quil'))->pluck('name')->values();

        $this->assertSame(array_values($tiers), $offered->all());

        fwrite(STDERR, "  [mention] \"quil\" ranks: ".$offered->implode(', ')."\n");
    }

    /**
     * And a mid-word match is still reachable.
     *
     * Ranking it last is not the same as dropping it: somebody who means
     * Aquilino can still get there by typing more of the name.
     */
    public function test_a_mid_word_match_is_still_reachable(): void
    {
        User::factory()->create([
            'name' => 'Pedro Aquilino',
            'role_id' => $this->student()->role_id,
            'status' => 'approved',
        ]);

        $found = collect($this->lookup('quilino'))->pluck('name');

        $this->assertTrue($found->contains('Pedro Aquilino'), 'a mid-word match became unreachable');

        fwrite(STDERR, "  [mention] \"quilino\" still reaches Pedro Aquilino\n");
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
