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
     * The programmes come from the college's own faculty roster
     * (cspc.edu.ph/academics/cas/faculty-roster-2), which names a chair per
     * programme -- the organisational chart this list was first built from
     * named the chairs without saying what each one chairs, so every CAS
     * programme escalated to whichever chair sorted first.
     *
     * The roster writes two of them differently from the way the system does:
     * "AB English Language Studies" is BA English Language Studies here, and
     * "Bachelor in Public Administration" is BS Public Administration. The
     * system's spelling is what students' profiles store, so that is what
     * routing has to match.
     *
     * Two names the chart called chairs are instructors on the roster -- Dr.
     * Janessa Angustia M. Malaya (Instructor II) and Rowel S. Ramos
     * (Instructor III) -- so they are seeded under FACULTY below instead.
     *
     * Development Communication is chaired by Gigi V. Severo, who is seeded
     * elsewhere as the Center for Gender and Development. An account carries
     * one role and one office, and GAD is the one that receives referrals, so
     * she is left there and the programme has no chair of its own.
     */
    private const CHAIRS = [
        ['Rosanova B. Oliveros', null],
        ['Ma. Francia S. Dechavez', 'Bachelor in Human Services'],
        ['Renato A. Adriano III', 'BS Public Administration'],
        ['Alex Ralph B. Nieva', 'BS Mathematics'],
        // The roster spells this "Jiel Mark D. Jagmis"; the chart spelled it
        // as below, and it is the same chair of Applied Mathematics.
        ['Joel Mark D. Jasmes', 'BS Applied Mathematics'],
        // Listed under teaching faculty until the roster showed the chair.
        // The roster spells the middle name "Fereth".
        ['Dr. Dan Pereth R. Fajardo', 'BA English Language Studies'],
    ];

    /** Teaching faculty, grouped as the chart groups them. */
    private const FACULTY = [
        // Chairs on the old organisational chart, instructors on the college's
        // own roster: Instructor II and Instructor III respectively, neither
        // holding a programme. Seeding them as chairs gave Arts and Sciences
        // two chairs more than it has programmes, and put two people in the
        // escalation tier above their own.
        'Dr. Janessa Angustia M. Malaya',
        'Rowel S. Ramos',

        // Development Communication
        'Filmor J. Murillo',

        // Human Services
        'Leny O. Figuracion',
        'Dr. Zandra Bonnie V. Salcedo',
        'Edylene B. Arines',
        'Patricia Marielle R. Estrella',

        // AB English
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
