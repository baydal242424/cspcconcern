<?php

namespace Database\Seeders\Faculty;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The full teaching roster of the College of Engineering and Architecture,
 * from https://cspc.edu.ph/academics/coea/ -- every programme's faculty,
 * regular and contract-of-service alike.
 *
 * The page names no programme chairs. It lists the dean and then faculty
 * grouped by programme, so CEA has no chair tier: a programme-level referral
 * there falls back to college level. That is a gap in the source, not in the
 * system, and it is worth chasing separately.
 *
 * Everyone is an Instructor with no programme pinned, even though the page
 * groups them by one. Only a Student and a Program Chair carry a course:
 * findHandler() prefers a handler whose course matches the reporter's, so
 * putting "BS Civil Engineering" on twenty instructors would make all twenty
 * outrank the college's actual chair for those students. AdminController
 * clears the field on any other role for the same reason.
 *
 * Several people teach in more than one programme -- Badiola, Biag, Bongcayao,
 * Buena, Cerillo, Ebron, Estallo, Musa and San Carlos each appear under two --
 * so the list is de-duplicated before anything is written.
 *
 * Not called from DatabaseSeeder. Run it deliberately:
 *
 *     php artisan db:seed --class=Database\\Seeders\\Faculty\\CeaFullFacultySeeder
 */
class CeaFullFacultySeeder extends Seeder
{
    private const DOMAIN = '@placeholder.cspc.edu.ph';

    private const COLLEGE = 'College of Engineering and Architecture';

    /** Civil Engineering. */
    private const CIVIL = [
        'Seth B. Barandon', 'Verna Liza M. Bermido', 'Irene Virginia C. Blanquera',
        'Edna P. Montañez', 'Francia H. Tomenio', 'Christian Karl B. Villaluz',
        'Yolanda A. Santorcas',
        'Marian E. Badiola', 'Joshua S. Biag', 'Rowel Domingo N. Bermal',
        'Angelica L. Bongcayao', 'Mark B. Buena', 'Erly O. Celiz',
        'Elisha B. Cerillo', 'Jun Francis R. Debil', 'Neil Bryan P. De Lima',
        'Jayson E. Ebron', 'Jenny B. Espejo', 'Joanna H. Estallo',
        'John Marlo O. Gorobao', 'Lara Jane D. Mendoza', 'Venus C. Musa',
        'John Rey M. Pacturanan', 'Rey S.A. Rimando', 'Carlo Adonis S. San Carlos',
        'Ulyssa Mae B. Serrano', 'Elias L. Tomenio', 'Henry P. Turalde',
        'Belen B. Magistrado', 'Antonia B. Martinez', 'Jose L. Oliva',
        'Marben S. Ramos',
    ];

    /** Electrical Engineering. */
    private const ELECTRICAL = [
        'Eddie L. Cabaltera', 'Wenceslao D. Gavina', 'Jose Eduardo II B. Cerillo',
        'Wenifredo L. Pacer', 'Virginia V. Pontillas', 'Martin Jr. D. Valeras',
        'Ma. Grace R. Aballa', 'Roner P. Abanil', 'Eduardo A. Cabanting',
        'Rosalie I. Gutierrez', 'Christian S. Nabio', 'Rodave G. Prestado',
        'Eric N. Velitario',
    ];

    /** Electronics Engineering. */
    private const ELECTRONICS = [
        'Eugene P. Barbonio', 'Rizza T. Loquias', 'Vincent E. Malapo',
        'Christopher T. Oares', 'Ruel R. Romulo', 'Keith Marlon R. Tabal',
        'Harold Jan R. Terano',
        'Victor Solito Dr. Isaac',
    ];

    /** Mechanical Engineering. */
    private const MECHANICAL = [
        'Bonifacio B. Buyet', 'Joeffrey D. Bustinera', 'Saul J. Ebonite',
        'Christopher C. Gutierrez', 'Leo E. Luceña', 'Rodolfo A. Merca Jr.',
        'Radmar B. Tañamor',
        'April Joy F. Aguado', 'Anjanette B. Baal', 'Jeremie C. Balang',
        'Angelo M. Bargo', 'Lino D. Berango', 'Edrianne Jay L. Dimanarig',
        'Pia Margarette B. Luceña', 'Syra Lyn B. Magistrado',
        'Joseph Nathan B. Marquez', 'John Philip III T. Nadal',
        'Gilbert A. Peñales', 'Precious Grace F. Peregrino',
        'Robert Jay L. Rabeje', 'Justine Rheyvan R. Tataro',
        'Romeo Cesar G. Tubig', 'John Christian Rey F. Celiz',
    ];

    /** Architecture. */
    private const ARCHITECTURE = [
        'Benigno Manuel S. III Aquino', 'Ernesto B. Bermido', 'Mary Ann A. Martinez',
        'Ronnie Michael S. Caballero', 'Vanessa Ella Fay M. Bermido',
    ];

    /** Computer Engineering. */
    private const COMPUTER = [
        'Sarahlyn C. Catimbang',
    ];

    /**
     * Administrative part-timers -- support staff, so Faculty/Staff. An
     * Instructor receives Academic, Physical, Safety and Others automatically,
     * and an administrative part-timer should not be handed a student's grade
     * dispute for working in the college.
     */
    private const ADMINISTRATIVE = [
        'Karen Kay O. Bagacina', 'Alexander Ian R. Layson', 'Javyie B. Margate',
    ];

    public function run(): void
    {
        $teaching = array_values(array_unique(array_merge(
            self::CIVIL, self::ELECTRICAL, self::ELECTRONICS,
            self::MECHANICAL, self::ARCHITECTURE, self::COMPUTER
        )));

        $created = 0;
        $skipped = 0;

        foreach ($teaching as $name) {
            $this->place($name, 'Instructor', $created, $skipped);
        }

        foreach (self::ADMINISTRATIVE as $name) {
            $this->place($name, 'Faculty/Staff', $created, $skipped);
        }

        $this->command?->info("CEA: {$created} seeded, {$skipped} skipped as already present.");
    }

    private function place(string $name, string $roleName, int &$created, int &$skipped): void
    {
        $role = Role::where('name', $roleName)->first();

        if (! $role) {
            $this->command?->warn("Skipped {$name}: the '{$roleName}' role does not exist.");

            return;
        }

        if ($this->alreadyPresent($name)) {
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
