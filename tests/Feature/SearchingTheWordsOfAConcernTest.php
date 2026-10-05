<?php

namespace Tests\Feature;

use App\Models\Concern;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Finding a concern by words from inside it.
 *
 * The box matched the typed text as ONE string, so it found a concern only
 * where the words happened to sit together in that exact order. Fair for a
 * reference number, poor for prose: somebody who files a letter-length
 * account and comes back a fortnight later remembers two or three words from
 * it, not a contiguous phrase.
 *
 *   "Over the past few weeks"  -> found  (the phrase, verbatim)
 *   "past weeks"               -> missed (two words, one gap)
 *   "challenges academic"      -> missed (both present, wrong order)
 */
class SearchingTheWordsOfAConcernTest extends TestCase
{
    use RefreshDatabase;

    /** A letter, with the line breaks a pasted one really has. */
    private const LETTER = "Dear Teacher,\n\nI hope this letter finds you well. I am writing to formally"
        ." express my concern regarding my academic performance in Data Structures.\n\nOver the past few"
        ." weeks, I have encountered challenges that have affected my ability to perform at my best.";

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
     * The row's own link.
     *
     * Asserting on "#12" matched the CSS colour #1f2733 in the page head,
     * so a concern that was not in the results looked as though it was.
     */
    private function link(Concern $concern): string
    {
        return '/concerns/'.$concern->id.'"';
    }

    private function aLetter(string $text = self::LETTER): Concern
    {
        return Concern::create([
            'user_id' => $this->student()->id,
            'category' => 'Academic',
            'department' => 'College of Computer Studies',
            'description' => $text,
            'urgency' => 'Low',
            'status' => 'submitted',
            'is_anonymous' => false,
        ]);
    }

    /** @return array<string, array{0: string}> */
    public static function termsThatShouldFind(): array
    {
        return [
            'the phrase as written' => ['Over the past few weeks'],
            'two words with a gap between them' => ['past weeks'],
            'two words in the wrong order' => ['challenges academic'],
            'words either side of a line break' => ['Structures Over'],
            'a different case' => ['ACADEMIC PERFORMANCE'],
            'one word' => ['challenges'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('termsThatShouldFind')]
    public function test_a_concern_is_found_by_its_words(string $term): void
    {
        $concern = $this->aLetter();

        $this->actingAs($this->student())
            ->get('/concerns?q='.urlencode($term))
            ->assertOk()
            ->assertSee($this->link($concern), false);

        fwrite(STDERR, "  [search] found by \"{$term}\"\n");
    }

    /** Every word has to be there, or it is a different concern. */
    public function test_a_word_that_is_not_there_finds_nothing(): void
    {
        $concern = $this->aLetter();

        $this->actingAs($this->student())
            ->get('/concerns?q='.urlencode('challenges plumbing'))
            ->assertOk()
            ->assertDontSee($this->link($concern), false);

        fwrite(STDERR, "  [search] every word must be present\n");
    }

    /** The number still works, which is what the box was built for. */
    public function test_the_concern_number_still_works(): void
    {
        $concern = $this->aLetter();

        foreach ([(string) $concern->id, '#'.$concern->id] as $term) {
            $this->actingAs($this->student())
                ->get('/concerns?q='.urlencode($term))
                ->assertOk()
                ->assertSee($this->link($concern), false);
        }

        fwrite(STDERR, "  [search] a concern number still finds it\n");
    }

    /**
     * A wildcard typed into the box is a character, not an instruction.
     *
     * Unescaped, "100%" asked the database for "100 followed by anything",
     * which quietly matches far more than the person meant; an underscore
     * does the same for any single character.
     */
    public function test_like_wildcards_are_treated_as_text(): void
    {
        $plain = $this->aLetter('My grade dropped and nobody explained why.');
        $literal = $this->aLetter('I was charged 100% of the fee twice.');

        $resp = $this->actingAs($this->student())
            ->get('/concerns?q='.urlencode('100%'))->assertOk();

        $resp->assertSee($this->link($literal), false);
        $resp->assertDontSee($this->link($plain), false);

        fwrite(STDERR, "  [search] a wildcard typed in the box is just text\n");
    }

    /** A pasted letter does not turn into three hundred conditions. */
    public function test_a_pasted_letter_still_finds_its_own_concern(): void
    {
        $concern = $this->aLetter();

        $this->actingAs($this->student())
            ->get('/concerns?q='.urlencode(self::LETTER))
            ->assertOk()
            ->assertSee($this->link($concern), false);

        fwrite(STDERR, "  [search] pasting the whole letter finds it\n");
    }
}
