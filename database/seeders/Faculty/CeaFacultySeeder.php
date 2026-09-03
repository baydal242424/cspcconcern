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
        ['alvinbadong@cspc.edu.ph', 'Alvin Badong', 'Instructor', null],
        ['markbuena@cspc.edu.ph', 'Mark Buena', 'Instructor', null],
        ['vincentmalapo@cspc.edu.ph', 'Vincent E. Malapo', 'Instructor', null],
        ['engrgavina@cspc.edu.ph', 'Wenceslao Gavina', 'Instructor', null],
        ['romulo.ruel@cspc.edu.ph', 'Ruel Romulo', 'Instructor', null],
        ['rizzaloquias@cspc.edu.ph', 'Rizza T. Loquias', 'Instructor', null],
        ['oliverosa@cspc.edu.ph', 'Rosanova Oliveros', 'Instructor', null],
        ['victorisaac@cspc.edu.ph', 'Victor Isaac', 'Instructor', null],
        ['eugenebarbonio@cspc.edu.ph', 'Eugene Barbonio', 'Instructor', null],

        // Directs Research and Development Services per cspc.edu.ph, and
        // appears on the college's teaching list. Seeded as a CEA instructor
        // on that basis -- if the directorship is the whole role he belongs
        // with the office staff, and CEA students should not reach him.
        ['haroldterano@cspc.edu.ph', 'Harold Jan R. Terano', 'Instructor', null],

        // Heads the Center for Quality Assurance per cspc.edu.ph, and appears
        // on the college's teaching list. Seeded as a CEA instructor on that
        // basis -- if the unit post is the whole role he belongs with the
        // office staff instead, and CEA students' concerns should not reach
        // him.
        ['jbustamante@cspc.edu.ph', 'Juvy Bustamante', 'Instructor', null],
    ];

    /*
     * Confirmed CEA staff, no confirmed address yet. Listed so the gap is
     * visible rather than forgotten, and so nobody is tempted to fill it with
     * a guess. Move a name up into FACULTY once its mailbox is confirmed.
     *
     *   - Daisylen De Guzman Alano
     *   - Angelica L. Bongcayao
     *   - Christoper Oares
     *   - Peter Turiano
     *
     * They hold accounts already, under placeholder addresses seeded by
     * PlaceholderFacultySeeder, so routing reaches them. Replacing a
     * placeholder address with the real one on that existing row keeps their
     * concerns and history; adding a second row here would split them.
     *
     * Daisylen De Guzman Alano directs Extension and Community Services per
     * cspc.edu.ph and appears on the college's teaching list. If the
     * directorship is the whole role she belongs in UserSeeder as office
     * staff, and CEA students' concerns should not reach her.
     *
     * James Sias appeared on this list and on the CCS consultation sheet. He
     * has a confirmed address and is seeded in CcsFacultySeeder, where the
     * posted hours place him.
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
