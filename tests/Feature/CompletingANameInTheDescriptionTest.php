<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Completing somebody's name while writing the description.
 *
 * Typing a few letters offers the full name, so "Mr. Panizal" does not go in
 * as "mr panizal" or, worse, as a different Panizal.
 *
 * The list is the SAME set the subject picker already offers, and those
 * names are already rendered on this page. An autocomplete over every
 * account would turn the filing form into a directory: type "a" and read
 * back eight hundred students with their programmes and sections.
 */
class CompletingANameInTheDescriptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, UserSeeder::class]);
    }

    private function form(): string
    {
        return $this->actingAs(User::where('email', 'student@my.cspc.edu.ph')->firstOrFail())
            ->get('/concerns/create')->assertOk()->getContent();
    }

    /** @return array<string, mixed> */
    private function people(): array
    {
        preg_match('/var PEOPLE = (\[.*?\]);/s', $this->form(), $m);

        return json_decode($m[1] ?? '[]', true) ?: [];
    }

    /** The box and its list are on the page, and the @ opens it. */
    public function test_the_completer_is_rendered(): void
    {
        $html = $this->form();

        $this->assertStringContainsString('id="mention-box"', $html);
        $this->assertStringContainsString('var PEOPLE =', $html);

        // Opened by an @, not by every word being typed: matching each
        // word made the list appear over and over while somebody was
        // writing an ordinary sentence. The @ is the person asking.
        $this->assertStringContainsString('function currentMention()', $html);
        $this->assertStringContainsString('before.match(/@(', $html);

        // And the form says the @ is there, or nobody finds it.
        $this->assertStringContainsString('to name a staff member', $html);
        $this->assertNotEmpty($this->people(), 'there should be somebody to complete');

        fwrite(STDERR, '  [mention] the completer is on the form with '.count($this->people())." names\n");
    }

    /** Each entry says who the person is, not just their name. */
    public function test_each_entry_says_who_they_are(): void
    {
        foreach ($this->people() as $person) {
            $this->assertArrayHasKey('name', $person);
            $this->assertArrayHasKey('who', $person);
            $this->assertNotSame('', trim($person['who']), $person['name'].' has no role or office');
        }

        fwrite(STDERR, "  [mention] every entry carries a role and an office\n");
    }

    /**
     * No student is offered, and no name that is not already on the page.
     *
     * This is the part worth guarding. The filing form is reachable by every
     * student in the college, and a completer over the whole account table
     * would answer "a" with the roster.
     */
    public function test_it_offers_nobody_the_page_does_not_already_show(): void
    {
        $offered = collect($this->people())->pluck('name');

        $students = User::whereHas('role', fn ($q) => $q->where('name', 'Student'))->pluck('name');

        foreach ($students as $studentName) {
            $this->assertFalse(
                $offered->contains($studentName),
                'a student was offered for completion: '.$studentName
            );
        }

        // And every offered name is one the subject picker already holds.
        // Compared against the view data rather than the rendered HTML:
        // Blade escapes a name like "Dr. Kenny Niño H. Tagum", so a
        // substring check against the page fails on the accent alone.
        $picker = collect($this->actingAs(User::where('email', 'student@my.cspc.edu.ph')->firstOrFail())
            ->get('/concerns/create')->viewData('otherStaffByOffice'))
            ->flatten(1)
            ->pluck('name');

        foreach ($offered as $name) {
            $this->assertTrue($picker->contains($name), $name.' is offered but not in the picker');
        }

        fwrite(STDERR, "  [mention] it offers nobody the page does not already show\n");
    }

    /**
     * It inserts text, and only text.
     *
     * Mentioning somebody in the description is not naming them as the
     * subject of the concern -- that is the checkbox, and it walls the
     * person out of the case and routes it over their head.
     */
    public function test_it_only_inserts_text(): void
    {
        $html = $this->form();

        // The chooser writes into the textarea and fires its input event so
        // the character counter keeps up.
        $this->assertStringContainsString('area.dispatchEvent(new Event(\'input\'', $html);

        // It never ticks the subject box.
        $this->assertStringNotContainsString('about_staff_id', substr(
            $html,
            strpos($html, 'function choose(name)'),
            600
        ));

        fwrite(STDERR, "  [mention] choosing a name inserts text and nothing else\n");
    }
}
