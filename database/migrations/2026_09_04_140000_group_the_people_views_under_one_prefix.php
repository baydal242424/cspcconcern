<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Renames every people-view to a `people_` prefix, so phpMyAdmin collects them
 * into one folder instead of scattering them through the table list.
 *
 * phpMyAdmin's navigation tree makes a folder out of whatever precedes the
 * first underscore in a name. With the old names that produced the worst of
 * both worlds: `deans`, `advisers`, `instructors` and `students` sat loose
 * among the real tables, while `guidance_office`, `program_chairs` and
 * `unit_heads` were filed under invented folders called "guidance", "program"
 * and "unit" -- so one set of related lists appeared in five different places,
 * none of them next to each other. Finding who holds a role meant knowing
 * which of those places to look in first.
 *
 * One prefix collapses that to one folder, in the order they are listed:
 *
 *     people_administration        Admin and the VPAA
 *     people_advisers              who advises which section
 *     people_deans                 one per college
 *     people_employees             everyone who is not a student
 *     people_faculty_staff         the generic role, offices included
 *     people_gender_and_development
 *     people_guidance_office
 *     people_instructors
 *     people_program_chairs        one per degree programme
 *     people_students
 *     people_unit_heads            offices that are not colleges
 *
 * Nothing in the application reads any of them; they exist to be read by a
 * human. Renaming is therefore safe. This recreates rather than renames
 * because SQLite, which the test suite runs on, has no ALTER VIEW.
 *
 * people_administration is new. A coverage check found Admin and Vice
 * President for Academic Affairs appearing in NO list except the 429-row
 * employees dump -- the same gap that once hid General Services and the
 * Registrar, where a role is added and no view claims it. Those two belong
 * together for a second reason: an Administrative concern routes to Admin,
 * unless it is ABOUT an admin, in which case it goes to the VPAA. This is the
 * list that answers who can read them.
 */
return new class extends Migration
{
    /** The names being retired. */
    private const OLD = [
        'students', 'employees', 'deans', 'program_chairs', 'instructors',
        'advisers', 'faculty_staff', 'guidance_office',
        'gender_and_development', 'unit_heads',
    ];

    /** The colleges, quoted for SQL. Mirrors User::COURSES_BY_COLLEGE. */
    private function colleges(): string
    {
        return collect(array_keys(\App\Models\User::COURSES_BY_COLLEGE))
            ->map(fn ($c) => "'".str_replace("'", "''", $c)."'")
            ->implode(', ');
    }

    /** 'pending first sign-in' until Google has seen them; sign-in is Google-only. */
    private function activated(): string
    {
        return "CASE WHEN u.google_id IS NULL THEN 'pending first sign-in'
                     ELSE 'activated' END AS activated";
    }

    /**
     * The new name => the body of its view. Definitions are carried over from
     * the migrations that built them, with the role names as they stand today
     * (Dean, not Department Head; no Registrar).
     *
     * @return array<string, string>
     */
    private function definitions(): array
    {
        $colleges = $this->colleges();
        $activated = $this->activated();

        $byRole = fn (string $where, string $deptAs = 'college') => "
            SELECT u.id, u.name, u.email, r.name AS role, u.department AS {$deptAs},
                   {$activated}, u.status, u.last_seen_at
            FROM users u
            JOIN roles r ON r.id = u.role_id
            WHERE {$where}
        ";

        return [
            'people_students' => "
                SELECT u.id, u.name, u.email, u.student_id, u.department AS college,
                       u.course, u.section, u.status, u.last_seen_at, u.created_at
                FROM users u
                JOIN roles r ON r.id = u.role_id
                WHERE r.name = 'Student'
            ",

            'people_employees' => "
                SELECT u.id, u.name, u.email, r.name AS role, u.department AS office,
                       u.status, u.last_seen_at, u.created_at
                FROM users u
                JOIN roles r ON r.id = u.role_id
                WHERE r.name <> 'Student'
            ",

            // Derived from the role PLUS membership of the college list, not
            // from a flag on the row: an account cannot look like a dean here
            // while routeConcern() disagrees about whether it can receive that
            // college's escalations.
            'people_deans' => "
                SELECT u.id, u.name, u.email, u.department AS college,
                       {$activated}, u.status, u.last_seen_at
                FROM users u
                JOIN roles r ON r.id = u.role_id
                WHERE r.name = 'Dean' AND u.department IN ({$colleges})
            ",

            'people_program_chairs' => $byRole("r.name = 'Program Chair'"),

            'people_instructors' => "
                SELECT u.id, u.name, u.email, u.department AS college,
                       {$activated}, u.status, u.last_seen_at
                FROM users u
                JOIN roles r ON r.id = u.role_id
                WHERE r.name = 'Instructor'
            ",

            // Reads from sections, not roles, because advising is not a role:
            // the people doing it are Instructors and Program Chairs, and one
            // person advises several sections. A programme with no row here has
            // no adviser, and its students fall back to college-level routing.
            'people_advisers' => "
                SELECT s.id, s.course, s.section, s.school_year, s.semester,
                       u.name AS adviser, u.email, r.name AS role,
                       u.department AS college, {$activated}
                FROM sections s
                JOIN users u ON u.id = s.adviser_id
                JOIN roles r ON r.id = u.role_id
            ",

            'people_faculty_staff' => $byRole("r.name = 'Faculty/Staff'"),

            // The counselor's categories are exclusive -- not even an Admin can
            // read them -- so this is the shortest list of accounts that can
            // see the most sensitive cases in the system.
            'people_guidance_office' => $byRole("r.name = 'Guidance Counselor'", 'office'),

            // No category of its own: GAD only ever sees what a counselor
            // deliberately referred to it.
            'people_gender_and_development' => $byRole("r.name = 'Gender and Development'", 'office'),

            'people_administration' => $byRole(
                "r.name IN ('Admin', 'Vice President for Academic Affairs')",
                'office'
            ),

            // Matches on ROLE first, with the department pattern only as a
            // fallback for offices still sitting in Faculty/Staff. A future
            // office role needs adding here -- but it then fails visibly, as an
            // office missing from the list, rather than depending on someone
            // having named a department with the word "Unit" in it.
            'people_unit_heads' => "
                SELECT u.id, u.name, u.email, r.name AS role, u.department AS unit,
                       {$activated}, u.status, u.last_seen_at
                FROM users u
                JOIN roles r ON r.id = u.role_id
                WHERE r.name IN ('Head of School', 'General Services',
                                 'Gender and Development')
                   OR (r.name = 'Dean' AND u.department NOT IN ({$colleges}))
                   OR (r.name = 'Faculty/Staff'
                       AND (u.department LIKE '%Unit%'
                         OR u.department LIKE '%Center%'
                         OR u.department LIKE '%Office%'
                         OR u.department LIKE '%Records%'
                         OR u.department LIKE '%Services%'))
            ",
        ];
    }

    public function up(): void
    {
        foreach (self::OLD as $name) {
            DB::statement("DROP VIEW IF EXISTS {$name}");
        }

        foreach ($this->definitions() as $name => $body) {
            DB::statement("DROP VIEW IF EXISTS {$name}");
            DB::statement("CREATE VIEW {$name} AS {$body}");
        }
    }

    public function down(): void
    {
        foreach ($this->definitions() as $name => $body) {
            DB::statement("DROP VIEW IF EXISTS {$name}");

            // Restore the unprefixed name, except people_administration, which
            // never existed under the old scheme.
            $old = substr($name, strlen('people_'));

            if (in_array($old, self::OLD, true)) {
                DB::statement("DROP VIEW IF EXISTS {$old}");
                DB::statement("CREATE VIEW {$old} AS {$body}");
            }
        }
    }
};
