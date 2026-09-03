<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds the Adviser, a tier between Instructor and Program Chair.
 *
 * A student's first point of contact for an academic matter is their adviser,
 * not whichever instructor happens to hold the role in their college. The
 * ladder now reads:
 *
 *     Instructor -> Adviser -> Program Chair -> Dean -> Head of School
 *
 * Advisers are attached to a COLLEGE, like deans, because that is the only
 * scope the data supports: users records a college but no section or year
 * level. Section-level advising would be more accurate and needs a column
 * somebody maintains every term, which is why it was left out.
 *
 * No account is created and nobody is promoted. Who advises is a decision for
 * the college, not something a migration can infer from a role -- so the role
 * is created empty and assigned at /admin/users. Until a college has one,
 * routeConcern falls back to an Instructor there rather than escalating, which
 * keeps academic concerns landing where they land today.
 */
return new class extends Migration
{
    private const NAME = 'Adviser';

    public function up(): void
    {
        if (DB::table('roles')->where('name', self::NAME)->exists()) {
            return;
        }

        DB::table('roles')->insert([
            'name' => self::NAME,
            'description' => 'Academic adviser for a college; first handler for academic, safety and general concerns',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $adviser = DB::table('roles')->where('name', self::NAME)->value('id');
        $instructor = DB::table('roles')->where('name', 'Instructor')->value('id');

        if ($adviser && $instructor) {
            // Anyone holding it drops to the tier below rather than losing
            // their account with the role.
            DB::table('users')->where('role_id', $adviser)->update(['role_id' => $instructor]);
            DB::table('concerns')->where('referred_to', self::NAME)->update(['referred_to' => 'Instructor']);
            DB::table('roles')->where('id', $adviser)->delete();
        }
    }
};
