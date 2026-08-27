<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Guidance Office accounts, listed on their own.
 *
 * These are worth separating from the general `employees` view because of
 * what routes to them: ConcernController::routeConcern() sends every
 * Mental Health / Personal and Bullying / Harassment concern to the
 * Guidance Counselor role, and Concern::scopeVisibleTo() makes those two
 * categories the counselor's exclusive domain -- an Admin cannot read them.
 *
 * So this is the shortest list of accounts that can see the most sensitive
 * cases in the system, which makes it the one worth being able to check at a
 * glance. If an account appears here that should not, it has access to every
 * mental-health and bullying report filed.
 *
 * A view, not a table: it re-reads users + roles on every query, so someone
 * promoted into or out of the counselor role appears and disappears here
 * immediately.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->drop();

        DB::statement("
            CREATE VIEW guidance_office AS
            SELECT u.id, u.name, u.email, r.name AS role, u.department AS office,
                   CASE WHEN u.google_id IS NULL THEN 'pending first sign-in'
                        ELSE 'activated' END AS activated,
                   u.status, u.last_seen_at
            FROM users u
            JOIN roles r ON r.id = u.role_id
            WHERE r.name = 'Guidance Counselor'
        ");
    }

    public function down(): void
    {
        $this->drop();
    }

    /** SQLite (used by the test suite) has no CREATE OR REPLACE VIEW. */
    private function drop(): void
    {
        DB::statement('DROP VIEW IF EXISTS guidance_office');
    }
};
