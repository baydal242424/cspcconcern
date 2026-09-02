<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Clears the programme from accounts whose role does not have one.
 *
 * Only two roles are programme-scoped: a Student, for the programme they are
 * enrolled in, and a Program Chair, for the one they chair. Everyone else
 * should carry none.
 *
 * They did anyway. The programme picker is hidden for other roles, but a
 * hidden field still submits, so promoting a student to any staff role carried
 * their programme across with them. findHandler() prefers a handler whose
 * course matches the reporter's before it considers anything else -- so a
 * former BSIS student promoted to Instructor silently became the preferred
 * handler for every BSIS concern in the college, ahead of colleagues who
 * should have shared the load.
 *
 * The controller now clears the field on every role change; this repairs the
 * accounts that were changed before it did.
 */
return new class extends Migration
{
    private const PROGRAMME_SCOPED = ['Student', 'Program Chair'];

    public function up(): void
    {
        $keep = DB::table('roles')->whereIn('name', self::PROGRAMME_SCOPED)->pluck('id');

        $affected = DB::table('users')
            ->whereNotNull('course')
            ->whereNotIn('role_id', $keep)
            ->update(['course' => null]);

        if ($affected > 0) {
            echo "  Cleared a stale programme from {$affected} account(s).\n";
        }
    }

    /**
     * Not reversible, and deliberately so. The values removed here were wrong
     * -- a programme on a role that has none -- and restoring them would mean
     * recording which accounts were misconfigured purely to be able to
     * misconfigure them again.
     */
    public function down(): void
    {
        //
    }
};
