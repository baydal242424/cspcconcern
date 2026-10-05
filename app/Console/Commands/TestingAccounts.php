<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\Section;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * One account per role per college, for trying the system out.
 *
 * READ THIS BEFORE RUNNING IT ANYWHERE REAL. Routing cannot tell a test
 * account from a person: findHandler() picks whoever holds the role in the
 * right college, and a test dean is as eligible as the real one. Staff demo
 * accounts were removed from this system once already, after a real student's
 * concern was handed to one that nobody could sign into -- it sat unread
 * because the person it was addressed to did not exist.
 *
 * So this refuses to run in production unless it is forced, every address is
 * stamped `test.` so it is obvious in Manage Users and in any picker, and
 * --remove takes the whole set away again.
 *
 *   php artisan testing:accounts              create them
 *   php artisan testing:accounts --list       show what exists
 *   php artisan testing:accounts --remove     take them all away
 *
 * They cannot be signed into. CSPC Mail is the only way in and no Google
 * account owns these addresses, so they are for seeing how the system behaves
 * -- who a concern reaches, what each role's list looks like in the data --
 * not for clicking through as that person.
 */
class TestingAccounts extends Command
{
    protected $signature = 'testing:accounts
        {--list : Show the test accounts that exist, then stop}
        {--remove : Delete every test account instead of creating them}
        {--force : Allow this on production, where it is normally refused}';

    protected $description = 'Create (or remove) one test account per role per college';

    /** Every address starts with this, which is what makes the set findable. */
    private const PREFIX = 'test.';

    /** Roles that exist once for the whole institution, not per college. */
    private const CENTRAL_ROLES = [
        'Guidance Counselor' => 'Guidance Office',
        'Staff Admin' => 'Academic Affairs',
    ];

    /** Roles created for each college. */
    private const COLLEGE_ROLES = ['Dean', 'Program Chair', 'Adviser', 'Instructor'];

    /**
     * Students are made per PROGRAMME, not per college, and one for every
     * class from 1A to 4D.
     *
     * A student's concern is routed by their programme and their section --
     * Section::adviserFor($course, $section) -- so one student per college
     * could only ever exercise one adviser. A student in each class reaches
     * whoever really advises it, which is the path a live concern takes.
     */
    private const STUDENT_YEARS = [1, 2, 3, 4];

    private const STUDENT_LETTERS = ['A', 'B', 'C', 'D'];

    public function handle(): int
    {
        if ($this->option('list')) {
            return $this->listAccounts();
        }

        if ($this->option('remove')) {
            return $this->removeAccounts();
        }

        // The environment check is the whole safety story. On production these
        // accounts join the routing pool the moment they exist.
        if (app()->environment('production') && ! $this->option('force')) {
            $this->error('Refusing to create test accounts on production.');
            $this->line('Routing cannot tell one from a real person: a test dean is as eligible');
            $this->line('to receive a student\'s concern as the real one, and nobody can sign in');
            $this->line('as it to answer. Pass --force only if you are certain.');

            return self::FAILURE;
        }

        $created = 0;
        $updated = 0;
        $skipped = [];

        foreach (User::COURSES_BY_COLLEGE as $college => $courses) {
            $slug = $this->collegeSlug($college);
            $course = $courses[0];

            foreach (self::COLLEGE_ROLES as $roleName) {
                // Only where the college has nobody real in that role. Every
                // dean, chair and instructor is already on file, and their
                // accounts have never signed in -- so they are already in the
                // sign-in dropdown under their own role. A test account beside
                // them is a second name for the same job, which is a longer
                // list to read and a choice with no right answer.
                if ($this->alreadyStaffed($roleName, $college)) {
                    $skipped[] = "{$roleName} — {$this->shortCollege($college)}";

                    continue;
                }

                $email = self::PREFIX.Str::lower(str_replace(' ', '', $roleName)).'.'.$slug.'@cspc.edu.ph';

                $result = $this->upsert($email, [
                    'name' => 'TEST '.$roleName.' — '.$this->shortCollege($college),
                    'role_id' => $this->roleId($roleName),
                    'department' => $college,
                    // A dean covers the whole college, so no programme. The
                    // rest are attached to one, which is what college-scoped
                    // routing matches on.
                    'course' => $roleName === 'Dean' ? null : $course,
                    'section' => null,
                    'student_id' => null,
                    // The role too, not just the college: staff numbers are
                    // unique now, and every staff role in a college shared
                    // one, so the second account of each college collided.
                    'employee_id' => 'TEST-'.Str::upper($slug).'-'.Str::upper(Str::slug($roleName)),
                ]);

                $result === 'created' ? $created++ : $updated++;

                // The adviser needs a class on record, or they advise nobody
                // and an Academic concern never reaches them.
                if ($roleName === 'Adviser') {
                    $this->giveAdviserAFreeClass(User::where('email', $email)->value('id'), $course);
                }
            }

            // One student per class, for every programme the college offers.
            foreach ($courses as $programme) {
                $programmeSlug = $this->programmeSlug($programme);
                $finalYear = User::finalYearFor($programme);

                foreach (self::STUDENT_YEARS as $year) {
                    // A three-year programme has no fourth year, and a
                    // student filed under one would sit in a class that does
                    // not exist -- which is how a concern reaches nobody.
                    if ($year > $finalYear) {
                        continue;
                    }

                    foreach (self::STUDENT_LETTERS as $letter) {
                        $section = $year.$letter;

                        $result = $this->upsert(
                            self::PREFIX.'student.'.$programmeSlug.'.'.Str::lower($section).'@my.cspc.edu.ph',
                            [
                                // Just the label. The dropdown prints the
                                // programme and class beside it, and carrying
                                // them in the name too read as "TEST Student —
                                // BS Information Technology 3A — BS
                                // Information Technology 3A".
                                'name' => 'TEST Student',
                                'role_id' => $this->roleId('Student'),
                                'department' => $college,
                                'course' => $programme,
                                'section' => $section,
                                // Derived from the class, not random. Student
                                // numbers are unique, and 432 random draws
                                // from a million collide often enough to fail
                                // a run -- this cannot collide, and re-running
                                // gives the same student the same number.
                                'student_id' => $this->testStudentNumber($programme, $section),
                                'employee_id' => null,
                            ]
                        );

                        $result === 'created' ? $created++ : $updated++;
                    }
                }
            }
        }

        foreach (self::CENTRAL_ROLES as $roleName => $office) {
            if ($this->alreadyStaffed($roleName)) {
                $skipped[] = $roleName;

                continue;
            }

            $email = self::PREFIX.Str::slug($roleName, '.').'@cspc.edu.ph';

            $result = $this->upsert($email, [
                'name' => 'TEST '.$roleName,
                'role_id' => $this->roleId($roleName),
                'department' => $office,
                'course' => null,
                'section' => null,
                'student_id' => null,
                'employee_id' => 'TEST-'.Str::upper(Str::slug($roleName)),
            ]);

            $result === 'created' ? $created++ : $updated++;
        }

        // Anything created here that is no longer needed -- because the real
        // person has since been added -- is cleared out, so running this again
        // after filling a vacancy tidies up after itself.
        $retired = $this->retireRolesNowStaffed();

        $this->info("Created {$created}, updated {$updated}.");

        if ($skipped !== []) {
            $this->newLine();
            $this->line('Already staffed by real accounts, so no test one was made:');

            foreach (array_chunk($skipped, 4) as $row) {
                $this->line('  '.implode(' · ', $row));
            }
        }

        if ($retired > 0) {
            $this->newLine();
            $this->warn("Removed {$retired} test account(s) whose role now has a real holder.");
        }

        $this->newLine();
        $this->line('They cannot be signed into -- no Google account owns these addresses.');
        $this->line('Find them in Manage Users by searching "test".');
        $this->line('Remove them with: php artisan testing:accounts --remove');

        return self::SUCCESS;
    }

    /**
     * Is there a real person in this role already?
     *
     * "Real" means not one of ours. It deliberately does NOT mean "has signed
     * in": every dean and program chair on file was put there by a seeder and
     * has never used Google, and those accounts are exactly the ones a tester
     * should be picking in the sign-in dropdown.
     */
    private function alreadyStaffed(string $roleName, ?string $college = null): bool
    {
        return User::whereHas('role', fn ($q) => $q->where('name', $roleName))
            ->where('email', 'not like', self::PREFIX.'%')
            ->where('email', 'not like', 'demo.%')
            ->where('status', 'approved')
            ->when($college, fn ($q) => $q->where('department', $college))
            ->exists();
    }

    /**
     * Drop test staff whose role has since been filled for real.
     *
     * Without this, a test dean made when the college had none would outlive
     * the day the real dean was added, and stay in the dropdown as a second
     * name for the same job. Students are never retired: they are the people
     * filing, not the people handling, so a real student does not make a test
     * one redundant.
     */
    private function retireRolesNowStaffed(): int
    {
        $retired = 0;

        $candidates = User::with('role')
            ->where('email', 'like', self::PREFIX.'%')
            ->whereHas('role', fn ($q) => $q->where('name', '!=', 'Student'))
            ->get();

        foreach ($candidates as $account) {
            $roleName = optional($account->role)->name;

            if (! $roleName) {
                continue;
            }

            $isCentral = array_key_exists($roleName, self::CENTRAL_ROLES);

            if (! $this->alreadyStaffed($roleName, $isCentral ? null : $account->department)) {
                continue;
            }

            // A class must never be left pointing at an account about to go.
            Section::where('adviser_id', $account->id)->update(['adviser_id' => null]);

            $account->delete();
            $retired++;
        }

        return $retired;
    }

    /**
     * Put a test adviser in front of a class that nobody advises.
     *
     * The first version simply took section 1A, and took it with
     * updateOrCreate -- which quietly replaced whoever already held it. On a
     * database where advisers have been assigned, that is six real advisers
     * silently displaced, and their students' Academic concerns rerouted to an
     * account nobody can sign into. A test fixture must never take a class off
     * a real person.
     */
    private function giveAdviserAFreeClass(int $adviserId, string $course): void
    {
        $term = Section::currentTerm();

        foreach (range(1, User::finalYearFor($course)) as $year) {
            foreach (range('A', 'D') as $letter) {
                $section = $year.$letter;

                if (Section::adviserFor($course, $section)) {
                    continue;   // taken, and not ours to take
                }

                Section::updateOrCreate(
                    [
                        'course' => $course,
                        'section' => $section,
                        'school_year' => $term['school_year'],
                        'semester' => $term['semester'],
                    ],
                    ['adviser_id' => $adviserId]
                );

                return;
            }
        }

        $this->warn("Every class of {$course} already has an adviser, so its test adviser advises none.");
    }

    /**
     * @return 'created'|'updated'
     */
    private function upsert(string $email, array $attributes): string
    {
        $existing = User::where('email', $email)->first();

        if ($existing) {
            $existing->forceFill($attributes)->save();

            return 'updated';
        }

        User::create(array_merge($attributes, [
            'email' => $email,
            // There is no password sign-in; this only satisfies the column.
            'password' => Hash::make(Str::random(40)),
            'status' => 'approved',
            'email_verified_at' => now(),
        ]));

        return 'created';
    }

    private function roleId(string $name): int
    {
        $id = Role::where('name', $name)->value('id');

        if (! $id) {
            $this->error("No such role: {$name}. Run the role seeder first.");
            exit(self::FAILURE);
        }

        return $id;
    }

    private function listAccounts(): int
    {
        $accounts = $this->testAccounts()->with('role')->orderBy('email')->get();

        if ($accounts->isEmpty()) {
            $this->info('No test accounts exist.');

            return self::SUCCESS;
        }

        $this->table(['Email', 'Role', 'College / office', 'Programme', 'Class'], $accounts->map(fn (User $u) => [
            $u->email,
            optional($u->role)->name ?? '—',
            $u->department ?: '—',
            $u->course ?: '—',
            $u->section ?: '—',
        ])->all());

        $this->line($accounts->count().' test account(s).');

        return self::SUCCESS;
    }

    private function removeAccounts(): int
    {
        $accounts = $this->testAccounts()->get();

        if ($accounts->isEmpty()) {
            $this->info('No test accounts to remove.');

            return self::SUCCESS;
        }

        // A test adviser leaves its class advised by nobody rather than by a
        // missing row: the section stays, its adviser is cleared.
        $cleared = Section::whereIn('adviser_id', $accounts->pluck('id'))->update(['adviser_id' => null]);

        $removed = 0;

        foreach ($accounts as $account) {
            $account->delete();
            $removed++;
        }

        $this->info("Removed {$removed} test account(s).");

        if ($cleared) {
            $this->line("{$cleared} class(es) left without an adviser -- assign a real one in Manage Users.");
        }

        return self::SUCCESS;
    }

    private function testAccounts()
    {
        return User::where('email', 'like', self::PREFIX.'%');
    }

    private function collegeSlug(string $college): string
    {
        // Initials, so the address stays short and readable:
        // "College of Computer Studies" -> ccs.
        return Str::lower(collect(explode(' ', $college))
            ->reject(fn ($word) => in_array(Str::lower($word), ['of', 'and', 'the'], true))
            ->map(fn ($word) => Str::substr($word, 0, 1))
            ->implode(''));
    }

    private function shortCollege(string $college): string
    {
        return Str::upper($this->collegeSlug($college));
    }

    /**
     * A student number nobody else can be given.
     *
     * Student numbers are unique, so 432 random draws collide often enough to
     * fail a run. This is derived from the class instead: the same student
     * gets the same number every time, and no two classes can produce one
     * number. The 9 prefix keeps them clear of real CSPC numbering.
     */
    private function testStudentNumber(string $programme, string $section): string
    {
        return '9'.substr(sprintf('%08u', crc32($programme.' '.$section)), 0, 8);
    }

    /**
     * The programme, as an address-safe handle.
     *
     * Initials would be shorter, and they collide: in Engineering, Electrical
     * and Electronics both give "bee", Civil and Computer both give "bce". Two
     * programmes sharing a handle means one student's address overwrites
     * another's, so the whole name is used.
     */
    private function programmeSlug(string $programme): string
    {
        return Str::slug($programme);
    }
}
