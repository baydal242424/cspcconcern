<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\Section;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * One demo student per programme, for demonstrating and testing routing.
 *
 * Students only. Staff demo accounts were removed: a demo account is
 * indistinguishable from a real one to the routing rules, so a demo dean or
 * demo instructor can win a live referral and quietly take a real student's
 * concern out of the queue of the person who should have it. A demo STUDENT
 * cannot do that -- students never receive anything.
 *
 * One per programme rather than one overall, because routing is decided by the
 * reporter's college and programme. Filing as the BSIT demo student and the
 * BS Nursing demo student exercises two entirely different paths: different
 * college, different instructors, different chair.
 *
 * Where a programme has sections with advisers recorded, the demo student is
 * put in one of them, so filing an Academic concern demonstrates the whole
 * chain -- student, section, class adviser -- rather than falling back to
 * college level.
 *
 * Every address starts "demo." so the set can be found and removed together:
 *
 *     php artisan tinker --execute="App\Models\User::where('email','like','demo.%')->delete();"
 *
 * Deleting a student also deletes the concerns they filed, which for these is
 * what you want -- test data goes with the test account.
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
        $withSection = 0;

        foreach (User::COURSES_BY_COLLEGE as $college => $courses) {
            foreach ($courses as $course) {
                $section = $this->sectionFor($course);

                $user = User::updateOrCreate(
                    ['email' => $this->address($course)],
                    [
                        // The full programme name, not initials. Six
                        // programmes abbreviate to three collisions --
                        // Civil and Computer Engineering both BSCE,
                        // Electrical and Electronics both BSEE,
                        // Mathematics and Midwifery both BSM -- and two
                        // identical names in a picker are worse than a
                        // long one.
                        'name' => 'Demo '.$course,
                        'password' => Hash::make(Str::random(40)),
                        'role_id' => $role->id,
                        'department' => $college,
                        'course' => $course,
                        'section' => $section,
                        'student_id' => '2026-DEMO-'.str_pad((string) (++$created), 3, '0', STR_PAD_LEFT),
                        'status' => 'approved',
                        // Without this the auth middleware turns the account
                        // away before any page renders. Nobody signs in as
                        // these -- sign-in is Google-only.
                        'email_verified_at' => now(),
                    ]
                );

                if ($section) {
                    $withSection++;
                }

                unset($user);
            }
        }

        $this->command?->info("Demo students: {$created} across ".count(User::COURSES_BY_COLLEGE).' colleges.');
        $this->command?->info("{$withSection} are in a section with a named class adviser.");
    }

    /**
     * A section of this programme that actually has an adviser, so filing as
     * this student demonstrates adviser routing rather than the fallback.
     */
    private function sectionFor(string $course): ?string
    {
        return Section::where('course', $course)
            ->whereNotNull('adviser_id')
            ->orderByDesc('school_year')
            ->orderByDesc('semester')
            ->value('section');
    }

    private function address(string $course): string
    {
        return 'demo.'.Str::slug($course).'@my.cspc.edu.ph';
    }
}
