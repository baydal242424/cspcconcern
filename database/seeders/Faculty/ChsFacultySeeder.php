<?php

namespace Database\Seeders\Faculty;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The College of Health Sciences, from https://cspc.edu.ph/academics/chs/
 *
 * By far the largest roster in the system -- clinical instructors, full and
 * part time, plus the regular faculty. Nursing and Midwifery are taught in
 * hospital placements, which is why the teaching staff outnumber every other
 * college several times over.
 *
 * Everyone who teaches holds Instructor. The three administrative part-timers
 * hold Faculty/Staff instead: Instructor receives Academic, Physical, Safety
 * and Others automatically, and an administrative part-timer has no business
 * being handed a student's clinical grade dispute for working in the college.
 *
 * Two people on the page are deliberately absent. Dr. Kenny Niño H. Tagum is
 * the CHS dean and already holds chs@cspc.edu.ph; Dr. Leni M. Malabanan is
 * dean of the Graduate School under graduateschool@cspc.edu.ph. Both appear in
 * the Regular Faculty list, and a second row would split their concerns and
 * history across two accounts.
 *
 * No addresses are published, so every account is created under
 * @placeholder.cspc.edu.ph and replaced as each is confirmed:
 *
 *     php artisan faculty:email "Agnas" heraddress@cspc.edu.ph
 *
 * Not called from DatabaseSeeder. Run it deliberately:
 *
 *     php artisan db:seed --class=Database\\Seeders\\Faculty\\ChsFacultySeeder
 */
class ChsFacultySeeder extends Seeder
{
    private const DOMAIN = '@placeholder.cspc.edu.ph';

    private const COLLEGE = 'College of Health Sciences';

    /** Clinical instructors, full time. */
    private const CLINICAL_FULL_TIME = [
        'Marielle France P. Agnas', 'Ma. Lourdes F. Albalate', 'Katrine N. Alferez',
        'Ralph Kevin B. Araña', 'Minakhim O. Arpa', 'Kerry Ann C. Baracena',
        'Noel A. Bengosta', 'Maribeth P. Boncayao', 'Jeffry A. Borromeo',
        'Leizel B. Borromeo', 'Ruth R. Bufe', 'Roselyn B. Bulauan',
        'Carmi Russel T. Casyao', 'Jhessa M. Dacara', 'Ma. Harieth Sa. Daligdig',
        'Fatima G. Dasal', 'Patrick Paul A. Deris', 'Norina N. De Lima',
        'Luzviminda A. Fajardo', 'Julius T. Florece', 'Alma L. Fucio',
        'Lorie Ann E. Garlando', 'Vilma P. Hermogeno', 'Angelyn B. Lachanibre',
        'Rechil L. Lomeda', 'Jenny R. Lopez', 'Bernadette F. Martirez',
        'Nelson Miles B. Mendez', 'Cherry N. Millare', 'Wilfreda Monette B. Moran',
        'Michael Angelo C. Navarra', 'Joseph Nicole A. Ocampo', 'Graciela Joy O. Ocampo',
        'Maria Karene B. Oraa', 'Jennifer M. Rances', 'Maricel B. Salcedo',
        'Sheena Carolyn S. Sedeño', 'Mark Anthony M. Sergio', 'Eden C. Tandayag',
        'Mary Isolde L. Tanay', 'Kim Fourth T. Tataro', 'Kenny B. Tigue',
        'Marites R. Tomenio', 'Cathy H. Turiano', 'Jessica P. Uy',
        'Jopher A. Valerio', 'Teresita P. Velasco', 'Lorna N. Vibares',
        'Crizaline A. Vilammel', 'Benedick B. Villanueva', 'Maricel B. Villanueva',
        'Maria Liezel H. Villanueva', 'Paulo Martin B. Villanueva', 'Erick B. Villarasa',

        // Newly appointed, listed separately on the page.
        'Manuel O. Arines', 'Rene SJ. Abrera', 'Kim Ferand N. Magistrado',
        'Romar C. Samper', 'Aiza M. Tabal',
    ];

    /** Clinical instructors, part time. */
    private const CLINICAL_PART_TIME = [
        'Kinski L. Abanes', 'Jocyl Darrel B. Abinal', 'Fe B. Albia',
        'Erma V. Arroyo', 'Christelle Kay G. Asis', 'Kris Bryan T. Baria',
        'Raymark G. Barredo', 'Galileo B. Bayos', 'Olivia G. Bello',
        'Georjane B. Belza', 'Wilma N. Beralde', 'Joseph A. Bermido',
        'Christie R. Bitara', 'Robert A. Cabañes', 'Ma. Josefina S. Carumba',
        'Vivian S. Consolacion', 'Zaide V. Corporal', 'Dennis Z. Daza',
        'Rubilyn E. De Dios', 'Rommella C. De Leon', 'Luzviminda V. De Villa',
        'Rebecca Z. Fajardo', 'Noel P. Felices', 'Daryl A. Figura',
        'Karen G. Figuracion', 'Ivy C. Floranza', 'Expedito Jr. P. Galvan',
        'Dolores M. Gonzales', 'Johanne L. Hugo', 'Felicidad II A. Jeremias',
        'Liziel B. Malapo', 'Maricar I. Nava', 'Hazel Franz S. Nilo-Hugo',
        'Joyce S. Obis', 'Josephine O. Occiano', 'Bienvenida B. Oliva',
        'Ma. Kathleen Z. Olivares', 'Benigno A. Panoy', 'Jennifer M. Parpan',
        'Michelle B. Paulite', 'John Roi C. Peñaflorida', 'John Kyle F. Perez',
        'Madonna S. Periabras', 'Ma. Lissette I. Ramos', 'Rodel A. Saño',
        'Sarah B. Sta. Ana', 'Sharon B. Sta. Ana', 'Kristine V. Talavera',
        'Tefie Hazel I. Tipones', 'Shara Mae B. Toniza', 'Rhodelyn V. Tortoles',
        'Cynthia H. Turiano', 'Jons Jacob B. Turiano', 'Polyanna R. Villalarvo',

        // Newly appointed.
        'Daryl R. Bulauan', 'Ricardo S. Candelaria Jr.', 'Christian I. Echipare',
        'Rhea C. Enciso', 'Christine P. France', 'Joshua C. Navarro',
        'Marubelle A. Rosales', 'Emmoises M. Taburnal',
    ];

    /**
     * Regular faculty.
     *
     * Dr. Kenny Niño H. Tagum and Dr. Leni M. Malabanan appear on the page in
     * this group and are left out: both are deans with existing accounts.
     */
    private const REGULAR_FACULTY = [
        'Leslie N. Abanes', 'Alvin Franco A. Agtarap', 'Mari Len A. Amoroso',
        'Alberto M. Arejola', 'Sheela Mae N. Bagacina', 'Valerie Sheila M. Bernales',
        'Rachel S. Bucay', 'Hernan A. Buena', 'Rolina M. Caballero',
        'Fe D. Camba', 'Janice M. Chavez', 'Raquel E. Cirujales',
        'Neil Joseph M. De La Cruz', 'Bevvy Philine M. Deris', 'Qvimrej A. Dimabogte',
        'Lito P. Felices', 'Ursula Comla P. Filio', 'Modesto P. Fucio',
        'Elaine Frances M. Illo', 'Marisol B. Latagan', 'Mary Ivy L. Malapo',
        'Susie Anne R. Malate', 'Lea Anne A. Mediado', 'Abigail F. Monge',
        'Bien Paolo O. Monsalve', 'Clemente Jr E. Morata', 'Rey C. Nadal',
        'Lyka P. Niñofranco', 'Marianne H. No', 'Angela Concepcion A. Nolasco',
        'Alicia D. Nuyda', 'Naneth O. Oida', 'Grace E. Pacer',
        'Sheena Marie N. Pamor', 'Mia D. Panimdim', 'Eden Q. Paniterce',
        'Marilyn N. Rivera', 'Jonah L. Rocha', 'Hayres Boots S. Sabio',
        'Maria Laarni M. Salcedo', 'Ervin M. Taburnal', 'Maria Visitacion M. Taburnal',
        'Jhunna M. Talangan', 'Jennifer I. Tam', 'Blenda Marie A. Tataro',
        'Bryan Deo A. Tataro', 'Michael Jeb B. Toriente', 'Grace E. Triumfante',
        'Melany L. Tuyay',
    ];

    /** Administrative part-timers -- support staff, not teaching. */
    private const ADMINISTRATIVE = [
        'Jeniffer D. Esparis', 'Margie M. Isaac', 'Rowena S. Martirez',
    ];

    public function run(): void
    {
        $created = 0;
        $skipped = 0;

        foreach ([self::CLINICAL_FULL_TIME, self::CLINICAL_PART_TIME, self::REGULAR_FACULTY] as $group) {
            foreach ($group as $name) {
                $this->place($name, 'Instructor', $created, $skipped);
            }
        }

        foreach (self::ADMINISTRATIVE as $name) {
            $this->place($name, 'Faculty/Staff', $created, $skipped);
        }

        $this->command?->info("CHS: {$created} seeded, {$skipped} skipped as already present.");
        $this->command?->info('Replace each placeholder with php artisan faculty:email "<name>" <address>');
    }

    private function place(string $name, string $roleName, int &$created, int &$skipped): void
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

    /**
     * firstname.surname@placeholder.cspc.edu.ph, made unique.
     *
     * The uniqueness check matters at this size. firstOrCreate() keys on the
     * address, so two people reduced to the same one would not produce two
     * rows -- the second would silently adopt the first's account, and one of
     * them would vanish from the roster with nothing to show it happened.
     * Middle initials are added back, and a counter after that.
     */
    private function placeholderAddress(string $name): string
    {
        $parts = $this->nameParts($name);
        $first = Str::slug($parts[0] ?? 'staff');
        $last = Str::slug((string) end($parts));

        $base = "{$first}.{$last}";
        $candidate = $base.self::DOMAIN;

        if (! $this->addressTaken($candidate, $name)) {
            return $candidate;
        }

        // A middle name usually separates them.
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

    /** Taken by somebody else -- the same person's own row is not a clash. */
    private function addressTaken(string $email, string $name): bool
    {
        return User::where('email', $email)->where('name', '!=', $name)->exists();
    }
}
