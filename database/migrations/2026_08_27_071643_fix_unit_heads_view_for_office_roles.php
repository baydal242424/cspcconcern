<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds `unit_heads` so the office roles stop falling out of it.
 *
 * The old definition matched Faculty/Staff whose department was LIKE
 * '%Unit%' / '%Center%' / '%Office%'. That worked only while every office
 * head happened to hold Faculty/Staff. When General Services and Registrar
 * were given their own roles -- so concerns could route and be referred to
 * them -- both silently disappeared from this view, and from every other
 * view too: the General Services Unit and the Student Registration and
 * Records Office were in the database but listed nowhere.
 *
 * The fix is to match on ROLE first and treat the department pattern only as
 * a fallback for offices still sitting in Faculty/Staff. Any future office
 * role needs adding to the list below -- which is the same maintenance
 * burden as before, but now it fails visibly (an office missing from the
 * list) rather than depending on a department someone happened to name with
 * the word "Unit" in it.
 */
return new class extends Migration
{
    private function collegeList(): string
    {
        return collect(array_keys(\App\Models\User::COURSES_BY_COLLEGE))
            ->map(fn ($c) => "'".str_replace("'", "''", $c)."'")
            ->implode(', ');
    }

    public function up(): void
    {
        DB::statement('DROP VIEW IF EXISTS unit_heads');
        $colleges = $this->collegeList();

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
                -- A Department Head of something that is not a college
                -- (the Graduate School).
                OR (r.name = 'Department Head' AND u.department NOT IN ({$colleges}))
                -- Offices still carrying the generic Faculty/Staff role.
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
        // Restore the previous role-blind definition.
        DB::statement('DROP VIEW IF EXISTS unit_heads');
        $colleges = $this->collegeList();

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
};
