<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * A Program Chair for every programme of a college that has none on record.
 *
 * Engineering and Architecture and Health Sciences published no chairs, so a
 * concern escalating past the class adviser found no chair of the student's
 * own college -- and findHandler()'s last tier, "anybody in the role", handed
 * a BS Nursing case to a Computer Studies chair. Routing now climbs to the
 * dean instead of sideways, but a dean is two tiers up: these rows put the
 * missing tier back.
 *
 * Named for the post, not for a person: "BS Nursing Program Chair". Nobody's
 * name is invented, and the row reads as an office waiting for its holder.
 * Addresses are on placeholder.cspc.edu.ph, the convention this project
 * already uses (see Faculty\PlaceholderFacultySeeder) -- a subdomain that does
 * not exist, so mail to it fails loudly rather than reaching a stranger.
 *
 * Only colleges with NO chair of their own are filled. Arts and Sciences was
 * briefly filled too -- six chairs whose programmes were never recorded, six
 * programmes with no chair -- and the result was twelve chairs in one college,
 * where the six office accounts outranked six real people in routing. One
 * chair per programme is the goal; two sets of chairs is not the way to it.
 *
 * Where a college's chairs have no programme recorded, set it on their own
 * accounts from Manage Users (the Program field). That is the same fix with
 * the real name on it.
 *
 * When the real chair is known: set their programme on their own account and
 * delete the placeholder row here.
 *
 * Not called from DatabaseSeeder. Run it deliberately:
 *
 *     php artisan db:seed --class=Database\\Seeders\\PlaceholderProgramChairSeeder
 */
class PlaceholderProgramChairSeeder extends Seeder
{
    private const DOMAIN = '@placeholder.cspc.edu.ph';

    public function run(): void
    {
        $role = Role::where('name', 'Program Chair')->first();

        if (! $role) {
            $this->command?->warn("The 'Program Chair' role does not exist; nothing seeded.");

            return;
        }

        $created = 0;

        foreach (User::COURSES_BY_COLLEGE as $college => $courses) {
            // A college that lists chairs of its own is left alone, even where
            // those chairs have no programme recorded. Filling programme gaps
            // there means putting an office account beside real people and
            // outranking them in routing -- the college's own chairs stop
            // receiving their students' escalations under their own names.
            // Record the real mapping on those accounts instead, from Manage
            // Users, and this seeder stays out of the way.
            //
            // Only the rows THIS seeder makes are discounted, by the address it
            // gives them: most seeded faculty are on the same placeholder
            // domain, so an address test alone read three staffed colleges as
            // empty.
            $collegeHasChairs = User::whereHas('role', fn ($q) => $q->where('name', 'Program Chair'))
                ->where('department', $college)
                ->where('email', 'not like', 'chair.%'.self::DOMAIN)
                ->exists();

            if ($collegeHasChairs) {
                $this->command?->line("  {$college}: chairs already on record, left alone.");

                continue;
            }

            foreach ($courses as $course) {
                User::updateOrCreate(
                    ['email' => 'chair.'.Str::slug($course).self::DOMAIN],
                    [
                        'name' => $course.' Program Chair',
                        'password' => Hash::make(Str::random(40)),
                        'role_id' => $role->id,
                        'department' => $college,
                        // The programme is the point of the row: it is what
                        // findHandler() matches on before anything else.
                        'course' => $course,
                        'section' => null,
                        'status' => 'approved',
                        'email_verified_at' => now(),
                    ]
                );

                $created++;
                $this->command?->line("    {$course} Program Chair");
            }
        }

        $this->command?->info("Placeholder chairs: {$created}.");

        $missing = collect(User::allCourses())->reject(
            fn ($course) => User::whereHas('role', fn ($q) => $q->where('name', 'Program Chair'))
                ->where('course', $course)
                ->exists()
        );

        if ($missing->isNotEmpty()) {
            $this->command?->warn($missing->count().' programmes still have no chair of their own: '
                .$missing->implode(', '));
        }
    }
}
