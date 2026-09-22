<?php

namespace App\Console\Commands;

use App\Http\Controllers\AuthController;
use App\Models\Role;
use App\Models\Section;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Add or update one account in a single line.
 *
 * user:role is the bootstrap door -- it grants a role and nothing else, which
 * is all the first Admin needs. This is the other job: putting a whole person
 * on file, with the fields routing actually reads. Doing that by hand meant a
 * tinker one-liner with seven exact strings, and the three that mattered
 * failed silently: a college that must match COURSES_BY_COLLEGE character for
 * character, a programme that must belong to that college, and a section in
 * the one shape Section::adviserFor() matches.
 *
 *   php artisan user:add juan@my.cspc.edu.ph --name="Juan Dela Cruz" \
 *       --college="College of Computer Studies" --program="BS Information Systems" \
 *       --year=4 --class=A --student-id=231001234
 *
 *   php artisan user:add rosa@cspc.edu.ph --role=Instructor --name="Rosa Delgado" \
 *       --college="College of Computer Studies" --program="BS Information Systems" \
 *       --year=2 --class=C --advises
 *
 * An address that has never signed in gets a dormant account: no google_id, so
 * it activates on that person's first CSPC Mail sign-in, keeping whatever is
 * set here. An address already on file is UPDATED -- only the options given
 * are touched, so nothing already recorded is wiped by omission.
 */
class AddUser extends Command
{
    protected $signature = 'user:add
        {email? : CSPC address, @my.cspc.edu.ph for a student or @cspc.edu.ph for staff}
        {--role= : Role name (default: Student for my.cspc.edu.ph, Faculty/Staff otherwise)}
        {--name= : Full name, as it should appear to students}
        {--college= : College or office, exactly as the system spells it}
        {--program= : Programme, for a student or a Program Chair}
        {--year= : Year level, 1-6}
        {--class= : Class letter within the year, e.g. A}
        {--student-id= : Student number}
        {--employee-id= : Staff number}
        {--advises : Also make this staff member the class adviser of --program --year --class}
        {--list : Print the roles, colleges and programmes this accepts, then stop}';

    protected $description = 'Add or update one CSPC account, with the details routing reads';

    /** The two domains sign-in accepts, mirroring AuthController's DOMAIN_ROLES. */
    private const DOMAIN_ROLES = [
        'my.cspc.edu.ph' => 'Student',
        'cspc.edu.ph' => 'Faculty/Staff',
    ];

    public function handle(): int
    {
        // Read-only, so it needs no address: --list is what you run when you
        // cannot remember how the system spells a college.
        if ($this->option('list')) {
            return $this->printChoices();
        }

        if (! $this->argument('email')) {
            $this->error('Which address? Give one, or run this with --list to see the spellings it accepts.');

            return self::FAILURE;
        }

        $email = strtolower(trim($this->argument('email')));
        $domain = (string) substr(strrchr($email, '@') ?: '', 1);

        // An account on any other domain can never be used: the Google
        // callback turns that address away at sign-in.
        if (! isset(self::DOMAIN_ROLES[$domain])) {
            $this->error("{$email} is not a CSPC address.");
            $this->line('Use @my.cspc.edu.ph for a student, or @cspc.edu.ph for staff.');

            return self::FAILURE;
        }

        $existing = User::where('email', $email)->first();

        // Given, else whatever they already are, else what the domain implies.
        $roleName = $this->option('role')
            ?: optional(optional($existing)->role)->name
            ?: self::DOMAIN_ROLES[$domain];

        $role = Role::where('name', $roleName)->first();

        if (! $role) {
            $this->error("No such role: {$roleName}");
            $this->line('Available: '.Role::orderBy('name')->pluck('name')->implode(', '));

            return self::FAILURE;
        }

        $college = $this->option('college');
        $program = $this->option('program');
        $year = $this->option('year');
        $class = $this->option('class');

        if ($college !== null && ! $this->collegeExists($college)) {
            $this->error("No such college or office: {$college}");
            $this->line('Run this with --list to see the exact spellings.');

            return self::FAILURE;
        }

        if ($program !== null && ! in_array($program, User::allCourses(), true)) {
            $this->error("No such programme: {$program}");
            $this->line('Run this with --list to see the exact spellings.');

            return self::FAILURE;
        }

        // A programme belongs to one college. Filed under the wrong one, every
        // college-scoped lookup disagrees with the row and the concern reaches
        // neither college's staff.
        $collegeForCheck = $college ?? optional($existing)->department;

        if ($program !== null && isset(User::COURSES_BY_COLLEGE[$collegeForCheck])
            && ! in_array($program, User::COURSES_BY_COLLEGE[$collegeForCheck], true)) {
            $this->error("{$program} is not offered by {$collegeForCheck}.");

            return self::FAILURE;
        }

        $section = null;

        if ($year !== null || $class !== null) {
            if ($year === null || $class === null) {
                $this->error('Give both --year and --class, or neither.');

                return self::FAILURE;
            }

            if (! preg_match('/^[1-6]$/', (string) $year) || ! preg_match('/^[A-Za-z]$/', (string) $class)) {
                $this->error('--year is 1-6 and --class is a single letter, e.g. --year=4 --class=A');

                return self::FAILURE;
            }

            $programForYears = $program ?? optional($existing)->course;

            if ($programForYears && (int) $year > User::finalYearFor($programForYears)) {
                $this->error("{$programForYears} runs for ".User::finalYearFor($programForYears).' years.');

                return self::FAILURE;
            }

            $section = $year.strtoupper($class);
        }

        $isStudentRole = $role->name === 'Student';

        // Only what was asked for. Everything else on an existing account
        // stays: a command that wiped a college by omission would be worse
        // than no command.
        $changes = array_filter([
            'name' => $this->option('name'),
            'department' => $college,
            'course' => $program,
            'section' => $section,
            'student_id' => $this->option('student-id'),
            'employee_id' => $this->option('employee-id'),
        ], fn ($value) => $value !== null);

        $changes['role_id'] = $role->id;

        if ($existing) {
            $existing->forceFill($changes)->save();
            $user = $existing->refresh();
            $this->info("Updated {$user->name} <{$email}> as {$role->name}.");
        } else {
            $user = User::create(array_merge([
                'name' => $email,
                'email' => $email,
                // There is no password sign-in; this only satisfies the column.
                'password' => Hash::make(str()->random(40)),
                'status' => 'approved',
                'email_verified_at' => now(),
            ], $changes));

            $this->info("Created {$user->name} <{$email}> as {$role->name}.");
            $this->line('Dormant until they sign in with CSPC Mail, which keeps these details.');
        }

        if ($this->option('advises')) {
            if ($isStudentRole) {
                $this->warn('A student advises nobody, so --advises was ignored.');
            } elseif (! $user->course || ! $user->section) {
                $this->warn('--advises needs --program, --year and --class, so it was ignored.');
            } else {
                $term = Section::currentTerm();

                $row = Section::updateOrCreate(
                    [
                        'course' => $user->course,
                        'section' => $user->section,
                        'school_year' => $term['school_year'],
                        'semester' => $term['semester'],
                    ],
                    ['adviser_id' => $user->id]
                );

                $this->info("Now advises {$row->course} {$row->section} ({$row->school_year} {$row->semester}).");
            }
        }

        $this->newLine();
        $this->table(['Field', 'Value'], [
            ['Name', $user->name],
            ['Email', $user->email],
            ['Role', $role->name],
            ['College / office', $user->department ?: '—'],
            ['Programme', $user->course ?: '—'],
            ['Year & class', $user->section ?: '—'],
            ['Student ID', $user->student_id ?: '—'],
            ['Employee ID', $user->employee_id ?: '—'],
            ['Signed in yet', $user->google_id ? 'yes' : 'not yet'],
        ]);

        return self::SUCCESS;
    }

    private function collegeExists(string $college): bool
    {
        return array_key_exists($college, User::COURSES_BY_COLLEGE)
            || in_array($college, AuthController::UNITS, true)
            || User::where('department', $college)->exists();
    }

    private function printChoices(): int
    {
        $this->info('Roles');
        $this->line('  '.Role::orderBy('name')->pluck('name')->implode(', '));

        $this->newLine();
        $this->info('Colleges and their programmes');

        foreach (User::COURSES_BY_COLLEGE as $college => $courses) {
            $this->line("  {$college}");

            foreach ($courses as $course) {
                $this->line("     {$course}");
            }
        }

        $this->newLine();
        $this->info('Offices and units');

        foreach (AuthController::UNITS as $unit) {
            $this->line("  {$unit}");
        }

        return self::SUCCESS;
    }
}
