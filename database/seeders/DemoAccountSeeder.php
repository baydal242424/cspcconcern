<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\Section;
use App\Models\User;
use Database\Seeders\Faculty\ProgrammeSectionSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Demo students for testing: one per programme, per year level.
 *
 * Students only. Staff demo accounts were removed: a demo account is
 * indistinguishable from a real one to the routing rules, so a demo dean or
 * demo instructor can win a live referral and quietly take a real student's
 * concern out of the queue of the person who should have it. A demo STUDENT
 * cannot do that -- students never receive anything.
 *
 * One per programme AND year, rather than one per programme, because both
 * decide who handles the concern. The programme picks the college and the
 * chair; the year picks the section, and the section picks the class adviser,
 * who takes Academic, Physical, Safety and Others ahead of anybody else. The
 * adviser of BSIS 1A is a different person from the adviser of BSIS 4A.
 *
 * A single first-year demo per programme hid exactly the gap that a real
 * student found: published adviser lists mostly cover first year, so 1A had an
 * adviser and 4A had none, and filing as the demo student never revealed it.
 *
 * Every address starts "demo." so the set can be found and removed together:
 *
 *     php artisan tinker --execute="App\Models\User::where('email','like','demo.%')->delete();"
 *
 * Deleting a student also deletes the concerns they filed, which for these is
 * what you want -- test data goes with the test account.
 *
 * Run ProgrammeSectionSeeder FIRST. It creates the sections these students are
 * placed in; without it they land in sections nobody advises, which is the
 * fallback path rather than the one worth demonstrating.
 *
 * Not called from DatabaseSeeder. Run it deliberately:
 *
 *     php artisan db:seed --class=Database\\Seeders\\DemoAccountSeeder
 */
class DemoAccountSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('Creating demo students on a PRODUCTION system. Remove them when you are done.');
        }

        $role = Role::where('name', 'Student')->first();

        if (! $role) {
            $this->command?->warn("The 'Student' role does not exist; nothing seeded.");

            return;
        }

        $created = 0;
        $withAdviser = 0;

        foreach (User::COURSES_BY_COLLEGE as $college => $courses) {
            foreach ($courses as $course) {
                foreach (ProgrammeSectionSeeder::YEARS as $year) {
                    $section = $year.'A';

                    User::updateOrCreate(
                        ['email' => $this->address($course, $year)],
                        [
                            // The full programme name and the section, not
                            // initials. Six programmes abbreviate to three
                            // collisions -- Civil and Computer Engineering
                            // both BSCE, Electrical and Electronics both
                            // BSEE, Mathematics and Midwifery both BSM -- and
                            // two identical names in a picker are worse than
                            // one long one.
                            'name' => 'Demo '.$course.' '.$section,
                            'password' => Hash::make(Str::random(40)),
                            'role_id' => $role->id,
                            'department' => $college,
                            'course' => $course,
                            'section' => $section,
                            'student_id' => '2026-DEMO-'.str_pad((string) (++$created), 3, '0', STR_PAD_LEFT),
                            'status' => 'approved',
                            // Without this the auth middleware turns the
                            // account away before any page renders. Nobody
                            // signs in as these -- sign-in is Google-only.
                            'email_verified_at' => now(),
                        ]
                    );

                    if (Section::adviserFor($course, $section)) {
                        $withAdviser++;
                    }
                }
            }
        }

        $this->command?->info("Demo students: {$created} across "
            .count(User::COURSES_BY_COLLEGE).' colleges, '
            .count(ProgrammeSectionSeeder::YEARS).' year levels each.');
        $this->command?->info("{$withAdviser} of them are in a section with a named class adviser.");

        if ($withAdviser < $created) {
            $this->command?->warn('The rest will demonstrate college-level fallback routing, not adviser routing. '
                .'Run ProgrammeSectionSeeder to give every year level an adviser.');
        }
    }

    /**
     * Deterministic, so re-running updates the same accounts instead of
     * creating a second set beside them.
     */
    private function address(string $course, int $year): string
    {
        return 'demo.'.Str::slug($course).'.'.$year.'a@my.cspc.edu.ph';
    }
}
