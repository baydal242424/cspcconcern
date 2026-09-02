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
                // Teaching staff. Split out of Faculty/Staff, which had become
                // a bucket: five of its six holders were unit heads, and every
                // academic complaint routed to all of them alike.
                'name' => 'Instructor',
                'description' => 'Teaching staff of a college; first handler for academic, safety and general concerns',
            ],
            [
                // What is left after the split: office and unit staff -- ICT,
                // Health Services, Legal, Records. No category of their own;
                // they receive work by referral, like GAD.
                'name' => 'Faculty/Staff',
                'description' => 'Faculty or staff member who can handle concerns',
            ],
            [
                'name' => 'Dean',
                'description' => 'Head of a college; handles escalations and referrals for every programme under it',
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
                // Sits above the Administration. Exists so that a concern the
                // Admin cannot handle -- because it is about them -- escalates
                // upward instead of sideways to a college dean with no
                // authority over a system administrator.
                'name' => 'Vice President for Academic Affairs',
                'description' => 'Oversees the academic division; receives concerns the Administration cannot handle itself',
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
        ];

        foreach ($roles as $role) {
            Role::firstOrCreate(
                ['name' => $role['name']],
                ['description' => $role['description']]
            );
        }
    }
}