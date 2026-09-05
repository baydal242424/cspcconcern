<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * One account for trying the staff sign-up form.
 *
 * A brand-new employee is exactly what this has to demonstrate, and a new
 * employee has nothing: the Google callback creates them with an email, the
 * lowest staff role, and no college. That empty state IS the demo -- sign in
 * as this account and the profile form appears, because
 * User::needsStaffProfile() is true for any employee with no department.
 *
 * So it is deliberately blank. Filling in a college here would skip the whole
 * thing straight to the concerns list and demonstrate nothing.
 *
 * Re-runnable: it wipes the details back out, so the form can be walked
 * through again after a previous run left the account set up.
 *
 *     php artisan db:seed --class=Database\\Seeders\\StaffSignupDemoSeeder
 *
 * Remove it with the other demo accounts -- the address starts "demo.":
 *
 *     php artisan tinker --execute="App\Models\User::where('email','like','demo.%')->delete();"
 */
class StaffSignupDemoSeeder extends Seeder
{
    private const EMAIL = 'demo.newstaff@cspc.edu.ph';

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('Creating a demo account on a PRODUCTION system. Remove it when you are done.');
        }

        // Faculty/Staff is what the Google callback assigns every new employee:
        // the address proves they work here, and nothing more.
        $role = Role::where('name', 'Faculty/Staff')->first();

        if (! $role) {
            $this->command?->warn("The 'Faculty/Staff' role does not exist; nothing seeded.");

            return;
        }

        User::updateOrCreate(
            ['email' => self::EMAIL],
            [
                'name' => 'Demo New Staff',
                'password' => Hash::make(Str::random(40)),
                'role_id' => $role->id,
                // Blank on purpose -- this is the state the form exists for.
                'department' => null,
                'course' => null,
                'section' => null,
                'requested_role_id' => null,
                'role_requested_at' => null,
                'status' => 'approved',
                'email_verified_at' => now(),
            ]
        );

        $this->command?->info('Demo staff account ready: '.self::EMAIL);
        $this->command?->info('Sign in as it from the demo dropdown; the staff profile form appears because it has no college yet.');
    }
}
