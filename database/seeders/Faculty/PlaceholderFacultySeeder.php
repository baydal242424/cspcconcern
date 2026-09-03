<?php

namespace Database\Seeders\Faculty;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Real faculty names, placeholder addresses.
 *
 * These are the actual teaching staff of two colleges, seeded so that routing
 * and the referral pickers have somebody in every college before anyone has
 * signed in. Their email addresses are NOT real and are not guesses at real
 * ones -- every address ends @placeholder.cspc.edu.ph, a subdomain that does
 * not exist and never will.
 *
 * That domain is the whole point. A guessed address in the real domain would
 * look plausible and fail silently: the person signs in with their actual
 * address, gets a second account, and the seeded row stays in the picker as a
 * name whose concerns reach nobody. A visibly fake domain cannot be mistaken
 * for the real thing, mail to it fails and is logged rather than delivered
 * somewhere wrong, and the whole set can be found and replaced in one query:
 *
 *     User::where('email', 'like', '%@placeholder.cspc.edu.ph')->get();
 *
 * When somebody's real address is confirmed, update that one row -- their
 * concerns, history and role move with it. When they instead sign in for the
 * first time with a real address, they get a fresh account and this row should
 * be deleted.
 *
 * Not called from DatabaseSeeder. Run it deliberately:
 *
 *     php artisan db:seed --class=Database\\Seeders\\Faculty\\PlaceholderFacultySeeder
 */
class PlaceholderFacultySeeder extends Seeder
{
    private const DOMAIN = '@placeholder.cspc.edu.ph';

    private const CCS = 'College of Computer Studies';

    private const CEA = 'College of Engineering and Architecture';

    /**
     * [full name, college, role, programme]
     *
     * Source for CCS: the posted Faculty Consultation Hours, First Semester
     * S/Y 2026-2027. Source for CEA: list supplied by the college.
     *
     * Four people on those lists are NOT here because they already hold
     * accounts, and seeding a second one under a fake address would split
     * their history across two rows:
     *
     *   Ms. Rosel O. Onesa    Dean            ccs@cspc.edu.ph
     *   Mr. Rey T. Cortez     Faculty/Staff   mict@cspc.edu.ph
     *   Mr. Jeremy Jireh Neo  Instructor      jeremyneo@cspc.edu.ph
     *   Ms. April Joy Aguado  Instructor      joyaguado@cspc.edu.ph
     */
    private const FACULTY = [
        // ---- College of Computer Studies ----
        ['Ms. Maica DL. Bagaporo', self::CCS, 'Instructor', null],
        ['Dr. Ichelle F. Baluis', self::CCS, 'Instructor', null],
        ['Dr. Ian P. Benitez', self::CCS, 'Instructor', null],
        ['Ms. Brenda D. Benosa', self::CCS, 'Instructor', null],
        ['Ms. Paulina B. Breboneria', self::CCS, 'Instructor', null],
        ['Ms. Kay Angeline O. Broqueza', self::CCS, 'Instructor', null],
        // Vincent B., not Rey T. -- two different people named Cortez.
        ['Mr. Vincent B. Cortez', self::CCS, 'Instructor', null],
        ['Ms. Kaela Marie N. Fortuno', self::CCS, 'Instructor', null],
        ['Mr. Allan O. Ibo Jr.', self::CCS, 'Instructor', null],
        ['Dr. Jocelyn T. Lipata', self::CCS, 'Instructor', null],
        ['Ms. Josefina H. Llagas', self::CCS, 'Instructor', null],
        ['Ms. Shiela Dona S. Manlapaz', self::CCS, 'Instructor', null],
        ['Dr. Victor Q. Parillas Jr.', self::CCS, 'Instructor', null],
        ['Ms. Mhelrose B. Prades', self::CCS, 'Instructor', null],
        ['Ms. Maricris Ramizares', self::CCS, 'Instructor', null],

        // The three programme chairs, with the programme each covers. This is
        // what sends a BSIS student's referral to the BSIS chair rather than
        // whichever chair sorts first, so the strings must match
        // User::COURSES_BY_COLLEGE exactly.
        ['Ms. Tiffany Lyn O. Pandes', self::CCS, 'Program Chair', 'BS Computer Science'],
        ['Mr. Freddie B. Prianes', self::CCS, 'Program Chair', 'BS Information Technology'],
        ['Ms. Ime Amor A. Mortel', self::CCS, 'Program Chair', 'Bachelor of Library and Information Science'],

        // ---- College of Engineering and Architecture ----
        ['Daisylen De Guzman Alano', self::CEA, 'Instructor', null],
        ['Angelica L. Bongcayao', self::CEA, 'Instructor', null],
        ['Christoper Oares', self::CEA, 'Instructor', null],
        ['Peter Turiano', self::CEA, 'Instructor', null],
    ];

    /*
     * Three things worth confirming, none of which a seeder can settle:
     *
     *  - JAMES SIAS appeared on BOTH lists and now has a confirmed address,
     *    so he is seeded in CcsFacultySeeder rather than here -- to CCS,
     *    where the posted consultation sheet gives him hours. If he also
     *    teaches in CEA, his college on that record decides whose students
     *    reach him.
     *
     *  - HAROLD JAN R. TERANO directs Research and Development Services, per
     *    cspc.edu.ph, and is seeded as a CEA instructor because he appears on
     *    the college's teaching list. If the unit post is the whole role, he
     *    belongs in UserSeeder as office staff instead, and CEA students'
     *    concerns should not reach him. Juvy Bustamante raised the same
     *    question and now has a confirmed address, so he sits in
     *    CeaFacultySeeder with the note attached there.
     *
     *  - DAISYLEN DE GUZMAN ALANO directs the Extension and Community Services
     *    Office. Same question.
     */

    public function run(): void
    {
        $created = 0;
        $skipped = 0;

        foreach (self::FACULTY as [$name, $college, $roleName, $course]) {
            $role = Role::where('name', $roleName)->first();

            if (! $role) {
                $this->command?->warn("Skipped {$name}: the '{$roleName}' role does not exist.");
                continue;
            }

            // Never a second row for somebody already here under a real
            // address. Matching on the surname is blunt, but a placeholder
            // duplicate of a real person is worse than a name left out: their
            // concerns would split across two accounts, and the fake one would
            // sit in the picker reaching nobody.
            if ($this->alreadyPresent($name)) {
                $this->command?->warn("Skipped {$name}: an account for that name already exists.");
                $skipped++;
                continue;
            }

            User::firstOrCreate(
                ['email' => $this->placeholderAddress($name)],
                [
                    'name' => $name,
                    'password' => Hash::make(Str::random(40)),
                    'role_id' => $role->id,
                    'department' => $college,
                    'course' => $course,
                    'status' => 'approved',
                    // Without this the auth middleware bounces the account
                    // before it can hold anything. Nobody signs in as these --
                    // sign-in is Google-only and this domain does not exist.
                    'email_verified_at' => now(),
                ]
            );

            $created++;
        }

        $this->command?->info("Placeholder faculty: {$created} seeded, {$skipped} skipped as already present.");
        $this->command?->info('Addresses end '.self::DOMAIN.' -- replace each with the real one as it is confirmed.');
    }

    /**
     * Does a real account already exist for this person?
     *
     * Matched on first name AND surname. Surname alone was too blunt: it
     * refused to seed Vincent B. Cortez because Rey T. Cortez already had an
     * account, and they are two different people who happen to share a
     * college and a surname. Requiring both names is still imperfect -- a
     * middle initial or a married name would defeat it -- but it fails towards
     * creating a duplicate somebody can see and delete, rather than silently
     * omitting a person nobody notices is missing.
     *
     * Placeholder rows are excluded from the check, or re-running this would
     * refuse its own previous work.
     */
    private function alreadyPresent(string $name): bool
    {
        $parts = $this->nameParts($name);

        if (count($parts) < 2) {
            return false;
        }

        $first = $parts[0];
        $surname = end($parts);

        return User::where('name', 'like', '%'.$first.'%')
            ->where('name', 'like', '%'.$surname.'%')
            ->where('email', 'not like', '%'.self::DOMAIN)
            ->exists();
    }

    /** Given names and surname, with titles, initials and suffixes dropped. */
    private function nameParts(string $name): array
    {
        return array_values(array_filter(
            array_map(fn ($p) => rtrim($p, '.'), explode(' ', $name)),
            fn ($p) => strlen($p) > 1
                && ! in_array($p, ['Mr', 'Ms', 'Mrs', 'Dr', 'Engr', 'Atty', 'Jr', 'Sr', 'III'], true)
        ));
    }

    /** firstname.surname@placeholder.cspc.edu.ph, titles and initials dropped. */
    private function placeholderAddress(string $name): string
    {
        $parts = $this->nameParts($name);

        $first = Str::slug($parts[0] ?? 'faculty');
        $last = Str::slug((string) end($parts));

        return "{$first}.{$last}".self::DOMAIN;
    }
}
