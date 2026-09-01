<?php

namespace Database\Seeders\Faculty;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Real named faculty of the College of Computer Studies.
 *
 * Kept apart from UserSeeder on purpose. UserSeeder holds OFFICE mailboxes
 * (ccs@, registrar@, gsu@) which belong to a post rather than a person and
 * survive whoever currently fills it. This file holds actual people, and the
 * two ages differently: a person leaves, changes programme or gets promoted,
 * and their row here has to change with them, while ccs@cspc.edu.ph does not.
 *
 * Only verified addresses go in. An account is matched to a human by EMAIL on
 * their first Google sign-in, so a guessed address does not merely fail -- it
 * creates a second, empty account while the seeded one lingers in the referral
 * picker as a name that can never be reached. Names without a confirmed
 * address are listed as comments below instead, and stay comments until
 * somebody confirms them.
 *
 * Nothing here is required for the system to work: an instructor who signs in
 * with a cspc.edu.ph address is provisioned as Faculty/Staff automatically.
 * Seeding them early only means routing can reach them before they ever log
 * in, which matters for the people a concern is most likely to be referred to.
 *
 * Source for names and designations: https://ccs.cspc.edu.ph/faculty/
 */
class CcsFacultySeeder extends Seeder
{
    private const COLLEGE = 'College of Computer Studies';

    /**
     * [email, full name, role]
     *
     * Add a row only once the address is confirmed -- see the class comment.
     */
    private const FACULTY = [
        // Information Systems Department. Instructor I, Rise Lab In-Charge.
        ['jeremyneo@cspc.edu.ph', 'Jeremy Jireh Neo', 'Faculty/Staff'],
    ];

    /*
     * Confirmed staff, no confirmed address yet. Listed so the gap is visible
     * rather than forgotten, and so nobody is tempted to guess an address to
     * fill it. Move a name up into FACULTY once its mailbox is confirmed.
     *
     * Program Chairs (the referral tier between an instructor and the dean,
     * so these are the ones worth chasing first):
     *   - Tiffany Lyn Pandes      -- BSCS Program Chair
     *   - Freddie Prianes         -- BSIT Program Chair
     *   - Jonuel Rey Colle        -- BSIS Program Chair
     *   - Ime Amor A. Mortel      -- BLIS Program Chair
     *
     * The OIC Dean, Rosel O. Onesa, is already reachable through the office
     * mailbox ccs@cspc.edu.ph seeded in UserSeeder, so she is not repeated
     * here as a person.
     */

    public function run(): void
    {
        foreach (self::FACULTY as [$email, $name, $roleName]) {
            $role = Role::where('name', $roleName)->first();

            // A role that has not been seeded yet would otherwise fail on a
            // null id and take the whole deploy's seeding with it.
            if (! $role) {
                $this->command?->warn("Skipped {$name}: the '{$roleName}' role does not exist.");
                continue;
            }

            User::firstOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'password' => Hash::make(Str::random(40)),
                    'role_id' => $role->id,
                    'department' => self::COLLEGE,
                    'status' => 'approved',
                    // Their college address is verified by definition -- Google
                    // sign-in is the only way in, and it proves control of the
                    // mailbox. Without this the account is bounced by the auth
                    // middleware before it can be assigned anything.
                    'email_verified_at' => now(),
                ]
            );
        }
    }
}
