<?php

namespace Database\Seeders\Faculty;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The College of Tourism, Hospitality and Business Management, plus the two
 * institution-level officers its organisational chart names above the dean.
 *
 * Taken from the college's published organisational structure. No email
 * addresses appear on it, so every account here is created under
 * @placeholder.cspc.edu.ph -- a subdomain that does not exist. Replace each
 * with the real address as it is confirmed:
 *
 *     php artisan faculty:email "Brioso" heraddress@cspc.edu.ph
 *
 * That updates the existing row, so the person's role, college, programme,
 * concerns and audit history stay with them. Creating a new account instead
 * would strand everything already routed to the placeholder.
 *
 * Dr. Maria Joy C. Iglesia is deliberately absent: she is the CTHBM dean and
 * already holds cthbm@cspc.edu.ph. The chart also lists her as Assistant
 * Professor II, but a second row would split her concerns across two accounts.
 *
 * Not called from DatabaseSeeder. Run it deliberately:
 *
 *     php artisan db:seed --class=Database\\Seeders\\Faculty\\CthbmFacultySeeder
 */
class CthbmFacultySeeder extends Seeder
{
    private const DOMAIN = '@placeholder.cspc.edu.ph';

    private const COLLEGE = 'College of Tourism, Hospitality and Business Management';

    /**
     * Two officers the chart places above the college.
     *
     * They fill tiers that had nobody but a demo account. The Head of School
     * is the only role that can perform a break-glass identity reveal, and the
     * VPAA is where a complaint about an administrator goes -- without a
     * holder it falls to a dean who has no standing over one.
     *
     * Their department is their office, not a college, which keeps them out of
     * college-level routing: neither should receive a student's academic
     * concern simply for existing.
     *
     * [name, role, office]
     */
    private const OFFICERS = [
        ['Dr. Amado A. Oliva Jr.', 'Head of School', 'Office of the President'],
        ['Dr. Jocelyn O. Jintalan', 'Vice President for Academic Affairs', 'Office of the Vice President for Academic Affairs'],
    ];

    /**
     * [name, role, programme]
     *
     * The programme is set only for a Program Chair, and must match
     * User::COURSES_BY_COLLEGE exactly -- a chair recorded under a name no
     * student's profile stores matches nobody, and the referral quietly falls
     * back to college level looking as though it worked.
     */
    private const FACULTY = [
        // ---- Programme chairs ----
        ['Roque B. Cruz II', 'Program Chair', 'BS Business Administration major in Financial Management'],
        ['Dr. Niño Martin P. Obrero', 'Program Chair', 'BS Entrepreneurship'],
        ['Dr. Rosanna B. Oliveros', 'Program Chair', 'BS Hospitality Management'],
        ['Syrell M. Hallare', 'Program Chair', 'BS Office Administration'],
        ['Jessa C. Brioso', 'Program Chair', 'BS Tourism Management'],

        // ---- Programme staff-in-charge ----
        ['Mae S. Interior', 'Instructor', null],
        ['Shiela Mae S. Zuñiga', 'Instructor', null],
        ['Nicole P. Relevante', 'Instructor', null],
        ['Vilma R. Picardal', 'Instructor', null],
        ['Judy Ann B. Hizon', 'Instructor', null],

        // ---- Teaching faculty ----
        ['Christine Margoux M. Sirios', 'Instructor', null],
        ['Emjay R. Cervas', 'Instructor', null],
        ['Dr. Crezel B. Obrero', 'Instructor', null],
        ['Dr. Lalaine M. Lastrollo', 'Instructor', null],
        ['Ma. Anjelica N. Ampongan', 'Instructor', null],
        ['Cherry Lyn M. Odsinada', 'Instructor', null],
        ['Dr. Norel Peter M. Illo', 'Instructor', null],
        ['Dr. Rosalie B. Bulalacao', 'Instructor', null],
        ['Mark Anthony L. Orobia', 'Instructor', null],
        ['Dr. Maruja C. Carilo', 'Instructor', null],
        ['Gilma P. Veras', 'Instructor', null],
        ['Jairo M. Repatacodo', 'Instructor', null],
        ['Dr. Teresita B. Salazar', 'Instructor', null],
        ['Alyssa D. Lagatic', 'Instructor', null],
        ['Maria Leticia C. Barela', 'Instructor', null],
        ['Genevie A. Estrebello', 'Instructor', null],
        ['Emilda E. Escolano', 'Instructor', null],
        ['Ailen G. Ala', 'Instructor', null],
        ['Jessica C. Marpuri', 'Instructor', null],
        ['Melany C. Federis', 'Instructor', null],
        ['Jessibel J. Escuro', 'Instructor', null],
        ['Dr. Maria Luisa O. Sotero', 'Instructor', null],
        ['Dr. Lynn C. Villar', 'Instructor', null],
        ['John Mykelle H. Lumabe', 'Instructor', null],
        ['Rochelle M. De Villa', 'Instructor', null],
        ['Dr. Mary Jane T. Bernales', 'Instructor', null],
    ];

    public function run(): void
    {
        $created = 0;
        $skipped = 0;

        foreach (self::OFFICERS as [$name, $roleName, $office]) {
            $this->place($name, $roleName, $office, null, $created, $skipped);
        }

        foreach (self::FACULTY as [$name, $roleName, $course]) {
            $this->place($name, $roleName, self::COLLEGE, $course, $created, $skipped);
        }

        $this->command?->info("CTHBM: {$created} seeded, {$skipped} skipped as already present.");
        $this->command?->info('Replace each placeholder with php artisan faculty:email "<name>" <address>');
    }

    private function place(string $name, string $roleName, string $department, ?string $course, int &$created, int &$skipped): void
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
                'department' => $department,
                'course' => $course,
                'status' => 'approved',
                // Without this the auth middleware turns the account away
                // before it can hold anything. Nobody signs in as these --
                // sign-in is Google-only and this domain does not resolve.
                'email_verified_at' => now(),
            ]
        );

        $created++;
    }

    /**
     * Matched on first name AND surname against the real accounts only.
     * Surname alone is too blunt where two colleagues share one, and
     * placeholder rows are excluded or a re-run would refuse its own work.
     */
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

    /** Given names and surname, with titles, initials and suffixes dropped. */
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

        $first = Str::slug($parts[0] ?? 'faculty');
        $last = Str::slug((string) end($parts));

        return "{$first}.{$last}".self::DOMAIN;
    }
}
