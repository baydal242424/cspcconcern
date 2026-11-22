<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Two offices that existed as departments become roles that receive work.
 *
 * THE CLINIC. A physical injury went to the Guidance Counselor, because
 * every category that was not obviously somebody else's went there. A
 * student who has been hurt needs a nurse first; counselling is what comes
 * after, if it comes at all. Mr. Manzano was already in the system as
 * Faculty/Staff in the Health Services Unit -- a department, which routing
 * cannot see -- so the clinic could be named in a concern and never
 * handed one.
 *
 * His address changes with it. He was listed under the office mailbox,
 * collegeclinic@cspc.edu.ph; sign-in is Google and he signs in as himself,
 * so the account carries his own address now. One person, one account: two
 * would mean a concern could reach whichever the routing happened to pick.
 *
 * THE LIBRARY is new. It had no category at all, so a complaint about a
 * fine, a missing book or the reading room was filed under "Others" and
 * routed to a class adviser.
 *
 * THE LIVE PHYSICAL CASES MOVE; THE SETTLED ONES DO NOT. The record says
 * the Guidance Office handled those, and it did. Reassigning finished work
 * would make the audit trail claim somebody dealt with cases they never
 * saw -- the same rule the ICT Unit move followed.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        foreach ([
            'Clinic' => 'The college clinic: injuries, illness and first aid.',
            'Library' => 'The library: borrowing, fines, clearance and the reading room.',
        ] as $role => $description) {
            if (! DB::table('roles')->where('name', $role)->exists()) {
                DB::table('roles')->insert([
                    'name' => $role,
                    'description' => $description,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        $clinicRole = DB::table('roles')->where('name', 'Clinic')->value('id');

        // Mr. Manzano: his own address, and the role that receives the work.
        //
        // Found by either address so this is safe to re-run and safe on a
        // database where somebody has already changed one of them.
        $manzano = DB::table('users')
            ->whereIn('email', ['collegeclinic@cspc.edu.ph', 'mamanzano@cspc.edu.ph'])
            ->first();

        if ($manzano) {
            DB::table('users')->where('id', $manzano->id)->update([
                'email' => 'mamanzano@cspc.edu.ph',
                'role_id' => $clinicRole,
                'department' => 'Health Services Unit',
                'status' => 'approved',
                'updated_at' => $now,
            ]);
        }

        // Physical injuries go to the clinic.
        DB::table('concern_categories')
            ->where('name', 'Physical')
            ->update(['routing_role' => 'Clinic', 'updated_at' => $now]);

        // The library, with the things a student actually comes to the desk
        // about. Each carries no office of its own -- they all belong to
        // the same desk -- which is what a null route means.
        if (! DB::table('concern_categories')->where('name', 'Library')->exists()) {
            DB::table('concern_categories')->insert([
                'name' => 'Library',
                'label' => 'Library',
                'routing_role' => 'Library',
                'subcategories' => json_encode(array_map(
                    fn (string $name) => ['name' => $name, 'route' => null],
                    [
                        'Borrowing and returns',
                        'Fines and penalties',
                        'Missing or damaged book',
                        'Clearance',
                        'Opening hours',
                        'Noise or behaviour',
                        'Library staff conduct',
                        'Other library concern',
                    ]
                )),
                'is_active' => true,
                'is_builtin' => false,
                'sort_order' => (int) DB::table('concern_categories')->max('sort_order') + 10,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if (! $manzano || ! $clinicRole) {
            return;
        }

        // The open physical injuries move to the desk that will answer for
        // them. Settled ones stay where the record says they were handled.
        $live = DB::table('concerns')
            ->where('category', 'Physical')
            ->whereNotIn('status', ['resolved', 'closed_no_action', 'withdrawn'])
            ->where(function ($q) use ($manzano) {
                $q->whereNull('assigned_to')->orWhere('assigned_to', '!=', $manzano->id);
            })
            ->pluck('id');

        if ($live->isEmpty()) {
            return;
        }

        DB::table('concerns')->whereIn('id', $live)->update([
            'assigned_to' => $manzano->id,
            'updated_at' => $now,
        ]);

        // audit_logs.user_id is NOT NULL, and this is the administration's
        // decision rather than any one person's, so the System Admin signs.
        $actor = DB::table('users')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->where('roles.name', 'System Admin')
            ->whereNull('users.deleted_at')
            ->value('users.id') ?? $manzano->id;

        DB::table('audit_logs')->insert(
            $live->map(fn ($id) => [
                'user_id' => $actor,
                'concern_id' => $id,
                'action' => 'status_updated',
                // Written as a referral because that is what it is: the
                // case changed desks, and the student's timeline reads
                // hand-offs. Anything else leaves a gap where it moved
                // with no entry saying so.
                'description' => 'Referred to Clinic',
                'created_at' => $now,
                'updated_at' => $now,
            ])->all()
        );
    }

    public function down(): void
    {
        DB::table('concern_categories')
            ->where('name', 'Physical')
            ->update(['routing_role' => 'Guidance Counselor', 'updated_at' => now()]);

        DB::table('concern_categories')->where('name', 'Library')->delete();

        // The address and the concerns are left where they are. Who holds a
        // case is a fact about who is working it, and by the time anybody
        // runs this the clinic may have done exactly that.
    }
};
