<?php

namespace Database\Seeders\Faculty;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The non-teaching staff of the College of Computer Studies: its secretaries
 * and its laboratory technicians.
 *
 * All hold Faculty/Staff, not Instructor. The distinction is not seniority --
 * it is what routing does. Instructor receives Academic, Physical, Safety and
 * Others automatically, and a secretary has no business being handed a
 * student's grade dispute because she works in the same college. Faculty/Staff
 * is referral-gated: they see what somebody deliberately sends them, and
 * nothing else.
 *
 * The laboratory technicians are the reason that matters in practice. A dead
 * lab PC reaches General Services first, and the person who can actually look
 * at it is a technician in this list -- reachable by name in the referral
 * picker precisely because they hold an account.
 *
 * Sources:
 *   https://ccs.cspc.edu.ph/administrative-staff-secretaries/
 *   https://ccs.cspc.edu.ph/laboratory-technicians/
 *
 * Neither page publishes an address, so every account is created under
 * @placeholder.cspc.edu.ph. Replace each as it is confirmed:
 *
 *     php artisan faculty:email "Otares" heraddress@cspc.edu.ph
 *
 * Not called from DatabaseSeeder. Run it deliberately:
 *
 *     php artisan db:seed --class=Database\\Seeders\\Faculty\\CcsSupportStaffSeeder
 */
class CcsSupportStaffSeeder extends Seeder
{
    private const DOMAIN = '@placeholder.cspc.edu.ph';

    private const COLLEGE = 'College of Computer Studies';

    /** [name, designation as published] */
    private const STAFF = [
        // Administrative staff and secretaries.
        ['Stephanie Mae B. Otares', 'Administrative Aide II'],
        ['Vianne Faye S. Gastilo', 'Administrative Aide III'],
        ['Reychille Grace Tañamor', 'Administrative Aide II'],
        ['Jo Ann V. Baeta', 'Administrative Aide I'],

        // Laboratory technicians.
        ['Anthony L. Llabres', 'Laboratory Aide I, Laboratory Technician'],
        ['Carlo V. Panizal', 'Laboratory Aide I, Laboratory Technician'],
        ['John Peter G. Andalis', 'Laboratory Aide I, Laboratory Technician'],
        ['Sammy M. Saniel', 'Laboratory Aide I, Laboratory Technician'],
    ];

    public function run(): void
    {
        $role = Role::where('name', 'Faculty/Staff')->first();

        if (! $role) {
            $this->command?->warn("Skipped all: the 'Faculty/Staff' role does not exist.");

            return;
        }

        $created = 0;
        $skipped = 0;

        foreach (self::STAFF as [$name, $designation]) {
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
                    'department' => self::COLLEGE,
                    'status' => 'approved',
                    'email_verified_at' => now(),
                ]
            );

            $created++;
        }

        $this->command?->info("CCS support staff: {$created} seeded, {$skipped} skipped as already present.");
    }

    /** First name AND surname, against real accounts only. */
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

    private function placeholderAddress(string $name): string
    {
        $parts = $this->nameParts($name);

        $first = Str::slug($parts[0] ?? 'staff');
        $last = Str::slug((string) end($parts));

        return "{$first}.{$last}".self::DOMAIN;
    }
}
