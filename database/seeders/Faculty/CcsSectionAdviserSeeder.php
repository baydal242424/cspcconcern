<?php

namespace Database\Seeders\Faculty;

use App\Models\Section;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Class advisers for the College of Computer Studies, from the posted lists.
 *
 * Two terms are recorded, not one. The lists differ -- BSIT 1D was advised by
 * Jay-R Leonidas in the first semester and Shiela Dona Manlapaz in the second,
 * BSIT 3B by Freddie Prianes then Leni Girlie Idian -- and keeping both means
 * last term's assignment stays as history rather than being overwritten by
 * this term's.
 *
 * An adviser here is not given the Adviser ROLE. Most of them are already
 * Instructors or Program Chairs, the system stores one role per person, and
 * advising a section is something a person does rather than something they
 * are. Routing looks the adviser up through this table; their role continues
 * to describe what they may read.
 *
 * Anyone not already in the database is created as a CCS Instructor under a
 * placeholder address, because a section pointing at nobody advises nobody.
 *
 * Not called from DatabaseSeeder. Run it deliberately:
 *
 *     php artisan db:seed --class=Database\\Seeders\\Faculty\\CcsSectionAdviserSeeder
 */
class CcsSectionAdviserSeeder extends Seeder
{
    private const YEAR = '2024-2025';

    /** Section prefixes as the college writes them, to the stored programme. */
    private const PROGRAMMES = [
        'BSIT' => 'BS Information Technology',
        'BSCS' => 'BS Computer Science',
        'BSIS' => 'BS Information Systems',
        'BLIS' => 'Bachelor of Library and Information Science',
    ];

    /** [section, adviser] for the first semester, SY 2024-2025. */
    private const FIRST_SEMESTER = [
        ['BSIT 1A', 'Jonuel Rey N. Colle'], ['BSIT 1B', 'Rey T. Cortez'],
        ['BSIT 1C', 'Jeremy Jireh S. Neo'], ['BSIT 1D', 'Jay-R H. Leonidas'],
        ['BSIT 1E', 'Philip Alger M. Serrano'], ['BSIT 2A', 'Maricris L. Ramizares'],
        ['BSIT 2B', 'Ichelle F. Baluis'], ['BSIT 2C', 'Mae Ann Ll. Tagum'],
        ['BSIT 2D', 'Kezia Abegail T. Velasco'], ['BSIT 2E', 'Jonathan Balbuena'],
        ['BSIT 2F', 'Richard F. Nonato'], ['BSIT 2G', 'Leomir K. Paz'],
        ['BSIT 2H', 'Kevin Von Erick D. Albania'], ['BSIT 3A', 'Marivic L. Ramizares'],
        ['BSIT 3B', 'Freddie B. Prianes'], ['BSIT 3C', 'Tiffany Lyn O. Pandes'],
        ['BSIT 3D', 'Ian P. Benitez'], ['BSIT 3E', 'Noel V. Paguio Jr.'],
        ['BSIT 3F', 'Vincent B. Cortez'], ['BSIT 3G', 'Mhelrose P. Prades'],
        ['BSIT 3H', 'Mark Kenneth R. Limjoco'], ['BSIT 4A', 'Vencel Angelo R. Sanglay'],
        ['BSIT 4B', 'Maria Theresa B. Goleta'], ['BSIT 4C', 'Leni Girlie M. Idian'],
        ['BSIT 4D', 'John Kenneth F. Olleres'], ['BSIT 4E', 'Eisen Rose D. Galvante'],
        ['BSIT 4F', 'Derick S. Parañal'], ['BSCS 1A', 'Jethro Ralph N. Abonal'],
        ['BSCS 2A', 'Joseph Jessie S. Oñate'], ['BSCS 2B', 'Shiela Dona S. Manlapaz'],
        ['BSCS 3A', 'Kaela Marie N. Fortuno'], ['BSCS 3B', 'Jayvee Niel S. Sias'],
        ['BSCS 4A', 'Rosel O. Onesa'], ['BSCS 4B', 'Allan O. Ibo Jr.'],
        ['BLIS 1A', 'Mercy O. Gonowon'], ['BLIS 2A', 'James Nicolo SJ. Sias'],
        ['BLIS 3A', 'Ayra A. Gonowon'], ['BLIS 4A', 'Ime A. Mortel'],
        ['BLIS 4B', 'Kay Angeline O. Broqueza'], ['BSIS 1A', 'Mary Chie Amoroso-De La Cruz'],
        ['BSIS 1B', 'Mark Anthony P. Dancalan'],
    ];

    /** [section, adviser] for the second semester, AY 2024-2025. */
    private const SECOND_SEMESTER = [
        ['BSIT 1A', 'Jonuel Rey N. Colle'], ['BSIT 1B', 'Rey T. Cortez'],
        ['BSIT 1C', 'Jeremy Jireh S. Neo'], ['BSIT 1D', 'Shiela Dona S. Manlapaz'],
        ['BSIT 1E', 'Philip Alger M. Serrano'], ['BSIT 2A', 'Maricris L. Ramizares'],
        ['BSIT 2B', 'Ichelle F. Baluis'], ['BSIT 2C', 'Mae Ann Ll. Tagum'],
        ['BSIT 2D', 'Kezia Abegail T. Velasco'], ['BSIT 2E', 'Jonathan Balbuena'],
        ['BSIT 2F', 'Richard F. Nonato'], ['BSIT 2G', 'Jocelyn Lipata'],
        ['BSIT 2H', 'Kevin Von Erick D. Albania'], ['BSIT 3A', 'Marivic L. Ramizares'],
        ['BSIT 3B', 'Leni Girlie M. Idian'], ['BSIT 3C', 'Jayvee Niel SJ. Sias'],
        ['BSIT 3D', 'Ian P. Benitez'], ['BSIT 3E', 'Noel V. Paguio Jr.'],
        ['BSIT 3F', 'Vincent B. Cortez'], ['BSIT 3G', 'Mhelrose P. Prades'],
        ['BSIT 3H', 'Mark Kenneth R. Limjoco'], ['BSIT 4A', 'Jocelle Monreal'],
        ['BSIT 4B', 'Freddie B. Prianes'], ['BSIT 4C', 'Freddie B. Prianes'],
        ['BSIT 4D', 'Eisen Rose D. Galvante'], ['BSIT 4E', 'Eisen Rose D. Galvante'],
        ['BSIT 4F', 'Derick S. Parañal'], ['BSCS 1A', 'Jethro Ralph N. Abonal'],
        ['BSCS 2A', 'Joseph Jessie S. Oñate'], ['BSCS 2B', 'Allan O. Ibo Jr'],
        ['BSCS 3A', 'Kaela Marie N. Fortuno'], ['BSCS 3B', 'Tiffany Lyn O. Pandes'],
        ['BSCS 4A', 'Rosel O. Onesa'], ['BSCS 4B', 'Allan O. Ibo Jr.'],
        ['BLIS 1A', 'Mercy O. Gonowon'], ['BLIS 2A', 'James Nicolo SJ. Sias'],
        ['BLIS 3A', 'Ayra A. Gonowon'], ['BLIS 4A', 'Ime A. Mortel'],
        ['BLIS 4B', 'Kay Angeline O. Broqueza'], ['BSIS 1A', 'Mary Chie Amoroso-De La Cruz'],
        ['BSIS 1B', 'Jonuel Rey N. Colle'],
    ];

    public function run(): void
    {
        $created = 0;
        $advisers = 0;

        foreach ([['First', self::FIRST_SEMESTER], ['Second', self::SECOND_SEMESTER]] as [$semester, $rows]) {
            foreach ($rows as [$label, $name]) {
                [$prefix, $section] = explode(' ', $label, 2);
                $course = self::PROGRAMMES[$prefix] ?? null;

                if (! $course) {
                    $this->command?->warn("Skipped {$label}: unknown programme prefix '{$prefix}'.");
                    continue;
                }

                $adviser = $this->adviser($name, $advisers);

                Section::updateOrCreate(
                    [
                        'course' => $course,
                        'section' => $section,
                        'school_year' => self::YEAR,
                        'semester' => $semester,
                    ],
                    ['adviser_id' => $adviser?->id]
                );

                $created++;
            }
        }

        $this->command?->info("CCS sections: {$created} recorded across two semesters, {$advisers} advisers created.");
    }

    /**
     * The account for this adviser, created as a CCS instructor if they are
     * not here yet. A section pointing at nobody advises nobody, so a missing
     * name is worth an account rather than a gap.
     */
    private function adviser(string $name, int &$advisers): ?User
    {
        $parts = array_values(array_filter(
            array_map(fn ($p) => rtrim($p, '.'), explode(' ', $name)),
            fn ($p) => strlen($p) > 1 && ! in_array($p, ['Mr', 'Ms', 'Mrs', 'Dr', 'Jr', 'Sr', 'II', 'III'], true)
        ));

        if (count($parts) < 2) {
            return null;
        }

        $existing = User::where('name', 'like', '%'.$parts[0].'%')
            ->where('name', 'like', '%'.end($parts).'%')
            ->first();

        if ($existing) {
            return $existing;
        }

        $advisers++;

        return User::firstOrCreate(
            ['email' => \Illuminate\Support\Str::slug($parts[0]).'.'.\Illuminate\Support\Str::slug(end($parts)).'@placeholder.cspc.edu.ph'],
            [
                'name' => $name,
                'password' => \Illuminate\Support\Facades\Hash::make(\Illuminate\Support\Str::random(40)),
                'role_id' => \App\Models\Role::where('name', 'Instructor')->value('id'),
                'department' => 'College of Computer Studies',
                'status' => 'approved',
                'email_verified_at' => now(),
            ]
        );
    }
}
