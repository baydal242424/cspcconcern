<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Safety Hazard leaves the Guidance Office for General Services.
 *
 * WHY IT WAS WRONG. Read the sub-categories: a damaged stair or floor,
 * exposed wiring, a blocked exit, a slippery area, a fire risk. Every one
 * is a repair. None is counselling, and the category no longer asks who
 * the concern is about -- that question was taken off the form -- so the
 * one thing a counsellor was there for is not even collected any more.
 *
 * WHAT IT COST. Sixty-six hazards were filed. All sixty-six sat with the
 * Guidance Counselor, and not one was ever referred to General Services:
 * there is no audit row saying so. Twenty-seven were still open -- blocked
 * exits and exposed wiring on the desk of somebody with no means to act on
 * them. Meanwhile Building & Facilities, the same work with less urgency,
 * went straight to General Services. The more dangerous queue was taking
 * the longer road.
 *
 * GUIDANCE IS STILL REACHABLE. It stays in Safety's referral list, for the
 * student frightened by what they saw rather than hurt by it. What changes
 * is which desk the case LANDS on.
 *
 * THE OPEN ONES MOVE; THE SETTLED ONES DO NOT. The same rule the clinic and
 * the ICT Unit moves followed. Reassigning finished work would make the
 * audit trail claim somebody dealt with cases they never saw.
 */
return new class extends Migration
{
    private const DONE = ['resolved', 'closed_no_action', 'withdrawn'];

    public function up(): void
    {
        $now = now();

        DB::table('concern_categories')
            ->where('name', 'Safety')
            ->update(['routing_role' => 'General Services', 'updated_at' => $now]);

        $office = DB::table('users')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->where('roles.name', 'General Services')
            ->whereNull('users.deleted_at')
            ->value('users.id');

        if (! $office) {
            return;
        }

        $live = DB::table('concerns')
            ->where('category', 'Safety')
            ->whereNotIn('status', self::DONE)
            ->where(function ($q) use ($office) {
                $q->whereNull('assigned_to')->orWhere('assigned_to', '!=', $office);
            })
            ->pluck('id');

        if ($live->isEmpty()) {
            return;
        }

        DB::table('concerns')->whereIn('id', $live)->update([
            'assigned_to' => $office,
            'updated_at' => $now,
        ]);

        // audit_logs.user_id is NOT NULL, and this is the administration's
        // decision rather than any one person's, so the System Admin signs.
        $actor = DB::table('users')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->where('roles.name', 'System Admin')
            ->whereNull('users.deleted_at')
            ->value('users.id') ?? $office;

        // Written as a referral because that is what it is: the case changed
        // desks, and the student's timeline reads hand-offs. Anything else
        // leaves a gap where it moved with no entry saying so.
        DB::table('audit_logs')->insert(
            $live->map(fn ($id) => [
                'user_id' => $actor,
                'concern_id' => $id,
                'action' => 'status_updated',
                'description' => 'Referred to General Services',
                'created_at' => $now,
                'updated_at' => $now,
            ])->all()
        );
    }

    public function down(): void
    {
        DB::table('concern_categories')
            ->where('name', 'Safety')
            ->update(['routing_role' => 'Guidance Counselor', 'updated_at' => now()]);

        // Who holds a case is a fact about who is working it, and by the time
        // anybody runs this General Services may have done exactly that.
    }
};
