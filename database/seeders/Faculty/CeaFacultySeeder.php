<?php

namespace Database\Seeders\Faculty;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Real named faculty of the College of Engineering and Architecture.
 *
 * Seeded rather than left to arrive on their own because the college had no
 * instructor at all: with Computer Studies holding the only one, a CEA
 * student's academic or safety concern fell through to an instructor in
 * another college entirely. One real name here fixes that for the whole
 * college; the rest can arrive by signing in.
 *
 * Only confirmed addresses go in. Accounts are matched to people by EMAIL on
 * first Google sign-in, so a guessed address does not merely fail -- the
 * person creates a second, empty account while the seeded one stays in the
 * instructor picker as a name whose concerns reach nobody. The three confirmed
 * CSPC addresses seen so far follow three different patterns
 * (jeremyneo@, mkiarasapinoso@, joyaguado@), so there is no rule to derive the
 * others from. Names without an address are listed below as comments and stay
 * comments until somebody confirms them.
 */
class CeaFacultySeeder extends Seeder
{
    private const COLLEGE = 'College of Engineering and Architecture';

    /**
     * [email, full name, role, programme]
     *
     * The fourth field is the programme a Program Chair covers, and null for
     * anyone who is not programme-scoped -- storing one on an ordinary
     * instructor would make findHandler() prefer them for that programme's
     * concerns ahead of their colleagues.
     */
    private const FACULTY = [
        ['joyaguado@cspc.edu.ph', 'April Joy F. Aguado', 'Instructor', null],
    ];

    /*
     * Confirmed CEA staff, no confirmed address yet. Listed so the gap is
     * visible rather than forgotten, and so nobody is tempted to fill it with
     * a guess. Move a name up into FACULTY once its mailbox is confirmed.
     *
     *   - Mark Buena
     *   - Daisylen De Guzman Alano
     *   - Angelica L. Bongcayao
     *   - Vincent E. Malapo
     *   - Wenceslao Gavina
     *   - Christoper Oares
     *   - Ruel Romulo
     *   - Rizza T. Loquias
     *   - Rosanova Oliveros
     *   - Harold Jan R. Terano
     *   - Eugene Barbonio
     *   - Victor Isaac
     *   - Peter Turiano
     *   - Alvin Badong
     *   - James Sias
     *   - Juvy Bustamante
     *
     * Two of these hold posts recorded elsewhere on cspc.edu.ph -- Harold Jan
     * R. Terano directs Research and Development Services, and Juvy Bustamante
     * heads the Center for Quality Assurance. If they teach as well, they
     * belong here; if the unit post is their whole role, they belong in
     * UserSeeder as office staff instead. Worth confirming before either.
     *
     * None of them need seeding to use the system: anyone signing in with a
     * cspc.edu.ph address is created as Faculty/Staff automatically, and an
     * administrator sets Instructor and the college at /admin/users.
     */

    public function run(): void
    {
        foreach (self::FACULTY as [$email, $name, $roleName, $course]) {
            $role = Role::where('name', $roleName)->first();

            // A role that has not been seeded yet would otherwise fail on a
            // null id and take the whole deploy's seeding down with it.
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
                    'course' => $course,
                    'status' => 'approved',
                    // A college address is verified by definition: Google
                    // sign-in is the only way in and it proves control of the
                    // mailbox. Without this the account is bounced by the auth
                    // middleware before it can be assigned anything.
                    'email_verified_at' => now(),
                ]
            );
        }
    }
}
