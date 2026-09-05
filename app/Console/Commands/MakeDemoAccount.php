<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\Section;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * One demo account, for whichever programme and role you name.
 *
 * The tinker one-liner it replaces was seven lines of exact strings, and the
 * three that mattered were the easiest to get wrong: a department that has to
 * match User::COURSES_BY_COLLEGE character for character, a role name whose
 * capitalisation matters on SQLite, and the course and section a Student
 * cannot reach a page without. All three fail silently -- the account is
 * created, and it simply never works.
 *
 * Naming a programme is enough here: the college is looked up from it, so the
 * pair can never disagree.
 *
 *     php artisan demo:account --list
 *     php artisan demo:account "BS Nursing"
 *     php artisan demo:account "BS Civil Engineering" --year=3 --class=B
 *     php artisan demo:account "BS Information Systems" --role=Instructor
 *     php artisan demo:account --role="Guidance Counselor"
 *
 * Every address starts "demo.", which is what groups these at the top of the
 * sign-in dropdown and what the cleanup matches:
 *
 *     php artisan tinker --execute="App\Models\User::where('email','like','demo.%')->delete();"
 */
class MakeDemoAccount extends Command
{
    protected $signature = 'demo:account
        {course? : The programme, e.g. "BS Nursing". Required for a Student}
        {--role=Student : The role to create}
        {--year=1 : Year level, 1-6, for a Student}
        {--class=A : Class letter within the year}
        {--adviser : Also make this staff member the adviser of that class}
        {--list : Print every college and programme, then stop}';

    protected $description = 'Create one demo account for a chosen programme and role';

    public function handle(): int
    {
        if ($this->option('list')) {
            return $this->listProgrammes();
        }

        $roleName = (string) $this->option('role');
        $role = Role::where('name', $roleName)->first();

        if (! $role) {
            $this->error("No role named '{$roleName}'.");
            $this->line('  '.Role::orderBy('name')->pluck('name')->implode(' · '));

            return self::FAILURE;
        }

        $course = $this->argument('course');
        $isStudent = $role->name === 'Student';

        if ($isStudent && ! $course) {
            $this->error('A Student needs a programme, or the profile form stops them before any page renders.');
            $this->line('  php artisan demo:account --list');

            return self::FAILURE;
        }

        // The college is DERIVED from the programme, never typed. Nothing in
        // the schema stops a BSIT student being filed under Health Sciences,
        // and routing would then look for a handler in the wrong college.
        $college = $course ? $this->collegeFor($course) : $this->officeFor($role->name);

        if ($course && ! $college) {
            $this->error("No programme named '{$course}'.");
            $this->line('  php artisan demo:account --list');

            return self::FAILURE;
        }

        $section = ((int) $this->option('year')).strtoupper((string) $this->option('class'));

        if (! preg_match('/^[1-6][A-Z]$/', $section)) {
            $this->error("'{$section}' is not a section. Year is 1-6 and the class is a single letter.");

            return self::FAILURE;
        }

        $user = User::updateOrCreate(
            ['email' => $this->address($role->name, $course, $isStudent)],
            [
                'name' => $this->displayName($role->name, $course, $section, $isStudent),
                // Never used: sign-in is Google-only. The column is NOT NULL,
                // so it gets a value nobody could type.
                'password' => Hash::make(Str::random(40)),
                'role_id' => $role->id,
                'department' => $college,
                // Only a Student and a Program Chair carry a programme. On
                // anybody else findHandler() would start preferring them for
                // that programme's concerns.
                'course' => in_array($role->name, ['Student', 'Program Chair'], true) ? $course : null,
                'section' => $isStudent ? $section : null,
                'student_id' => $isStudent ? '2026-DEMO-'.strtoupper(Str::random(4)) : null,
                'employee_id' => $isStudent ? null : 'DEMO-'.strtoupper(Str::random(5)),
                'status' => 'approved',
                // Without this the auth middleware turns the account away
                // before any page renders.
                'email_verified_at' => now(),
            ]
        );

        $this->info("{$user->name}");
        $this->line("  email       {$user->email}");
        $this->line("  role        {$role->name}");
        $this->line('  college     '.($college ?: '(none — this account gets the staff sign-up form)'));

        if ($course) {
            $this->line("  programme   {$course}");
        }

        if ($isStudent) {
            $this->line("  section     {$section}");

            $adviser = Section::adviserFor($course, $section);
            $this->line('  adviser     '.($adviser
                ? $adviser->name
                : 'nobody advises '.$course.' '.$section.' — academic concerns fall back to the college'));
        }

        // Advising is a relationship, not a role: it is a row in `sections`,
        // which is why one instructor can advise several classes.
        if ($this->option('adviser') && ! $isStudent && $course) {
            $term = Section::currentTerm();

            Section::updateOrCreate(
                [
                    'course' => $course,
                    'section' => $section,
                    'school_year' => $term['school_year'],
                    'semester' => $term['semester'],
                ],
                ['adviser_id' => $user->id]
            );

            $this->line("  advises     {$course} {$section}");
        }

        if (! $isStudent) {
            $this->warn('  Demo staff can be assigned live concerns and cannot sign in to act on them.');
        }

        return self::SUCCESS;
    }

    private function listProgrammes(): int
    {
        foreach (User::COURSES_BY_COLLEGE as $college => $courses) {
            $this->info($college);

            foreach ($courses as $course) {
                $this->line('    '.$course);
            }
        }

        $this->newLine();
        $this->line('  php artisan demo:account "BS Nursing"');

        return self::SUCCESS;
    }

    private function collegeFor(string $course): ?string
    {
        foreach (User::COURSES_BY_COLLEGE as $college => $courses) {
            if (in_array($course, $courses, true)) {
                return $college;
            }
        }

        return null;
    }

    /**
     * Where a staff role sits when no programme was named. Faculty/Staff is
     * left blank on purpose: that empty state is what triggers the staff
     * sign-up form, which is the thing worth demonstrating.
     */
    private function officeFor(string $roleName): ?string
    {
        return match ($roleName) {
            'Guidance Counselor' => 'Guidance Office',
            'Gender and Development' => 'Center for Gender and Development',
            'General Services' => 'General Services Unit',
            'Staff Admin', 'System Admin' => 'Student Registration and Records',
            'Vice President for Academic Affairs' => 'Academic Affairs',
            'Head of School' => 'Office of the President',
            'Faculty/Staff' => null,
            default => 'College of Computer Studies',
        };
    }

    private function address(string $roleName, ?string $course, bool $isStudent): string
    {
        $who = $course
            ? Str::slug($course).($isStudent ? '' : '.'.Str::slug($roleName))
            : Str::slug($roleName);

        // Students hold my.cspc.edu.ph, staff hold cspc.edu.ph -- the same
        // split the real sign-in uses to tell them apart.
        return 'demo.'.$who.'@'.($isStudent ? 'my.cspc.edu.ph' : 'cspc.edu.ph');
    }

    private function displayName(string $roleName, ?string $course, string $section, bool $isStudent): string
    {
        if ($isStudent) {
            return 'Demo '.$course.' '.$section;
        }

        return 'Demo '.$roleName.($course ? ' — '.$course : '');
    }
}
