<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Splits teaching staff out of Faculty/Staff into an Instructor role.
 *
 * Faculty/Staff had become a bucket rather than a role. Of the six accounts
 * holding it, five were unit heads -- ICT, Health Services, Legal, Records,
 * Alumni Affairs -- and one was an actual instructor. Academic, Physical /
 * Safety and Others all route to that role, so an academic complaint from a
 * college with no instructor could land on the ICT unit head. That is a
 * routing defect wearing a vague label, not just a naming problem.
 *
 * Who moves is decided by department, which is the one signal that separates
 * the two groups reliably: teaching staff belong to a COLLEGE, office staff to
 * a unit or centre. Anyone whose department is one of the colleges becomes an
 * Instructor; everybody else stays Faculty/Staff and stops receiving academic
 * complaints they were never placed to answer.
 *
 * Faculty/Staff keeps existing and keeps its meaning -- office and unit staff,
 * reachable by referral. It simply no longer owns a category.
 */
return new class extends Migration
{
    public function up(): void
    {
        $instructor = DB::table('roles')->where('name', 'Instructor')->value('id');

        if (! $instructor) {
            $instructor = DB::table('roles')->insertGetId([
                'name' => 'Instructor',
                'description' => 'Teaching staff of a college; first handler for academic, safety and general concerns',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $faculty = DB::table('roles')->where('name', 'Faculty/Staff')->value('id');

        if ($faculty) {
            DB::table('users')
                ->where('role_id', $faculty)
                ->whereIn('department', array_keys(\App\Models\User::COURSES_BY_COLLEGE))
                ->update(['role_id' => $instructor]);
        }

        // A concern already referred to Faculty/Staff is left pointing there on
        // purpose. That referral was a decision somebody made about a specific
        // office, and rewriting it would change what the timeline says happened.
        $this->createView();
    }

    public function down(): void
    {
        $instructor = DB::table('roles')->where('name', 'Instructor')->value('id');
        $faculty = DB::table('roles')->where('name', 'Faculty/Staff')->value('id');

        if ($instructor && $faculty) {
            DB::table('users')->where('role_id', $instructor)->update(['role_id' => $faculty]);
            DB::table('roles')->where('id', $instructor)->delete();
        }

        DB::statement('DROP VIEW IF EXISTS instructors');
    }

    /**
     * A view of its own, beside faculty_staff. The point of both is to make an
     * empty college obvious: a college with no instructor cannot receive its
     * own students' academic concerns, and they escalate to the dean instead.
     */
    private function createView(): void
    {
        DB::statement('DROP VIEW IF EXISTS instructors');
        DB::statement("
            CREATE VIEW instructors AS
            SELECT u.id, u.name, u.email, u.department AS college,
                   CASE WHEN u.google_id IS NULL THEN 'pending first sign-in'
                        ELSE 'activated' END AS activated,
                   u.status, u.last_seen_at
            FROM users u
            JOIN roles r ON r.id = u.role_id
            WHERE r.name = 'Instructor'
        ");
    }
};
