<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The two teaching tiers, listed on their own beside the existing office
 * views (deans, unit_heads, guidance_office, gender_and_development).
 *
 * They were the gap in that set. Every office that RECEIVES a concern had a
 * view of its own, but the people who receive the most -- instructors -- were
 * only visible inside the general `employees` list, mixed in with every unit
 * on campus. Academic, Physical / Safety and Others all route to
 * Faculty/Staff, which is the largest share of what students file, so the
 * roster that absorbs it is worth being able to read at a glance.
 *
 * program_chairs is the newer tier: a chair owns one degree programme and
 * takes academic concerns an instructor cannot settle alone, before they
 * reach the dean. A short list is the point -- if it is empty, that
 * escalation step has nobody in it and concerns will skip straight to the
 * dean without anyone noticing.
 *
 * Both carry `college` rather than `office`, unlike the unit-based views.
 * Routing prefers a handler from the reporter's own college, so an instructor
 * or chair with a blank department is silently skipped by findHandler(). That
 * column is the quickest way to spot it.
 *
 * Views, not tables: promoting or demoting someone at /admin/users moves them
 * in and out of these lists immediately, with nothing to maintain by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->drop();

        DB::statement("
            CREATE VIEW faculty_staff AS
            SELECT u.id, u.name, u.email, r.name AS role, u.department AS college,
                   CASE WHEN u.google_id IS NULL THEN 'pending first sign-in'
                        ELSE 'activated' END AS activated,
                   u.status, u.last_seen_at
            FROM users u
            JOIN roles r ON r.id = u.role_id
            WHERE r.name = 'Faculty/Staff'
        ");

        DB::statement("
            CREATE VIEW program_chairs AS
            SELECT u.id, u.name, u.email, r.name AS role, u.department AS college,
                   CASE WHEN u.google_id IS NULL THEN 'pending first sign-in'
                        ELSE 'activated' END AS activated,
                   u.status, u.last_seen_at
            FROM users u
            JOIN roles r ON r.id = u.role_id
            WHERE r.name = 'Program Chair'
        ");
    }

    public function down(): void
    {
        $this->drop();
    }

    /** SQLite (the test suite) has no CREATE OR REPLACE VIEW. */
    private function drop(): void
    {
        DB::statement('DROP VIEW IF EXISTS faculty_staff');
        DB::statement('DROP VIEW IF EXISTS program_chairs');
    }
};
