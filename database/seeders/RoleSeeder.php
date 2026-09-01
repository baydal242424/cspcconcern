<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Role;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $roles = [
            [
                'name' => 'Student',
                'description' => 'College student who can submit concerns',
            ],
            [
                'name' => 'Faculty/Staff',
                'description' => 'Faculty or staff member who can handle concerns',
            ],
            [
                'name' => 'Department Head',
                'description' => 'Department head/dean who manages concerns',
            ],
            [
                // Sits between an instructor and the dean. A program chair owns
                // one degree programme (BSCS, BSIT, BSIS, BLIS in Computer
                // Studies), so an academic complaint that an instructor cannot
                // settle alone has somewhere to go that is not yet the whole
                // college. Without this role that escalation jumped straight to
                // the dean, which is both slower and heavier than most academic
                // concerns warrant.
                'name' => 'Program Chair',
                'description' => 'Chair of a degree programme; handles academic concerns escalated from instructors before they reach the dean',
            ],
            [
                'name' => 'Guidance Counselor',
                'description' => 'Guidance office staff for mental health and referrals',
            ],
            [
                'name' => 'Admin',
                'description' => 'System administrator with full access',
            ],
            [
                'name' => 'Head of School',
                'description' => 'Highest authority; can read concerns and perform logged identity reveals (break-glass) for accountability',
            ],
            [
                // Referral target only -- no category routes here automatically.
                // A harassment concern still reaches the Guidance Counselor
                // first, who assesses it and refers it on if it falls under
                // CMO No. 3 s. 2022 (sexual harassment), which the Student
                // Handbook singles out as the one offence CSPC claims
                // jurisdiction over even off campus.
                'name' => 'Gender and Development',
                'description' => 'Center for Gender and Development; receives referred gender-related and sexual harassment cases (CMO No. 3 s. 2022)',
            ],
            [
                // The General Services Unit is CSPC's maintenance front door:
                // per cspc.edu.ph it performs "routine maintenance on all the
                // buildings, grounds, facilities and other equipment", with
                // sub-units for preventive maintenance, the electrical system,
                // and air-conditioning and water systems.
                //
                // Facilities / Equipment concerns route here instead of to
                // Admin, which had no maintenance function at all. Computer
                // faults are ICTRaM's (the ICT Unit's repair arm) rather than
                // GSU's, but GSU is the office students already report a
                // broken anything to, so it takes them and passes them on --
                // one real destination beats asking a student to guess which
                // of two offices owns their broken thing.
                'name' => 'General Services',
                'description' => 'General Services Unit; handles facilities, equipment and maintenance concerns',
            ],
            [
                // The Student Registration and Records Office. Per cspc.edu.ph
                // it is "the main unit in charge of student enrollment;
                // maintenance and preservation of student's scholastic
                // records; academic advising, and student graduation", and it
                // handles ID validation, grading sheets, credentials and
                // certifications.
                //
                // Administrative concerns route here instead of to Admin.
                // "Admin" in this system means the system's administrators,
                // not the admin OFFICE -- they manage accounts, not student
                // records, so a lost-ID or enrolment problem was reaching
                // people with no way to act on it.
                //
                // Caveat: the Administrative category also covers FEES, which
                // belong to the Cash Unit (cashier@cspc.edu.ph), not the
                // Registrar. Those arrive here and get referred on. Splitting
                // the category was the alternative and was judged not worth
                // the extra step it puts on every student.
                'name' => 'Registrar',
                'description' => 'Student Registration and Records Office; handles enrolment, records, credentials and other administrative concerns',
            ],
        ];

        foreach ($roles as $role) {
            Role::firstOrCreate(
                ['name' => $role['name']],
                ['description' => $role['description']]
            );
        }
    }
}