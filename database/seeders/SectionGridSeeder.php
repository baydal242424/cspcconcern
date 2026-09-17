<?php

namespace Database\Seeders;

use App\Models\Section;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Every programme gets sections 1A-1E, 2A-2E, 3A-3E and 4A-4E.
 *
 * The section list was whatever the published adviser lists happened to name:
 * BSIT had 1A-1E and 2A-2H, while most programmes had only an "A" section per
 * year. A student in BS Nursing 1B was therefore in a section the system had
 * never heard of, which is the same to routing as having no section at all --
 * their concerns fell past the adviser tier to whichever instructor of the
 * college sorted first.
 *
 * The rows are created with NO adviser. That is deliberate and it changes
 * nothing on its own: Section::adviserFor() skips a row with no adviser
 * exactly as it skips a missing one. What it gives is a list of the sections
 * that exist and a place to record who advises each, so the gaps are visible
 * instead of being discovered by a student filing a concern.
 *
 * Existing rows are left alone, advisers included -- this fills holes, it does
 * not rewrite what a college has already published. Sections outside the grid
 * (BSIT 2F-2H, 3F-3H, 4F) stay where they are.
 *
 * Not called from DatabaseSeeder. Run it deliberately:
 *
 *     php artisan db:seed --class=Database\\Seeders\\SectionGridSeeder
 */
class SectionGridSeeder extends Seeder
{
    /** Five classes per year level: A, B, C, D, E. */
    private const LETTERS = ['A', 'B', 'C', 'D', 'E'];

    /** First to fourth year. Architecture's fifth is not part of the grid. */
    private const YEARS = [1, 2, 3, 4];

    public function run(): void
    {
        // The term the newest records use, so these sit alongside them rather
        // than in a term of their own that adviserFor() would then prefer.
        $term = Section::orderByDesc('school_year')->orderByDesc('semester')->first();

        if (! $term) {
            $this->command?->warn('No sections exist yet, so there is no term to add to. Run ProgrammeSectionSeeder first.');

            return;
        }

        $created = 0;
        $kept = 0;

        foreach (User::allCourses() as $course) {
            foreach (self::YEARS as $year) {
                foreach (self::LETTERS as $letter) {
                    $section = $year.$letter;

                    $row = Section::firstOrCreate(
                        [
                            'course' => $course,
                            'section' => $section,
                            'school_year' => $term->school_year,
                            'semester' => $term->semester,
                        ],
                        // Nobody yet. Named by an administrator, or by the
                        // seeder that carries a college's published list.
                        ['adviser_id' => null]
                    );

                    $row->wasRecentlyCreated ? $created++ : $kept++;
                }
            }
        }

        $withAdviser = Section::whereNotNull('adviser_id')->count();
        $total = Section::count();

        $this->command?->info("Sections added: {$created}. Already on record and left as they were: {$kept}.");
        $this->command?->info("{$total} sections in total, {$withAdviser} of them with a named adviser.");

        if ($total > $withAdviser) {
            $this->command?->warn(($total - $withAdviser).' sections have no adviser. Concerns from those sections '
                .'go to an instructor of the college until somebody is named.');
        }
    }
}
