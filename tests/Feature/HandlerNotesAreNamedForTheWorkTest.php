<?php

namespace Tests\Feature;

use App\Models\Concern;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the handler's own notes are called.
 *
 * "Investigation Notes" was the label on all eleven categories. Raised in an
 * interview: most of them are not investigations. A student querying a grade
 * is asking somebody to CHECK a record; a dead lab PC is INSPECTED; a
 * counselling case is written up. Only Bullying and Harassment are
 * investigations in the sense the word carries -- and those are the two where
 * the word matters most, because the notes become part of a record that may
 * be read back.
 *
 * The column, the field and the rule requiring it are unchanged.
 */
class HandlerNotesAreNamedForTheWorkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, UserSeeder::class]);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function categories(): array
    {
        return [
            'a grade query is a record being checked' => ['Academic', 'Review Notes'],
            'counselling is written up' => ['Mental Health', 'Case Notes'],
            'bullying really is investigated' => ['Bullying', 'Investigation Notes'],
            'harassment really is investigated' => ['Harassment', 'Investigation Notes'],
            'a website fault is diagnosed' => ['Administrative', 'Diagnosis Notes'],
            'a broken room is inspected' => ['Facilities', 'Inspection Notes'],
            'a dead lab PC is inspected' => ['Equipment', 'Inspection Notes'],
            'an injury is assessed' => ['Physical', 'Assessment Notes'],
            'a hazard is assessed' => ['Safety', 'Assessment Notes'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('categories')]
    public function test_the_label_matches_the_work(string $category, string $expected): void
    {
        $concern = new Concern(['category' => $category]);

        $this->assertSame($expected, $concern->handlingNoteLabel());
        $this->assertNotSame('', $concern->handlingNoteHint());

        fwrite(STDERR, "  [notes] {$category}: {$expected}\n");
    }

    /** Every category has one, so none falls back by accident. */
    public function test_every_category_is_covered(): void
    {
        foreach (array_keys(Concern::CATEGORY_LABELS) as $category) {
            $this->assertArrayHasKey($category, Concern::HANDLING_NOTE_LABELS, $category);
            $this->assertArrayHasKey($category, Concern::HANDLING_NOTE_HINTS, $category);
        }

        fwrite(STDERR, "  [notes] every category has a label and a hint\n");
    }

    /** The handler sees it on the form, and the field still has to be filled. */
    public function test_the_form_uses_it(): void
    {
        $staff = User::where('email', 'staff@cspc.edu.ph')->firstOrFail();

        $concern = Concern::create([
            'user_id' => User::where('email', 'student@my.cspc.edu.ph')->firstOrFail()->id,
            'category' => 'Facilities',
            'department' => 'College of Computer Studies',
            'description' => 'The aircon in Room 204 has not worked for two weeks.',
            'urgency' => 'Low',
            'status' => 'submitted',
            'is_anonymous' => false,
            'assigned_to' => $staff->id,
        ]);

        $this->actingAs($staff)->get("/concerns/{$concern->id}")->assertOk()
            ->assertSee('Inspection Notes *')
            ->assertDontSee('Investigation Notes');

        // Still required: this renamed the field, it did not relax it.
        $this->actingAs($staff)->patch("/concerns/{$concern->id}", [
            'investigation_notes' => '',
            'resolution_notes' => 'Recorded what is being done about it.',
            'status' => 'in_progress',
            'urgency' => 'Low',
        ])->assertSessionHasErrors('investigation_notes');

        fwrite(STDERR, "  [notes] the form uses the category's label, and still requires it\n");
    }
}
