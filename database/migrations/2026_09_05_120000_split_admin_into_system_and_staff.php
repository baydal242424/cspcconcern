<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Splits the single Admin role into two tiers.
 *
 *   System Admin  the people who run the system: accounts, roles, bans, the
 *                 start-of-year promotion. Only a System Admin can hand out
 *                 either admin role.
 *
 *   Staff Admin   the administrative office. Receives Administrative concerns
 *                 -- enrolment, records, ID, clearance, fees -- and refers on
 *                 to whichever office owns the request.
 *
 * They were one role, and that conflated two unrelated jobs. Every
 * Administrative concern landed on whoever could also delete accounts and
 * change roles, which in practice meant the student who built the app was
 * reading complaints about the registrar's queue. Splitting them means the
 * office handles the office's work and the system's keys stay with the people
 * who need them.
 *
 * The existing Admin role is RENAMED to System Admin rather than replaced, so
 * every account, concern and audit entry that already points at it follows
 * automatically -- no ids change, nothing is reassigned.
 *
 * Staff Admin starts empty. Nobody is promoted into it by this migration:
 * who administers what is a decision for a person, not a pattern in the data.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('roles')->where('name', 'Admin')->update([
            'name' => 'System Admin',
            'description' => 'Runs the system: accounts, roles, bans, and the start-of-year promotion.',
            'updated_at' => now(),
        ]);

        if (! DB::table('roles')->where('name', 'Staff Admin')->exists()) {
            DB::table('roles')->insert([
                'name' => 'Staff Admin',
                'description' => 'The administrative office. Handles Administrative concerns: enrolment, records, ID, clearance and fees.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Concerns referred to the old destination keep pointing somewhere
        // real. They were sent to "the Administration", which is now the
        // office rather than the system's operators.
        DB::table('concerns')->where('referred_to', 'Admin')->update(['referred_to' => 'Staff Admin']);
    }

    public function down(): void
    {
        // Anyone made a Staff Admin goes back to the single Admin role rather
        // than being left pointing at a role that no longer exists.
        $staffAdmin = DB::table('roles')->where('name', 'Staff Admin')->value('id');
        $systemAdmin = DB::table('roles')->where('name', 'System Admin')->value('id');

        if ($staffAdmin && $systemAdmin) {
            DB::table('users')->where('role_id', $staffAdmin)->update(['role_id' => $systemAdmin]);
        }

        DB::table('roles')->where('name', 'Staff Admin')->delete();

        DB::table('roles')->where('name', 'System Admin')->update([
            'name' => 'Admin',
            'description' => 'System administrator.',
            'updated_at' => now(),
        ]);

        DB::table('concerns')->where('referred_to', 'Staff Admin')->update(['referred_to' => 'Admin']);
    }
};
