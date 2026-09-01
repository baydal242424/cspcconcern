<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Role;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $students_role = Role::where('name', 'Student')->first();
        $staff_role = Role::where('name', 'Faculty/Staff')->first();
        $counselor_role = Role::where('name', 'Guidance Counselor')->first();
        $admin_role = Role::where('name', 'Admin')->first();
        $head_role = Role::where('name', 'Head of School')->first();
        $depthead_role = Role::where('name', 'Dean')->first();
        $gad_role = Role::where('name', 'Gender and Development')->first();
        $gsu_role = Role::where('name', 'General Services')->first();
        $registrar_role = Role::where('name', 'Registrar')->first();

        // ==================================================================
        // TEST FIXTURES -- never seeded outside a test run.
        // ==================================================================
        // "John Student", "Prof. Juan Dela Cruz", "Admin User" and the six
        // per-college instructors are invented people. They existed so the
        // system had something to demo against, but they are not CSPC staff
        // and must not sit in a live database looking like they are.
        //
        // They cannot simply be deleted: 20 test files seed this class and
        // then look these accounts up by address, so removing them outright
        // would break the whole suite. Gating on runningUnitTests() keeps
        // them available where they are genuinely needed and out of every
        // other environment -- including local, so what you demo is the real
        // roster.
        //
        // The real CSPC officials below this block are seeded unconditionally.
        if (app()->runningUnitTests()) {
        // Demo students
        User::firstOrCreate(
            ['email' => 'student@my.cspc.edu.ph'],
            [
                'name' => 'John Student',
                'password' => Hash::make('password'),
                'role_id' => $students_role->id,
                // A student's department is their college: concerns they file
                // inherit it, and routeConcern() uses it to pick a handler.
                'department' => 'College of Computer Studies',
                'student_id' => '2023-00123',
                'course' => 'BS Information Technology',
            ]
        );

        User::firstOrCreate(
            ['email' => 'student2@my.cspc.edu.ph'],
            [
                'name' => 'Maria Santos',
                'password' => Hash::make('password'),
                'role_id' => $students_role->id,
                'department' => 'College of Health Sciences',
                'student_id' => '2023-00456',
                'course' => 'BS Nursing',
            ]
        );

        // Demo staff. Seeded FIRST and deliberately not attached to a college:
        // routeConcern() falls back to the first Faculty/Staff account when the
        // concern's college has no instructor of its own, so this account is
        // the catch-all handler.
        User::firstOrCreate(
            ['email' => 'staff@cspc.edu.ph'],
            [
                'name' => 'Prof. Juan Dela Cruz',
                'password' => Hash::make('password'),
                'role_id' => $staff_role->id,
                'department' => 'Academic Affairs',
            ]
        );

        // One instructor per college, so department-aware routing has somebody
        // to hand a college's Academic / Physical-Safety / Others concerns to
        // instead of dropping them all on the catch-all account above.
        $collegeInstructors = [
            'College of Computer Studies' => ['ccs.instructor@cspc.edu.ph', 'Prof. Andres Villamor'],
            'College of Engineering and Architecture' => ['cea.instructor@cspc.edu.ph', 'Engr. Lorna Sabado'],
            'College of Tourism, Hospitality and Business Management' => ['cthbm.instructor@cspc.edu.ph', 'Prof. Rico Alcantara'],
            'College of Health Sciences' => ['chs.instructor@cspc.edu.ph', 'Prof. Delia Marquez'],
            'College of Technological and Development Education' => ['ctde.instructor@cspc.edu.ph', 'Prof. Noel Bagasbas'],
            'College of Arts and Sciences' => ['cas.instructor@cspc.edu.ph', 'Prof. Imelda Ferrer'],
        ];

        foreach ($collegeInstructors as $college => [$email, $name]) {
            User::firstOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'password' => Hash::make('password'),
                    'role_id' => $staff_role->id,
                    'department' => $college,
                ]
            );
        }

        // Demo counselor
        User::firstOrCreate(
            ['email' => 'counselor@cspc.edu.ph'],
            [
                'name' => 'Dr. Maria Reyes',
                'password' => Hash::make('password'),
                'role_id' => $counselor_role->id,
                'department' => 'Guidance Office',
            ]
        );

        // Demo admin
        User::firstOrCreate(
            ['email' => 'admin@cspc.edu.ph'],
            [
                'name' => 'Admin User',
                'password' => Hash::make('password'),
                'role_id' => $admin_role->id,
                'department' => 'Administration',
            ]
        );

        // Demo Head of School (break-glass authority)
        if ($head_role) {
            User::firstOrCreate(
                ['email' => 'head@cspc.edu.ph'],
                [
                    'name' => 'Dr. Elena Villanueva',
                    'password' => Hash::make('password'),
                    'role_id' => $head_role->id,
                    'department' => 'Office of the President',
                ]
            );
        }

        } // ---- end test fixtures ----

        // ==================================================================
        // REAL CSPC OFFICIALS -- seeded in every environment.
        // ==================================================================

        // A Dean for every college a student can register under, so
        // each department has someone to receive escalations and referrals.
        // Deans are employees, so they use the staff domain @cspc.edu.ph --
        // @my.cspc.edu.ph is the student domain. Computer Studies is seeded
        // first: routeConcern() takes the first matching head, and the referral
        // tests expect that account.
        if ($depthead_role) {
            // The sitting deans, with their OFFICE addresses as published in
            // CSPC's Directory of College Officials
            // (https://cspc.edu.ph/college-officials/).
            //
            // Note these are per-OFFICE, not per-person: the College of
            // Computer Studies dean is reached at ccs@cspc.edu.ph, not at a
            // personal address. That is an advantage here -- when a dean is
            // replaced the office address stays put, so the role survives the
            // handover without anyone editing this file. It is also why the
            // earlier firstname.lastname guesses were doomed: personal
            // addresses were never the published contact in the first place.
            //
            // !! Only works if these office addresses are real Google accounts
            // somebody signs in with, rather than mail aliases. If a dean signs
            // in with a personal address instead, no row matches and they land
            // as Faculty/Staff -- an Admin then promotes them at /admin/users.
            //
            // The array is keyed by the college names in
            // User::COURSES_BY_COLLEGE, NOT by the directory's wording: a
            // concern inherits its reporter's college and routeConcern()
            // matches that string exactly, so "Development" vs the directory's
            // "Developmental" Education would silently break escalation.
            $deanEmails = [
                'College of Computer Studies' => ['ccs@cspc.edu.ph', 'Ms. Rosel O. Onesa (OIC)'],
                'College of Engineering and Architecture' => ['coe@cspc.edu.ph', 'Engr. Martin D. Valeras, Jr.'],
                'College of Tourism, Hospitality and Business Management' => ['cthbm@cspc.edu.ph', 'Dr. Maria Joy Iglesia'],
                'College of Health Sciences' => ['chs@cspc.edu.ph', 'Dr. Kenny Niño H. Tagum'],
                'College of Technological and Development Education' => ['ctde@cspc.edu.ph', 'Dr. Patrick Gerard A. Paulino'],
                'College of Arts and Sciences' => ['cas@cspc.edu.ph', 'Dr. Marlon SD. Pontillas'],
            ];

            // Driven off the college list so a college added there always gets
            // a head, even before it has a published dean.
            foreach (array_keys(User::COURSES_BY_COLLEGE) as $college) {
                [$email, $name] = $deanEmails[$college]
                    ?? ['depthead.'.\Illuminate\Support\Str::slug($college).'@cspc.edu.ph', 'Head, '.$college];

                User::firstOrCreate(
                    ['email' => $email],
                    [
                        'name' => $name,
                        'password' => Hash::make('password'),
                        'role_id' => $depthead_role->id,
                        'department' => $college,
                    ]
                );
            }
        }

        // ------------------------------------------------------------------
        // PRE-REGISTERED OFFICIALS
        // ------------------------------------------------------------------
        // Non-college officials from CSPC's official directory, seeded so they
        // arrive with the right role instead of the Faculty/Staff default that
        // AuthController::DOMAIN_ROLES gives every new @cspc.edu.ph address.
        //
        // These rows have no google_id, so they are dormant: nobody can sign
        // in AS them. On that person's first CSPC Mail sign-in the callback
        // matches on email and links their real Google identity to this row,
        // keeping the role set here.
        //
        // ONLY CONFIRMED ADDRESSES BELONG IN THIS LIST.
        //
        // Ten more officials were seeded here from CSPC's printed directory,
        // with addresses guessed as firstname.lastname@cspc.edu.ph. All ten
        // were removed once a real address showed the convention has no dot
        // and no consistent rule (chrbaydal@ = 3 letters, glpocaan@ = 2,
        // mkiarasapinoso@ = initial + second given name).
        //
        // A wrong address fails SILENTLY: nothing matches, the real person is
        // auto-created as plain Faculty/Staff, and the seeded row sits at
        // "pending first sign-in" forever with nothing reporting it. Anyone
        // reading the list would believe those people were pre-authorised.
        //
        // Only add someone here with an address confirmed from their actual
        // account. For everyone else the flow is: they sign in, they land as
        // Faculty/Staff, an Admin promotes them at /admin/users -- which acts
        // on the address Google really returned instead of an invented one.
        $officials = [
            // ---- Guidance ----
            // Two accounts, two DIFFERENT roles, deliberately.
            //
            // The Guidance Office administers this system (SASO declined to),
            // so the shared office address carries Admin: /admin/users, the
            // dashboard, and the Administrative / Facilities caseload.
            //
            // But Admin and Guidance Counselor see disjoint categories --
            // scopeVisibleTo() makes Mental Health / Personal and Bullying /
            // Harassment the COUNSELOR's exclusive domain, invisible to Admin.
            // routeConcern() sends both categories to the Guidance Counselor
            // role, so if nobody held it findHandler() would return null and
            // those concerns would be created unassigned and visible to no
            // one at all. Kiara's personal account therefore stays Counselor:
            // the casework keeps a home while the office gains admin powers.
            //
            // Her personal address is the one confirmed from her own sign-in
            // screen; the office address is the one CSPC publishes.
            ['guidancecenter@cspc.edu.ph', 'Guidance Counseling Office', $admin_role, 'Guidance Office'],
            ['mkiarasapinoso@cspc.edu.ph', 'Ma. Kiara Sapinoso-Deligencia', $counselor_role, 'Guidance Office'],

            // ---- Graduate School ----
            // A college-equivalent unit, so its dean carries the same role as
            // the college deans. It is not in COURSES_BY_COLLEGE (no
            // undergraduate programs), so no concern routes here by college --
            // this account exists to receive referrals and escalations.
            ['graduateschool@cspc.edu.ph', 'Dr. Leni M. Malabanan', $depthead_role, 'Graduate School'],

            // ---- Unit heads ----
            // Faculty/Staff, not Dean: routeConcern() picks a
            // Dean by matching the concern's COLLEGE, and these are
            // units rather than colleges, so the higher role would grant
            // escalation visibility over cases they never receive. Promote at
            // /admin/users if a unit should genuinely take referrals.
            ['mict@cspc.edu.ph', 'Mr. Rey T. Cortez', $staff_role, 'Information and Communications Technology Unit'],
            ['oprkm@cspc.edu.ph', 'Dr. Noel R. Volante', $staff_role, 'Information and Alumni Affairs Unit'],
            ['registrar@cspc.edu.ph', 'Dr. Arlene O. Malaya', $registrar_role, 'Student Registration and Records'],
            ['collegeclinic@cspc.edu.ph', 'Mr. Mark Adrian J. Manzano', $staff_role, 'Health Services Unit'],
            ['gsu@cspc.edu.ph', 'Mr. Dan Randolf P. Francia', $gsu_role, 'General Services Unit'],
            // ---- Gender and Development ----
            // Holds its own role, not Faculty/Staff: GAD is a referral
            // destination, and referred_to stores a ROLE NAME, so it could not
            // be a referral target without one. The role has no category of
            // its own -- it sees only what is referred or assigned to it.
            ['gad@cspc.edu.ph', 'Ms. Gigi V. Severo', $gad_role, 'Center for Gender and Development'],

            // The Legal Affairs Office sits in the same role so a case can be
            // referred to legal counsel through the same gate. The handbook
            // requires the Disciplinary Board be chaired by a member of the
            // Integrated Bar (or someone with a legal background), and CMO
            // No. 3 s. 2022 sexual-harassment cases are legal matters, so
            // this is the office those referrals need to reach.
            //
            // She is also the Data Privacy Officer, which matters for a system
            // holding students' mental-health and harassment reports.
            //
            // Atty. Abaca appears twice in CSPC's directory, under two
            // addresses. Her Human Rights Education row (chre@, below) stays
            // Faculty/Staff; whichever address she signs in with, a row
            // matches, so she is never auto-provisioned as an unknown account.
            ['lao@cspc.edu.ph', 'Atty. Maria Francia S. Abaca', $gad_role, 'Legal Affairs Office'],
            ['chre@cspc.edu.ph', 'Atty. Maria Francia S. Abaca', $staff_role, 'Center for Human Rights Education'],

            // NOT seeded: sas@cspc.edu.ph (Dr. Jay L. Luzon, Director, Student
            // Affairs Services). The handbook makes SASO the official intake
            // point for disciplinary complaints, but SASO declined to adopt
            // this system, so routing cases to an address nobody there will
            // open would strand them. Add this row if that changes.
            //
            // NOT seeded: mail@cspc.edu.ph (Dr. Amado A. Oliva Jr., SUC
            // President III) as Head of School. That role can read EVERY
            // concern in the system, and the published address is a general
            // office inbox -- granting read-everything to whoever happens to
            // have access to it is a decision for CSPC, not a default. So
            // there is deliberately NO Head of School outside tests.
            // Consequence: routeConcern()'s conflict-of-interest escalation
            // chain (Dean -> Head of School) stops at Department
            // Head, which is safe -- a case is never left unassigned, it just
            // does not escalate past the dean. Seed a real personal address
            // here, or promote the right person at /admin/users, when CSPC
            // decides who holds it.
        ];

        foreach ($officials as [$email, $name, $role, $unit]) {
            if (! $role) {
                continue;
            }

            User::firstOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'password' => Hash::make(str()->random(40)),
                    'role_id' => $role->id,
                    'department' => $unit,
                ]
            );
        }

        // Mark the seeded addresses confirmed. Nothing here is a real mailbox,
        // and CSPC Mail sign-in is now the only way in, so these accounts are
        // fixtures for tests and routing rather than usable logins -- a real
        // person's first Google sign-in is what links a live identity to one.
        User::whereNull('email_verified_at')->update(['email_verified_at' => now()]);
    }
}