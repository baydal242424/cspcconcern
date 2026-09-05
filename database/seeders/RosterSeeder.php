<?php

namespace Database\Seeders;

use Database\Seeders\Faculty\CasFacultySeeder;
use Database\Seeders\Faculty\CcsFacultySeeder;
use Database\Seeders\Faculty\CcsSectionAdviserSeeder;
use Database\Seeders\Faculty\CcsSupportStaffSeeder;
use Database\Seeders\Faculty\CeaFacultySeeder;
use Database\Seeders\Faculty\CeaFullFacultySeeder;
use Database\Seeders\Faculty\ChsFacultySeeder;
use Database\Seeders\Faculty\CtdeFacultySeeder;
use Database\Seeders\Faculty\CthbmFacultySeeder;
use Database\Seeders\Faculty\PlaceholderFacultySeeder;
use Database\Seeders\Faculty\ProgrammeSectionSeeder;
use Illuminate\Database\Seeder;

/**
 * The whole CSPC roster, in the order it has to be built.
 *
 * Migrations run on deploy; seeders never do. So a freshly deployed copy has
 * the schema and almost nobody in it -- and an empty roster is not an empty
 * app, it is a broken one: every concern falls through routing to whoever
 * happens to sort first, and the filing form tells students no adviser is
 * recorded for their section.
 *
 * The order matters, which is the reason this exists rather than a list of ten
 * commands pasted one at a time into a hosting panel:
 *
 *   1. roles       everything else looks up a role by name.
 *   2. officials   the deans, VPAA and Head of School the colleges publish.
 *   3. faculty     each college's instructors and chairs.
 *   4. advisers    CCS's published class lists, which name real people.
 *   5. sections    a starter class per programme for the colleges that
 *                  publish none -- and this MUST be last, because it picks
 *                  advisers from the faculty the steps above create. Run
 *                  early it finds nobody and silently does nothing.
 *
 * Every step is firstOrCreate-based, so running this on a database that
 * already holds some of it changes nothing. It is safe to run twice.
 *
 *     php artisan db:seed --class=Database\\Seeders\\RosterSeeder --force
 */
class RosterSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            UserSeeder::class,

            CcsFacultySeeder::class,
            CeaFacultySeeder::class,
            CeaFullFacultySeeder::class,
            ChsFacultySeeder::class,
            CthbmFacultySeeder::class,
            CtdeFacultySeeder::class,
            CasFacultySeeder::class,
            CcsSupportStaffSeeder::class,
            PlaceholderFacultySeeder::class,

            // CCS publishes real class advisers; those rows win over the
            // starter sections below, which only fill what nobody has named.
            CcsSectionAdviserSeeder::class,

            ProgrammeSectionSeeder::class,
        ]);

        $this->command?->newLine();
        $this->command?->info('Roster seeded. Check it landed:');
        $this->command?->line('  php artisan tinker --execute="echo App\Models\User::count().\' users, \'.App\Models\Section::count().\' sections\';"');
    }
}
