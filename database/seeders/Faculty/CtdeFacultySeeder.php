<?php

namespace Database\Seeders\Faculty;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The College of Technological and Developmental Education, from its published
 * organisational structure.
 *
 * Three kinds of people, and the difference decides what routing does with
 * them rather than how senior they are:
 *
 *   Program Chair   the six programme chairs, each pinned to their programme
 *   Instructor      core faculty and professional education faculty
 *   Faculty/Staff   the administrative aides, technical writer and computer
 *                   technician -- referral-gated, because Instructor receives
 *                   Academic, Physical, Safety and Others automatically and an
 *                   administrative aide has no business being handed a
 *                   student's grade dispute for working in the college
 *
 * Three people on the chart already hold accounts and are left out: Dr.
 * Patrick Gerard A. Paulino is the CTDE dean under ctde@cspc.edu.ph, and Dr.
 * Amado A. Oliva Jr. and Dr. Jocelyn O. Jintalan sit above the college as Head
 * of School and VPAA. A second row would split their concerns and history.
 *
 * No addresses are published, so accounts are created under
 * @placeholder.cspc.edu.ph and replaced as each is confirmed:
 *
 *     php artisan faculty:email "Saclag" heraddress@cspc.edu.ph
 *
 * Not called from DatabaseSeeder. Run it deliberately:
 *
 *     php artisan db:seed --class=Database\\Seeders\\Faculty\\CtdeFacultySeeder
 */
class CtdeFacultySeeder extends Seeder
{
    private const DOMAIN = '@placeholder.cspc.edu.ph';

    private const COLLEGE = 'College of Technological and Development Education';

    private const BTVTED = 'Bachelor of Technical-Vocational Teacher Education';

    /**
     * [name, programme]
     *
     * The chart names separate chairs for the BTVTED majors -- Food
     * Processing, Food and Service Management, Electronics -- but the system
     * knows one BTVTED programme, not three. All three are pinned to it, which
     * means a BTVTED student's referral reaches whichever of them sorts first
     * rather than the chair of their own major.
     *
     * Fixing that properly means adding the majors to
     * User::COURSES_BY_COLLEGE and asking students which one they are in, so
     * it is recorded here rather than papered over.
     */
    private const CHAIRS = [
        ['Edelyn N. Nales', self::BTVTED],
        ['Dr. Michelle L. Junio', self::BTVTED],
        ['Juniser A. Oliva', self::BTVTED],
        ['Dr. Niña SF. Sibulo', 'Bachelor of Special Needs Education'],
        ['Dr. Jay L. Luzon', 'Bachelor of Culture and Arts Education'],
        ['Dr. Edesa R. Saclag', 'Bachelor of Physical Education'],
    ];

    /** Core faculty and professional education faculty. */
    private const FACULTY = [
        // Core faculty, by programme.
        'Alvin B. Badong',
        'April Mae L. Bantog',
        'Donn Raymond L. Bermundo',
        'Bryan B. Amaranto',
        'Benedic V. Baluyot',
        'Brine Joy D. Abonita',
        'Khate B. Martinez',
        'Christian K. Puso',
        'Rosiel Bruzula',
        'Mary Justine Sienne D. Corporal',

        // Professional education faculty.
        'Dr. Marivel F. Paycana',
        'Dr. Arly B. Balingbing',
        'Ruby Jane S. Gonzales',
        'Dr. Estelito R. Clemente',
        'Dennis N. Rañon',
        'Romeo B. Sotto Jr.',
    ];

    /** Non-teaching support staff. */
    private const SUPPORT = [
        'Angelli S. Guazon',
        'Joechiel B. Selleza',
        'Dianne N. Almazan',
        'Matt Glenn L. Laynesa',
    ];

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

        $this->command?->info("CTDE: {$created} seeded, {$skipped} skipped as already present.");
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
