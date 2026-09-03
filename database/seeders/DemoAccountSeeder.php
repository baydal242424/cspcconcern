<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * One account per role, for demonstrating the system.
 *
 * Deliberately NOT called from DatabaseSeeder. A deploy runs `db:seed`, and
 * these accounts have no business appearing on a system holding real reports
 * simply because somebody shipped a bug fix. Run it on purpose:
 *
 *     php artisan db:seed --class=Database\\Seeders\\DemoAccountSeeder
 *
 * They are only reachable through the demo sign-in dropdown, which is itself
 * off unless DEMO_LOGIN_ENABLED is set. Two switches, both off by default, and
 * both have to be on before any of this is usable. That is the point: an
 * account that bypasses Google sign-in is a bypass of the only authentication
 * this system has.
 *
 * Every address starts with "demo." so they can be found and removed in one
 * query when the demonstration is over:
 *
 *     User::where('email', 'like', 'demo.%')->delete();
 *
 * Deleting a user cascades to the concerns they filed, which for these is what
 * you want -- test data goes with the test account.
 */
class DemoAccountSeeder extends Seeder
{
    /**
     * [role, name, email, department, course]
     *
     * A student carries a college and a programme because routing reads both:
     * without them a demo concern cannot reach the right chair or dean, and the
     * one thing worth demonstrating stops working.
     */
    private const ACCOUNTS = [
        ['Student', 'Demo Student', 'demo.student@my.cspc.edu.ph', 'College of Computer Studies', 'BS Information Systems'],
        ['Instructor', 'Demo Instructor', 'demo.instructor@cspc.edu.ph', 'College of Computer Studies', null],
        ['Program Chair', 'Demo Program Chair', 'demo.chair@cspc.edu.ph', 'College of Computer Studies', 'BS Information Systems'],
        ['Dean', 'Demo Dean', 'demo.dean@cspc.edu.ph', 'College of Computer Studies', null],
        ['Guidance Counselor', 'Demo Counselor', 'demo.counselor@cspc.edu.ph', 'Guidance Office', null],
        ['Gender and Development', 'Demo GAD Officer', 'demo.gad@cspc.edu.ph', 'Center for Gender and Development', null],
        ['General Services', 'Demo Maintenance Staff', 'demo.gsu@cspc.edu.ph', 'General Services Unit', null],
        ['Faculty/Staff', 'Demo Office Staff', 'demo.office@cspc.edu.ph', 'Records and Freedom of Information Unit', null],
        ['Admin', 'Demo Administrator', 'demo.admin@cspc.edu.ph', 'Administration', null],
        ['Head of School', 'Demo Head of School', 'demo.head@cspc.edu.ph', 'Office of the President', null],
        ['Vice President for Academic Affairs', 'Demo VPAA', 'demo.vpaa@cspc.edu.ph', 'Office of the Vice President for Academic Affairs', null],
    ];

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('Creating demo accounts on a PRODUCTION system.');
            $this->command?->warn('They are inert while DEMO_LOGIN_ENABLED is unset, but remove them when you are done.');
        }

        $created = 0;

        foreach (self::ACCOUNTS as [$roleName, $name, $email, $department, $course]) {
            $role = Role::where('name', $roleName)->first();

            if (! $role) {
                $this->command?->warn("Skipped {$name}: the '{$roleName}' role does not exist.");
                continue;
            }

            $user = User::firstOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    // Never used. Sign-in is Google-only, and these accounts are
                    // reached through the demo dropdown, which authenticates
                    // nobody -- it just picks a row. A random hash means the
                    // column holds nothing anyone could guess or reuse.
                    'password' => Hash::make(Str::random(40)),
                    'role_id' => $role->id,
                    'department' => $department,
                    'course' => $course,
                    'student_id' => $roleName === 'Student' ? '2026-DEMO-001' : null,
                    'status' => 'approved',
                    // Without this the auth middleware bounces them before any
                    // page renders, and the dropdown appears to do nothing.
                    'email_verified_at' => now(),
                ]
            );

            if ($user->wasRecentlyCreated) {
                $created++;
            }
        }

        $this->command?->info("Demo accounts ready ({$created} created, ".(count(self::ACCOUNTS) - $created).' already present).');
        $this->command?->info('Enable them with DEMO_LOGIN_ENABLED=true, and remove them afterwards.');
    }
}
