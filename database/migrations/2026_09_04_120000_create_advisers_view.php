<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Who advises which section, as a browsable list beside deans, instructors
 * and program_chairs.
 *
 * The other role views answer "who holds this role". This one cannot, because
 * advising is not a role: the people doing it are Instructors and Program
 * Chairs, and one person advises several sections. It reads from the sections
 * table instead, one row per section per term.
 *
 * That makes two things visible that nothing else shows. A programme with no
 * row has no adviser, so its students fall back to college-level routing. And
 * because the rows are versioned, a college that has not published this term's
 * assignments is obvious -- the newest school year against its name is last
 * year's.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->drop();

        DB::statement("
            CREATE VIEW advisers AS
            SELECT s.id, s.course, s.section, s.school_year, s.semester,
                   u.name AS adviser, u.email, r.name AS role, u.department AS college,
                   CASE WHEN u.google_id IS NULL THEN 'pending first sign-in'
                        ELSE 'activated' END AS activated
            FROM sections s
            JOIN users u ON u.id = s.adviser_id
            JOIN roles r ON r.id = u.role_id
        ");
    }

    public function down(): void
    {
        $this->drop();
    }

    /** SQLite (the test suite) has no CREATE OR REPLACE VIEW. */
    private function drop(): void
    {
        DB::statement('DROP VIEW IF EXISTS advisers');
    }
};
