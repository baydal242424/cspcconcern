<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A browsable list of the leadership accounts only -- deans, unit heads and
 * the Head of School -- separate from the general `employees` view, which
 * also contains every instructor.
 *
 * Like the other views this stores no rows: it is a saved query over users +
 * roles, so promoting or demoting someone at /admin/users moves them in and
 * out of here immediately, with nothing to keep in step by hand.
 *
 * `activated` is the column that matters operationally. A pre-registered
 * official seeded from CSPC's directory has no google_id until they sign in
 * with CSPC Mail for the first time, at which point the callback links their
 * real Google identity to the row and keeps the role. Until then the account
 * is dormant -- it holds a role but nobody can sign in as it. This view makes
 * that state visible, so it is obvious who has actually onboarded and who is
 * still just a placeholder waiting on an address that may not even be right.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->drop();

        DB::statement("
            CREATE VIEW deans_and_heads AS
            SELECT u.id, u.name, u.email, r.name AS role,
                   u.department AS unit,
                   CASE WHEN u.google_id IS NULL THEN 'pending first sign-in'
                        ELSE 'activated' END AS activated,
                   u.status, u.last_seen_at
            FROM users u
            JOIN roles r ON r.id = u.role_id
            WHERE r.name IN ('Department Head', 'Head of School')
        ");
    }

    public function down(): void
    {
        $this->drop();
    }

    /** SQLite (used by the test suite) has no CREATE OR REPLACE VIEW. */
    private function drop(): void
    {
        DB::statement('DROP VIEW IF EXISTS deans_and_heads');
    }
};
