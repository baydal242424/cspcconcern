<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Splits the old `deans_and_heads` view into two, because the two groups do
 * genuinely different jobs in this system:
 *
 *  - `deans`      : the Department Head of a COLLEGE. routeConcern() and
 *                   findHandler() escalate a college's concerns to these
 *                   accounts, matching on users.department, so who sits here
 *                   determines where a case goes when its normal handler is
 *                   conflicted out.
 *
 *  - `unit_heads` : offices and centres (ICT, Registrar, Clinic, GAD, Human
 *                   Rights, General Services...). No concern routes to them by
 *                   college -- they only ever receive explicit referrals.
 *
 * Both are views, so a promotion at /admin/users moves someone between them
 * immediately with nothing to maintain by hand.
 *
 * `deans` is derived from the role plus membership of the college list, NOT
 * from a flag on the row: that keeps it impossible for an account to look like
 * a dean here while routeConcern() disagrees about whether it can receive that
 * college's escalations.
 */
return new class extends Migration
{
    /**
     * The colleges, quoted for SQL. Mirrors User::COURSES_BY_COLLEGE, which is
     * what routeConcern() matches against.
     */
    private function collegeList(): string
    {
        $colleges = array_keys(\App\Models\User::COURSES_BY_COLLEGE);

        return collect($colleges)
            ->map(fn ($c) => "'".str_replace("'", "''", $c)."'")
            ->implode(', ');
    }

    public function up(): void
    {
        $this->drop();
        $colleges = $this->collegeList();

        DB::statement("
            CREATE VIEW deans AS
            SELECT u.id, u.name, u.email, u.department AS college,
                   CASE WHEN u.google_id IS NULL THEN 'pending first sign-in'
                        ELSE 'activated' END AS activated,
                   u.status, u.last_seen_at
            FROM users u
            JOIN roles r ON r.id = u.role_id
            WHERE r.name = 'Department Head'
              AND u.department IN ({$colleges})
        ");

        // Everything else with authority that is not a college dean: the Head
        // of School, and the Department Heads of non-college units such as the
        // Graduate School.
        DB::statement("
            CREATE VIEW unit_heads AS
            SELECT u.id, u.name, u.email, r.name AS role, u.department AS unit,
                   CASE WHEN u.google_id IS NULL THEN 'pending first sign-in'
                        ELSE 'activated' END AS activated,
                   u.status, u.last_seen_at
            FROM users u
            JOIN roles r ON r.id = u.role_id
            WHERE r.name = 'Head of School'
               OR (r.name = 'Department Head' AND u.department NOT IN ({$colleges}))
               OR (r.name = 'Faculty/Staff'
                   AND (u.department LIKE '%Unit%'
                     OR u.department LIKE '%Center%'
                     OR u.department LIKE '%Office%'
                     OR u.department LIKE '%Records%'
                     OR u.department LIKE '%Services%'))
        ");
    }

    public function down(): void
    {
        $this->drop();
    }

    /** SQLite (the test suite) has no CREATE OR REPLACE VIEW. */
    private function drop(): void
    {
        DB::statement('DROP VIEW IF EXISTS deans');
        DB::statement('DROP VIEW IF EXISTS unit_heads');
        DB::statement('DROP VIEW IF EXISTS deans_and_heads');
    }
};
