<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * ALL ELEVEN ROSTERS, not the two this used to call.
     *
     * The faculty seeders were written one college at a time and only the
     * first two were ever wired in here. On a developer's machine that went
     * unnoticed, because the others had been run by hand at some point and
     * the rows stayed. On a freshly deployed site it showed: four Program
     * Chairs instead of twenty-eight, no College of Health Sciences at all,
     * and a routing table pointing at colleges with nobody in them.
     *
     * A concern reaches the chair of the student's own programme. A
     * programme with no chair seeded does not fall back to the right
     * person -- it falls back to whoever sorts first, in a college with
     * nothing to do with it.
     *
     * Every one of these is idempotent, so running this again changes
     * nothing that is already right.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,

            // Office mailboxes and the roles that receive work.
            UserSeeder::class,

            // Real named people, after the roles and office mailboxes they
            // depend on. Kept in their own namespace so a person joining or
            // leaving is an edit to one file, not to the office roster.
            \Database\Seeders\Faculty\CcsFacultySeeder::class,
            \Database\Seeders\Faculty\CcsSupportStaffSeeder::class,
            \Database\Seeders\Faculty\CeaFacultySeeder::class,
            \Database\Seeders\Faculty\CeaFullFacultySeeder::class,
            \Database\Seeders\Faculty\CasFacultySeeder::class,
            \Database\Seeders\Faculty\ChsFacultySeeder::class,
            \Database\Seeders\Faculty\CtdeFacultySeeder::class,
            \Database\Seeders\Faculty\CthbmFacultySeeder::class,

            // Real names, placeholder addresses, for the colleges whose
            // published lists carry no mailboxes. Last of the people, so a
            // real address already seeded is never replaced by a stand-in.
            \Database\Seeders\Faculty\PlaceholderFacultySeeder::class,

            // Classes, and who advises them. After the faculty, because an
            // adviser is one of them: a section seeded first would have
            // nobody to point at.
            \Database\Seeders\Faculty\ProgrammeSectionSeeder::class,
            \Database\Seeders\Faculty\CcsSectionAdviserSeeder::class,
        ]);
    }
}
