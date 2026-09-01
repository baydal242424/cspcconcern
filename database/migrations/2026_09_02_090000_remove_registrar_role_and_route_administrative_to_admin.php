<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Removes the Registrar role and sends Administrative concerns to Admin.
 *
 * The role is deleted; the PERSON is not. Dr. Malaya has handled concerns and
 * written audit entries, and deleting her account would either cascade those
 * rows away or fail on a foreign key -- and an audit trail with the actor
 * removed is worse than no audit trail. She moves to Faculty/Staff, which also
 * keeps her in the unit_heads view: that view already catches Faculty/Staff
 * accounts whose department matches '%Records%', and hers is "Student
 * Registration and Records".
 *
 * Anything still referred to "Registrar" is repointed at Admin in the same
 * step. concerns.referred_to stores the destination role as a string, so a
 * concern left naming a deleted role would match nothing and become invisible
 * to every office at once.
 *
 * The down() path restores the role and moves the records staff back into it,
 * matching on department. That is a heuristic, not a record: this migration
 * does not remember who held the role, so anyone else who happened to be a
 * Registrar is not restored.
 */
return new class extends Migration
{
    public function up(): void
    {
        $registrar = DB::table('roles')->where('name', 'Registrar')->first();

        if ($registrar) {
            $faculty = DB::table('roles')->where('name', 'Faculty/Staff')->first();

            if ($faculty) {
                DB::table('users')
                    ->where('role_id', $registrar->id)
                    ->update(['role_id' => $faculty->id]);
            }

            DB::table('concerns')->where('referred_to', 'Registrar')->update(['referred_to' => 'Admin']);
            DB::table('roles')->where('id', $registrar->id)->delete();
        }

        $this->rebuildUnitHeads(false);
    }

    public function down(): void
    {
        $id = DB::table('roles')->insertGetId([
            'name' => 'Registrar',
            'description' => 'Student Registration and Records Office; handles enrolment, records, credentials and other administrative concerns',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('users')
            ->where('department', 'Student Registration and Records')
            ->update(['role_id' => $id]);

        DB::table('concerns')->where('referred_to', 'Registrar')->update(['referred_to' => 'Registrar']);

        $this->rebuildUnitHeads(true);
    }

    /**
     * unit_heads names the office roles literally, so it has to be rebuilt
     * rather than left joining against a role that no longer exists.
     */
    private function rebuildUnitHeads(bool $withRegistrar): void
    {
        $colleges = collect(array_keys(\App\Models\User::COURSES_BY_COLLEGE))
            ->map(fn ($c) => "'".str_replace("'", "''", $c)."'")
            ->implode(', ');

        $officeRoles = $withRegistrar
            ? "'Head of School', 'General Services', 'Registrar', 'Gender and Development'"
            : "'Head of School', 'General Services', 'Gender and Development'";

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
                r.name IN ({$officeRoles})
                -- The head of something that is not a college (the Graduate
                -- School), who carries the college-level role regardless.
                OR (r.name = 'Dean' AND u.department NOT IN ({$colleges}))
                -- Offices carrying the generic Faculty/Staff role -- which now
                -- includes Student Registration and Records.
                OR (r.name = 'Faculty/Staff'
                    AND (u.department LIKE '%Unit%'
                      OR u.department LIKE '%Center%'
                      OR u.department LIKE '%Office%'
                      OR u.department LIKE '%Records%'
                      OR u.department LIKE '%Services%'))
        ");
    }
};
