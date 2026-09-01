<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Department Head" becomes "Dean".
 *
 * The old name was wrong in CSPC's own vocabulary and actively confusing next
 * to the Program Chair tier added recently: a chair heads a DEPARTMENT (the
 * Information Systems Department, say), while the person this role means
 * heads a COLLEGE. Calling the college-level role "Department Head" put the
 * two labels in the wrong order of seniority for anyone reading the referral
 * dropdown.
 *
 * The role name is not just a label -- it is stored as a string in three
 * places, and all three have to move together or referred concerns lose their
 * handler:
 *
 *  - roles.name, which every visibility rule joins against;
 *  - concerns.referred_to, which stores the destination ROLE by name, so an
 *    open concern referred to "Department Head" would match no role at all
 *    and become invisible to the office that owes the student an answer;
 *  - the deans and unit_heads views, which filter on the literal string.
 *
 * audit_logs.description is deliberately NOT rewritten. Those rows say what
 * somebody did at the time, in the words that were true then, and a timeline
 * that edits itself to match a later rename is not an audit trail. Old
 * entries keep reading "Referred to Department Head"; that is correct.
 */
return new class extends Migration
{
    private const OLD = 'Department Head';

    private const NEW = 'Dean';

    public function up(): void
    {
        $this->rename(self::OLD, self::NEW);
    }

    public function down(): void
    {
        $this->rename(self::NEW, self::OLD);
    }

    private function rename(string $from, string $to): void
    {
        DB::table('roles')->where('name', $from)->update(['name' => $to]);

        // Open concerns point at their destination by role NAME, not id.
        DB::table('concerns')->where('referred_to', $from)->update(['referred_to' => $to]);

        $this->rebuildViews($to);
    }

    /**
     * Both views hard-code the role name in their WHERE clause, so they are
     * dropped and rebuilt rather than left pointing at a name that no longer
     * exists. Definitions otherwise match the migrations that created them.
     */
    private function rebuildViews(string $roleName): void
    {
        $colleges = $this->collegeList();
        $role = str_replace("'", "''", $roleName);

        DB::statement('DROP VIEW IF EXISTS deans');
        DB::statement("
            CREATE VIEW deans AS
            SELECT u.id, u.name, u.email, u.department AS college,
                   CASE WHEN u.google_id IS NULL THEN 'pending first sign-in'
                        ELSE 'activated' END AS activated,
                   u.status, u.last_seen_at
            FROM users u
            JOIN roles r ON r.id = u.role_id
            WHERE r.name = '{$role}'
              AND u.department IN ({$colleges})
        ");

        DB::statement('DROP VIEW IF EXISTS unit_heads');
        DB::statement("
            CREATE VIEW unit_heads AS
            SELECT u.id, u.name, u.email, r.name AS role, u.department AS unit,
                   CASE WHEN u.google_id IS NULL THEN 'pending first sign-in'
                        ELSE 'activated' END AS activated,
                   u.status, u.last_seen_at
            FROM users u
            JOIN roles r ON r.id = u.role_id
            WHERE
                -- Offices that hold a role of their own.
                r.name IN ('Head of School', 'General Services', 'Registrar',
                           'Gender and Development')
                -- The head of something that is not a college (the Graduate
                -- School), who carries the college-level role regardless.
                OR (r.name = '{$role}' AND u.department NOT IN ({$colleges}))
                -- Offices still carrying the generic Faculty/Staff role.
                OR (r.name = 'Faculty/Staff'
                    AND (u.department LIKE '%Unit%'
                      OR u.department LIKE '%Center%'
                      OR u.department LIKE '%Office%'
                      OR u.department LIKE '%Records%'
                      OR u.department LIKE '%Services%'))
        ");
    }

    private function collegeList(): string
    {
        return collect(array_keys(\App\Models\User::COURSES_BY_COLLEGE))
            ->map(fn ($c) => "'".str_replace("'", "''", $c)."'")
            ->implode(', ');
    }
};
