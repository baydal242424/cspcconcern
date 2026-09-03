<?php

namespace Database\Seeders\Faculty;

use App\Models\Role;
use App\Models\Section;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * A starter section for every programme that has none, advised by a real
 * member of that college's faculty.
 *
 * Academic, Physical, Safety and Others reach a student's class adviser first.
 * Only Computer Studies has published section lists, so students everywhere
 * else fell through to the college-level fallback -- and that fallback picks
 * the first eligible instructor by id, which means one person per college was
 * receiving every concern from it while their colleagues sat idle. In Health
 * Sciences that was one instructor out of a hundred and seventy.
 *
 * This gives each remaining programme a section 1A with a named adviser, so
 * the tier exists and concerns spread by programme instead of piling onto
 * whoever happens to sort first.
 *
 * Advisers are REAL faculty of the college, not demo accounts. A demo adviser
 * would receive live academic concerns ahead of every real instructor and
 * could not sign in to act on them -- the same failure that had a demo
 * instructor holding a real concern before those accounts were removed.
 *
 * These are placeholders for the colleges' own assignments, not a substitute.
 * When a college publishes its class advisers, seed them the way CCS is seeded
 * and they supersede this: the lookup takes the most recent school year and
 * semester, so a real list simply wins.
 *
 * Not called from DatabaseSeeder. Run it deliberately:
 *
 *     php artisan db:seed --class=Database\\Seeders\\Faculty\\ProgrammeSectionSeeder
 */
class ProgrammeSectionSeeder extends Seeder
{
    private const YEAR = '2024-2025';

    private const SEMESTER = 'Second';

    public function run(): void
    {
        $instructor = Role::where('name', 'Instructor')->value('id');

        if (! $instructor) {
            $this->command?->warn("The 'Instructor' role does not exist; nothing seeded.");

            return;
        }

        $sections = 0;
        $students = 0;

        foreach (User::COURSES_BY_COLLEGE as $college => $courses) {
            // Faculty of this college, in a stable order, so each programme
            // gets a different adviser rather than all of them sharing one.
            $faculty = User::where('role_id', $instructor)
                ->where('department', $college)
                ->where('status', 'approved')
                ->orderBy('name')
                ->get();

            if ($faculty->isEmpty()) {
                $this->command?->warn("Skipped {$college}: no instructors to advise its sections.");
                continue;
            }

            foreach (array_values($courses) as $i => $course) {
                // Already advised -- a published list, or a previous run.
                if (Section::where('course', $course)->whereNotNull('adviser_id')->exists()) {
                    continue;
                }

                $adviser = $faculty[$i % $faculty->count()];

                Section::updateOrCreate(
                    [
                        'course' => $course,
                        'section' => '1A',
                        'school_year' => self::YEAR,
                        'semester' => self::SEMESTER,
                    ],
                    ['adviser_id' => $adviser->id]
                );

                $sections++;

                // Put this programme's demo student in the section, so filing
                // as them demonstrates the whole chain rather than the
                // fallback. Real students set their own section on the profile
                // form and are left alone.
                $students += User::where('course', $course)
                    ->where('email', 'like', 'demo.%')
                    ->whereNull('section')
                    ->update(['section' => '1A']);
            }
        }

        $this->command?->info("Programme sections: {$sections} created, {$students} demo students placed in one.");
        $this->command?->info('A college publishing its own adviser list supersedes these -- the newest term wins.');
    }
}
