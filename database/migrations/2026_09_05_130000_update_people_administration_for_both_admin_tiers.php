<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds people_administration now that Admin has become two roles.
 *
 * The view matched on 'Admin', which no longer exists, so it was returning the
 * VPAA alone -- and that is the exact failure the view was created to catch:
 * a role changes name and quietly falls out of every list, leaving people in
 * the database who appear nowhere. The lesson is that a view spelling a role
 * name has to be revisited with the role, so this one now carries both tiers
 * and says which is which.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP VIEW IF EXISTS people_administration');

        DB::statement("
            CREATE VIEW people_administration AS
            SELECT u.id, u.name, u.email, r.name AS role, u.department AS office,
                   CASE WHEN u.google_id IS NULL THEN 'pending first sign-in'
                        ELSE 'activated' END AS activated,
                   u.status, u.last_seen_at
            FROM users u
            JOIN roles r ON r.id = u.role_id
            WHERE r.name IN ('System Admin', 'Staff Admin',
                             'Vice President for Academic Affairs')
        ");
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS people_administration');

        DB::statement("
            CREATE VIEW people_administration AS
            SELECT u.id, u.name, u.email, r.name AS role, u.department AS office,
                   CASE WHEN u.google_id IS NULL THEN 'pending first sign-in'
                        ELSE 'activated' END AS activated,
                   u.status, u.last_seen_at
            FROM users u
            JOIN roles r ON r.id = u.role_id
            WHERE r.name IN ('Admin', 'Vice President for Academic Affairs')
        ");
    }
};
