<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Two read-only views that show the student/employee split as browsable
 * entries in phpMyAdmin, next to the real tables.
 *
 * They are VIEWS, not tables, and that distinction is the whole point. A view
 * stores no rows of its own -- it is a saved query over users + roles,
 * re-evaluated every time it is read. So it can never drift out of sync with
 * the accounts it describes: promote someone in /admin/users and they move
 * between these two immediately, with nothing to keep updated by hand and no
 * second source of truth for a permission check to disagree with.
 *
 * The split follows ROLE, not the email domain. The domain only decides what
 * a brand-new account starts as (AuthController::DOMAIN_ROLES); role_id is
 * what Concern::scopeVisibleTo() actually enforces, and an Admin can change
 * it afterwards.
 *
 * Nothing in the application reads these -- App\Models\User::students() and
 * ::employees() are the code path. They exist so the classification is
 * legible to a human looking at the database.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->drop();

        // Students: the people who file concerns.
        DB::statement("
            CREATE VIEW students AS
            SELECT u.id, u.name, u.email, u.student_id, u.department AS college,
                   u.course, u.status, u.last_seen_at, u.created_at
            FROM users u
            JOIN roles r ON r.id = u.role_id
            WHERE r.name = 'Student'
        ");

        // Employees: faculty, deans, counselors, admins, Head of School --
        // the people who handle concerns.
        DB::statement("
            CREATE VIEW employees AS
            SELECT u.id, u.name, u.email, r.name AS role, u.department AS office,
                   u.status, u.last_seen_at, u.created_at
            FROM users u
            JOIN roles r ON r.id = u.role_id
            WHERE r.name <> 'Student'
        ");
    }

    public function down(): void
    {
        $this->drop();
    }

    /**
     * DROP before CREATE rather than CREATE OR REPLACE: SQLite (which the
     * test suite runs on) has no REPLACE form for views.
     */
    private function drop(): void
    {
        DB::statement('DROP VIEW IF EXISTS students');
        DB::statement('DROP VIEW IF EXISTS employees');
    }
};
