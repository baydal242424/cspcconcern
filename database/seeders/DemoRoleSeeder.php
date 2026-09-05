<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\Section;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * One demo account per role, for seeing the app through each pair of eyes.
 *
 * What makes an account appear in the demo dropdown is not a flag -- it is
 * three conditions, all checked in AuthController::demoAccounts():
 *
 *   1. google_id is NULL   nobody real has claimed it. The moment a person
 *                          signs in with Google, their id is stamped and they
 *                          drop out of the list permanently. That single
 *                          condition is what stops this being an
 *                          impersonation tool.
 *   2. status is approved  the same rule sign-in uses.
 *   3. it has a role       the dropdown groups by role.
 *
 * The "demo." address prefix does two more things: it lifts the account into
 * its own group at the top of the dropdown, and it makes the whole set
 * removable in one line.
 *
 * Each role needs different details or the account lands somewhere useless:
 *
 *   Student        college + programme + section, or the profile gate stops
 *                  them before any page renders.
 *   Instructor     a college, or findHandler() skips them -- they look set up
 *                  and receive nothing.
 *   Program Chair  a college AND a programme; the chair tier is matched by
 *                  programme first.
 *   Adviser        not a role you assign. Advising is a row in `sections`, so
 *                  this seeder gives the demo instructor a class to advise.
 *   Offices        a department that reads like the office, since that is
 *                  what a student sees on the concern.
 *
 * A demo STAFF account is not free of consequence: routing cannot tell it from
 * a real one, so it can be assigned a live concern that nobody can sign in to
 * act on. That already happened once here. Remove them when the demonstration
 * is over:
 *
 *     php artisan tinker --execute="App\Models\User::where('email','like','demo.%')->delete();"
 *
 * Not called from DatabaseSeeder. Run it deliberately:
 *
 *     php artisan db:seed --class=Database\\Seeders\\DemoRoleSeeder
 */
class DemoRoleSeeder extends Seeder
{
    /** The college most demo staff belong to: it has the real section data. */
    private const COLLEGE = 'College of Computer Studies';

    /**
     * [role, name, department, programme].
     *
     * @var list<array{0:string, 1:string, 2:?string, 3:?string}>
     */
    private const ACCOUNTS = [
        ['Student', 'Demo Student', self::COLLEGE, 'BS Information Systems'],
        ['Instructor', 'Demo Instructor', self::COLLEGE, null],
        ['Program Chair', 'Demo Program Chair', self::COLLEGE, 'BS Information Systems'],
        ['Dean', 'Demo Dean', self::COLLEGE, null],
        ['Guidance Counselor', 'Demo Counselor', 'Guidance Office', null],
        ['Gender and Development', 'Demo GAD Officer', 'Center for Gender and Development', null],
        ['General Services', 'Demo General Services', 'General Services Unit', null],
        ['Staff Admin', 'Demo Staff Admin', 'Student Registration and Records', null],
        ['Vice President for Academic Affairs', 'Demo VPAA', 'Academic Affairs', null],
        ['Head of School', 'Demo Head of School', 'Office of the President', null],
        // Faculty/Staff with NO department: the state a brand-new employee is
        // in, which is what makes the staff sign-up form appear.
        ['Faculty/Staff', 'Demo New Staff', null, null],
    ];

    /** The section the demo student sits in, and the demo instructor advises. */
    private const SECTION = '3A';

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('Creating demo accounts on a PRODUCTION system. Remove them when you are done.');
        }

        $made = [];

        foreach (self::ACCOUNTS as [$roleName, $name, $department, $course]) {
            $role = Role::where('name', $roleName)->first();

            if (! $role) {
                $this->command?->warn("Skipped {$name}: the '{$roleName}' role does not exist.");

                continue;
            }

            $isStudent = $roleName === 'Student';

            $made[$roleName] = User::updateOrCreate(
                ['email' => $this->address($name)],
                [
                    'name' => $name,
                    // Never used. Sign-in is Google-only; the column is NOT
                    // NULL, so it gets a random value nobody can type.
                    'password' => Hash::make(Str::random(40)),
                    'role_id' => $role->id,
                    'department' => $department,
                    'course' => $course,
                    // A student is IN a class. Staff are in charge of one, and
                    // that lives in `sections` -- see below.
                    'section' => $isStudent ? self::SECTION : null,
                    'student_id' => $isStudent ? '2026-DEMO-001' : null,
                    'employee_id' => $isStudent ? null : 'DEMO-'.strtoupper(Str::random(5)),
                    'status' => 'approved',
                    // Without this the auth middleware turns the account away
                    // before any page renders.
                    'email_verified_at' => now(),
                ]
            );
        }

        // Advising is a relationship, not a role: give the demo instructor the
        // demo student's class, so filing as the student demonstrates the whole
        // student -> section -> adviser chain rather than a fallback.
        if (isset($made['Instructor'])) {
            $term = Section::currentTerm();

            Section::updateOrCreate(
                [
                    'course' => 'BS Information Systems',
                    'section' => self::SECTION,
                    'school_year' => $term['school_year'],
                    'semester' => $term['semester'],
                ],
                ['adviser_id' => $made['Instructor']->id]
            );
        }

        $this->command?->info(count($made).' demo accounts ready. They appear in one group at the top of the sign-in dropdown.');
        $this->command?->info('Demo Instructor advises BS Information Systems '.self::SECTION.', which Demo Student is in.');
        $this->command?->warn('Demo STAFF can be assigned live concerns and cannot sign in to act on them. Remove them after the demonstration: '
            ."App\\Models\\User::where('email','like','demo.%')->delete();");
    }

    /**
     * demo.demo-instructor@cspc.edu.ph. The prefix is the convention the rest
     * of the app relies on: it groups these at the top of the dropdown and
     * makes the whole set deletable in one statement.
     */
    private function address(string $name): string
    {
        // "Demo Instructor" -> demo.instructor@. The leading word is dropped
        // because the prefix already says it: demo.demo-instructor@ reads as a
        // mistake.
        $withoutPrefix = preg_replace('/^Demo\s+/i', '', $name);

        return 'demo.'.Str::slug($withoutPrefix).'@cspc.edu.ph';
    }
}
