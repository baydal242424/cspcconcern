<?php

namespace Tests\Feature;

use App\Http\Controllers\ConcernController;
use App\Models\Concern;
use Tests\TestCase;

/**
 * The filing form promises the student who will read their concern, before
 * they write it. This checks that promise is still true.
 *
 * The form carries its own routingByCategory map in JavaScript, so the note
 * can update as the student changes the dropdown without a round trip. Being
 * a second copy of the routing table, it drifts -- twice now. Most recently
 * Academic, Physical, Safety and Others went on reading "This will be routed
 * to an instructor in your college" after routing had moved to the student's
 * class adviser, a tier above the instructor. Nothing failed; the sentence was
 * simply a lie, and the only way to notice was to read it.
 *
 * A wrong destination is worse than none. A student deciding whether to report
 * a teacher is weighing exactly one thing -- who sees this -- and the form was
 * naming the wrong office.
 *
 * So this reads the strings out of the Blade file and holds them against
 * ConcernController::CATEGORY_ROUTING. It deliberately matches on a word
 * rather than an exact sentence: the wording is meant to stay plain English
 * for students ("your class adviser", not "the Adviser role"), and pinning the
 * phrasing would only teach people to update the test to match the mistake.
 */
class CategoryRoutingHelperTest extends TestCase
{
    /**
     * A word that must appear in the student-facing note for each handling
     * role. Anything else is drift.
     *
     * @var array<string, string>
     */
    private const EXPECTED_WORD = [
        'Adviser' => 'adviser',
        'Guidance Counselor' => 'Guidance',
        'Admin' => 'Administration',
        'General Services' => 'General Services',
    ];

    public function test_the_form_names_the_office_that_actually_receives_each_category(): void
    {
        $form = $this->routingMapFromForm();

        foreach (Concern::CATEGORIES as $category) {
            $this->assertArrayHasKey(
                $category,
                $form,
                "The filing form says nothing about where a {$category} concern goes."
            );

            $role = ConcernController::CATEGORY_ROUTING[$category] ?? null;

            $this->assertNotNull(
                $role,
                "{$category} is offered to students but has no entry in CATEGORY_ROUTING."
            );

            $word = self::EXPECTED_WORD[$role]
                ?? $this->fail("No student-facing wording is defined for the {$role} role.");

            $this->assertStringContainsStringIgnoringCase(
                $word,
                $form[$category],
                "{$category} routes to {$role}, but the form tells the student it goes to "
                ."\"{$form[$category]}\"."
            );
        }
    }

    /**
     * Every category a student can pick must be described. An unlisted one
     * shows the dropdown with no note at all, which is how "Others" behaved
     * before it was given one.
     */
    public function test_the_form_describes_every_category_it_offers(): void
    {
        $missing = array_diff(array_keys($this->routingMapFromForm()), Concern::CATEGORIES);

        $this->assertSame(
            [],
            array_values($missing),
            'The filing form routes categories that no longer exist: '.implode(', ', $missing)
        );
    }

    /**
     * Pulls routingByCategory out of the Blade template.
     *
     * Reading the file rather than rendering the page: the map is inert
     * JavaScript, and parsing it here keeps the check independent of who is
     * logged in and of every other thing that view needs to render.
     *
     * @return array<string, string>
     */
    private function routingMapFromForm(): array
    {
        $blade = resource_path('views/concerns/create.blade.php');

        $this->assertFileExists($blade);

        $source = file_get_contents($blade);

        $found = preg_match(
            '/const\s+routingByCategory\s*=\s*\{(.*?)\};/s',
            $source,
            $block
        );

        $this->assertSame(
            1,
            $found,
            'routingByCategory is no longer in create.blade.php. If the helper text moved, '
            .'point this test at its new home rather than deleting it.'
        );

        preg_match_all("/'([^']+)'\s*:\s*'([^']*)'/", $block[1], $pairs, PREG_SET_ORDER);

        return collect($pairs)->mapWithKeys(fn ($p) => [$p[1] => $p[2]])->all();
    }
}
