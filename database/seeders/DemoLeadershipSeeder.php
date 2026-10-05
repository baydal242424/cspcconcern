<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Demo accounts for the tiers a concern climbs to: Dean, VPAA, Head of School.
 *
 * The escalation ladder is the part of routing hardest to see from a student's
 * seat -- a concern about a Program Chair goes to the Dean, and one about a
 * Dean goes to the VPAA -- and until now the only way to watch it arrive was
 * to sign in as the real person holding that post. These stand in for them.
 *
 * One Dean per college, because a dean's college is what routing matches on.
 * One VPAA and one Head of School, because there is one of each.
 *
 * WHAT THESE WILL AND WILL NOT DO. They can be signed into, and they show what
 * the role sees. They will not usually RECEIVE a live concern: findHandler()
 * takes the first eligible person in the role, and the real seeded office
 * accounts were created first. That is the safer way round -- a demo account
 * quietly taking a real student's concern is why the staff demo accounts were
 * removed once already (see DemoAccountSeeder) -- so to watch one arrive,
 * file as a demo student and refer it to the demo account by hand from the
 * concern page.
 *
 * Addresses are on placeholder.cspc.edu.ph, so a notification to one cannot
 * reach a real mailbox, and they start "demo." like the rest:
 *
 *     php artisan tinker --execute="App\Models\User::where('email','like','demo.%')->delete();"
 *
 * Not called from DatabaseSeeder. Run it deliberately:
 *
 *     php artisan db:seed --class=Database\\Seeders\\DemoLeadershipSeeder
 */
class DemoLeadershipSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('Creating demo DEANS and a demo VPAA on a PRODUCTION system. Remove them when you are done.');
        }

        $created = 0;

        // The colleges as the real deans are filed under, Graduate School
        // included -- a demo dean in a college nobody is enrolled in would
        // never appear in the right place.
        $colleges = User::whereHas('role', fn ($q) => $q->where('name', 'Dean'))
            ->whereNotNull('department')
            ->where('email', 'not like', 'demo.%')
            ->distinct()
            ->orderBy('department')
            ->pluck('department');

        if ($colleges->isEmpty()) {
            $colleges = collect(array_keys(User::COURSES_BY_COLLEGE));
        }

        foreach ($colleges as $college) {
            $this->place(
                'Dean',
                'Demo Dean ('.Str::after($college, 'College of ').')',
                'demo.dean.'.Str::slug($college).'@placeholder.cspc.edu.ph',
                $college,
                $created
            );
        }

        $this->place(
            'Vice President for Academic Affairs',
            'Demo VPAA',
            'demo.vpaa@placeholder.cspc.edu.ph',
            'Office of the Vice President for Academic Affairs',
            $created
        );

        $this->place(
            'Head of School',
            'Demo Head of School',
            'demo.head-of-school@placeholder.cspc.edu.ph',
            'Office of the President',
            $created
        );

        $this->command?->info("Demo leadership accounts: {$created} ({$colleges->count()} deans, one VPAA, one Head of School).");
        $this->command?->warn('They are in the demo sign-in list. Routing still prefers the real office accounts, '
            .'so refer a concern to one by hand to watch it arrive.');
    }

    private function place(string $roleName, string $name, string $email, string $department, int &$created): void
    {
        $role = Role::where('name', $roleName)->first();

        if (! $role) {
            $this->command?->warn("The '{$roleName}' role does not exist; skipped {$name}.");

            return;
        }

        User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make(Str::random(40)),
                'role_id' => $role->id,
                'department' => $department,
                // No programme and no section: a course would make
                // findHandler() prefer them for that programme's concerns,
                // which is exactly what a demo account must not do.
                'course' => null,
                'section' => null,
                'status' => 'approved',
                'email_verified_at' => now(),
            ]
        );

        $created++;
        $this->command?->line("  {$name} — {$department}");
    }
}
