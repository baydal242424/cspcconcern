<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Faults in this website go to the ICT Unit, not the System Admin.
 *
 * The two jobs are different. The System Admin role in this system manages
 * accounts and roles -- approving a registration, changing somebody's post,
 * banning an account. A page that will not load is a technical defect, and
 * the office that owns technology at the college is the ICT Unit, which
 * already handles Equipment and devices. A dead lab computer reaching Rey
 * and a dead web page reaching Allan was a split with nothing behind it.
 *
 * Done as a migration rather than as a click on the admin page, even though
 * the admin page can now do it. A click changes one database; this has to be
 * true on the deployed host as well, and a migration is the only thing that
 * travels.
 *
 * THE LIVE CASES MOVE. THE SETTLED ONES DO NOT.
 *
 * 23 of the 49 on file are still open, and those belong on the desk that
 * will now be answering for them. The other 26 are resolved or closed: the
 * record says the System Admin handled them, and that is what happened.
 * Reassigning finished work would make the audit trail say somebody dealt
 * with cases they never saw.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('concern_categories')
            ->where('name', 'Administrative')
            ->update(['routing_role' => 'ICT Unit', 'updated_at' => now()]);

        $ict = DB::table('users')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->where('roles.name', 'ICT Unit')
            ->whereNull('users.deleted_at')
            ->where('users.status', 'approved')
            ->value('users.id');

        // No ICT Unit account yet -- a fresh database being built before its
        // users are seeded, which is every test run. The routing above is
        // the part that matters; there is nothing to move.
        if (! $ict) {
            return;
        }

        // Somebody to record as the actor. audit_logs.user_id is NOT NULL,
        // and this is a decision the administration made rather than any one
        // person, so the System Admin signs for it.
        $actor = DB::table('users')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->where('roles.name', 'System Admin')
            ->whereNull('users.deleted_at')
            ->value('users.id') ?? $ict;

        $live = DB::table('concerns')
            ->where('category', 'Administrative')
            ->whereNotIn('status', ['resolved', 'closed_no_action', 'withdrawn'])
            ->where(function ($q) use ($ict) {
                $q->whereNull('assigned_to')->orWhere('assigned_to', '!=', $ict);
            })
            ->pluck('id');

        if ($live->isEmpty()) {
            return;
        }

        DB::table('concerns')->whereIn('id', $live)->update([
            'assigned_to' => $ict,
            'updated_at' => now(),
        ]);

        $now = now();

        DB::table('audit_logs')->insert(
            $live->map(fn ($id) => [
                'user_id' => $actor,
                'concern_id' => $id,
                'action' => 'status_updated',
                // Written as a referral, because that is what it is: the
                // case moved from one desk to another, and the student's
                // timeline reads hand-offs. Anything else would leave a gap
                // where the case changed hands with no entry saying so.
                'description' => 'Referred to ICT Unit',
                'created_at' => $now,
                'updated_at' => $now,
            ])->all()
        );
    }

    public function down(): void
    {
        DB::table('concern_categories')
            ->where('name', 'Administrative')
            ->update(['routing_role' => 'System Admin', 'updated_at' => now()]);

        // The concerns are not moved back. Where a case sits is a fact about
        // who is working it, and by the time anybody runs this the ICT Unit
        // may have done exactly that.
    }
};
