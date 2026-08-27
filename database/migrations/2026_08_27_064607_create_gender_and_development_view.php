<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Gender and Development accounts, listed on their own -- the fourth of
 * the office views, alongside deans, unit_heads and guidance_office.
 *
 * Worth separating for the same reason guidance_office is: this role is the
 * destination for referred sexual-harassment cases under CMO No. 3 s. 2022,
 * which the Student Handbook singles out as the one offence CSPC claims
 * jurisdiction over even off campus. Anyone appearing here can read every
 * concern referred to GAD.
 *
 * Unlike the other staff roles, Gender and Development has NO category of its
 * own in Concern::scopeVisibleTo(): a harassment concern still routes to the
 * Guidance Counselor first, who assesses it and refers it on. So this list
 * only ever sees what somebody deliberately sent it -- which is exactly why
 * it is short enough to be worth checking at a glance.
 *
 * A view, not a table: promoting or demoting someone at /admin/users moves
 * them in and out of here immediately, with nothing to maintain by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->drop();

        DB::statement("
            CREATE VIEW gender_and_development AS
            SELECT u.id, u.name, u.email, r.name AS role, u.department AS office,
                   CASE WHEN u.google_id IS NULL THEN 'pending first sign-in'
                        ELSE 'activated' END AS activated,
                   u.status, u.last_seen_at
            FROM users u
            JOIN roles r ON r.id = u.role_id
            WHERE r.name = 'Gender and Development'
        ");
    }

    public function down(): void
    {
        $this->drop();
    }

    /** SQLite (the test suite) has no CREATE OR REPLACE VIEW. */
    private function drop(): void
    {
        DB::statement('DROP VIEW IF EXISTS gender_and_development');
    }
};
