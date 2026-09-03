<?php

namespace Database\Seeders\Faculty;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The College of Arts and Sciences, from its published organisational
 * structure, with each person's role taken from the line under their name.
 *
 *   Program Chair   the programme chairpersons
 *   Instructor      teaching faculty, whatever their academic rank
 *   Faculty/Staff   the dean's office personnel -- referral-gated, because
 *                   Instructor receives Academic, Physical, Safety and Others
 *                   automatically and an executive assistant has no business
 *                   being handed a student's grade dispute
 *
 * Academic rank (Instructor I, Associate Professor III) is not a role here.
 * The system's roles describe what somebody may READ and what routing sends
 * them; rank describes seniority and pay. An Associate Professor V and an
 * Instructor I handle a student's concern identically.
 *
 * Already accounted for and left out: Dr. Marlon S. Pontillas is the CAS dean
 * under cas@cspc.edu.ph, and Dr. Amado A. Oliva Jr. and Dr. Jocelyn O.
 * Jintalan sit above the college.
 *
 * Not called from DatabaseSeeder. Run it deliberately:
 *
 *     php artisan db:seed --class=Database\\Seeders\\Faculty\\CasFacultySeeder
 */
class CasFacultySeeder extends Seeder
{
    private const DOMAIN = '@placeholder.cspc.edu.ph';

    private const COLLEGE = 'College of Arts and Sciences';

    /**
     * [name, programme]
     *
     * Programmes are left null. The chart's chairs cover Development
     * Communication, Human Services, AB English, Public Administration,
     * Mathematics and Applied Mathematics, and none of those strings are in
     * User::COURSES_BY_COLLEGE for this college -- a chair pinned to a
     * programme no student's profile stores matches nobody, and the referral
     * falls back to college level looking as though it worked. College-level
     * routing reaches them correctly; add the programmes to COURSES_BY_COLLEGE
     * first if programme-level referral is wanted here.
     */
    private const CHAIRS = [
        ['Rosanova B. Oliveros', null],
        ['Ma. Francia S. Dechavez', null],
        ['Dr. Janessa Angustia M. Malaya', null],
        ['Renato A. Adriano III', null],
        ['Alex Ralph B. Nieva', null],
        ['Joel Mark D. Jasmes', null],
        ['Rowel S. Ramos', null],
    ];

    /** Teaching faculty, grouped as the chart groups them. */
    private const FACULTY = [
        // Development Communication
        'Filmor J. Murillo',

        // Human Services
        'Leny O. Figuracion',
        'Dr. Zandra Bonnie V. Salcedo',
        'Edylene B. Arines',
        'Patricia Marielle R. Estrella',

        // AB English
        'Dr. Dan Pereth R. Fajardo',
        'Nicky Gem M. Rivera',
        'Dr. Nel Michael B. Buena',
        'Jayvee M. Layson',
        'Herbert John N. Nachor',
        'Audrey Millicent S. Hugo',
        'Kevin Sean D. Rada',

        // Public Administration
        'Pedro R. Turiano',
        'Al Lexus P. Arevalo',

        // Mathematics and Applied Mathematics
        'Liezl B. Namoro',
        'Axel M. Gayondato',

        // General Education
        'Ma. Luzelyn B. Agarito',
        'Atty. Freddie B. Collada',
        'Bomer P. Beltran-Yu',
        'Dr. Maria Teresa V. Septimo',
        'Dr. Marietta A. Tataro',
        'Joseph D. Illo',
        'Francia J. Babay',
        'Elbert O. Baeta',
    ];

    /** Dean's office personnel. */
    private const SUPPORT = [
        'Kaila Mae N. Sergio-Salazar',
        'Kristyl Vine D. Gascon',
    ];

    /*
     * NOT SEEDED -- names I could not read reliably from the chart.
     *
     * A misspelled name is worse than a missing one. The duplicate check
     * matches on first name and surname, so a wrong spelling would fail to
     * recognise the real person later: they would sign in, get a second
     * account, and this row would stay in the picker reaching nobody.
     *
     * From the Dean's Office row, and the faculty groups:
     *   - the Performance Management and Documentation officer
     *   - the Secretary II
     *   - one DEVCOM instructor
     *   - two Human Services instructors
     *   - one Public Administration instructor
     *   - one Mathematics instructor
     *   - two General Education instructors
     *
     * Send a clearer image or the names as text and they go in.
     *
     * ALSO WORTH CHECKING, both already in the database under another college:
     *
     *   GIGI V. SEVERO appears here as AB English faculty. She holds
     *   gad@cspc.edu.ph as head of the Center for Gender and Development --
     *   the office that receives referred harassment cases. She is skipped, so
     *   that account is untouched. If she teaches as well, her college decides
     *   whose academic concerns reach her, and GAD referrals do not depend on
     *   it either way.
     *
     *   DAISYLEN D. ALANO appears here as General Education faculty and on the
     *   CEA list, and directs Extension and Community Services per
     *   cspc.edu.ph. Skipped for the same reason. One person, one account --
     *   the college on it decides where routing sends her.
     */

    public function run(): void
    {
        $created = 0;
        $skipped = 0;

        foreach (self::CHAIRS as [$name, $course]) {
            $this->place($name, 'Program Chair', $course, $created, $skipped);
        }

        foreach (self::FACULTY as $name) {
            $this->place($name, 'Instructor', null, $created, $skipped);
        }

        foreach (self::SUPPORT as $name) {
            $this->place($name, 'Faculty/Staff', null, $created, $skipped);
        }

        $this->command?->info("CAS: {$created} seeded, {$skipped} skipped as already present.");
    }

    private function place(string $name, string $roleName, ?string $course, int &$created, int &$skipped): void
    {
        $role = Role::where('name', $roleName)->first();

        if (! $role) {
            $this->command?->warn("Skipped {$name}: the '{$roleName}' role does not exist.");

            return;
        }

        if ($this->alreadyPresent($name)) {
            $this->command?->warn("Skipped {$name}: an account for that name already exists.");
            $skipped++;

            return;
        }

        User::firstOrCreate(
            ['email' => $this->placeholderAddress($name)],
            [
                'name' => $name,
                'password' => Hash::make(Str::random(40)),
                'role_id' => $role->id,
                'department' => self::COLLEGE,
                'course' => $course,
                'status' => 'approved',
                'email_verified_at' => now(),
            ]
        );

        $created++;
    }

    private function alreadyPresent(string $name): bool
    {
        $parts = $this->nameParts($name);

        if (count($parts) < 2) {
            return false;
        }

        return User::where('name', 'like', '%'.$parts[0].'%')
            ->where('name', 'like', '%'.end($parts).'%')
            ->where('email', 'not like', '%'.self::DOMAIN)
            ->exists();
    }

    private function nameParts(string $name): array
    {
        return array_values(array_filter(
            array_map(fn ($p) => rtrim($p, '.'), explode(' ', $name)),
            fn ($p) => strlen($p) > 1
                && ! in_array($p, ['Mr', 'Ms', 'Mrs', 'Dr', 'Engr', 'Atty', 'Jr', 'Sr', 'II', 'III'], true)
        ));
    }

    private function placeholderAddress(string $name): string
    {
        $parts = $this->nameParts($name);
        $base = Str::slug($parts[0] ?? 'staff').'.'.Str::slug((string) end($parts));

        $candidate = $base.self::DOMAIN;

        if (! $this->addressTaken($candidate, $name)) {
            return $candidate;
        }

        foreach (array_slice($parts, 1, -1) as $middle) {
            $candidate = $base.'.'.Str::slug($middle).self::DOMAIN;

            if (! $this->addressTaken($candidate, $name)) {
                return $candidate;
            }
        }

        for ($n = 2; $n < 50; $n++) {
            $candidate = $base.$n.self::DOMAIN;

            if (! $this->addressTaken($candidate, $name)) {
                return $candidate;
            }
        }

        return $base.'.'.Str::random(6).self::DOMAIN;
    }

    private function addressTaken(string $email, string $name): bool
    {
        return User::where('email', $email)->where('name', '!=', $name)->exists();
    }
}
