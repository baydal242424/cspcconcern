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

        // "Others" is pinned to the bottom of the list, for good.
        //
        // It was simply the last one seeded, so the Library landed under
        // it -- and a named category sitting below the bucket for
        // everything that has no name reads as an afterthought to it.
        // A number nothing will reach keeps it there however many
        // categories the office adds.
        DB::table('concern_categories')
            ->where('name', 'Others')
            ->update(['sort_order' => 9999, 'updated_at' => $now]);

        // The library, with the things only the library can answer for.
        //
        // Three, not the eight this started with. The test for a
        // sub-category is not "could it happen in the library" but "is
        // the library both the subject and the only office that can
        // settle it". Borrowing and returning is a transaction rather
        // than a grievance. A missing or damaged book is the library's
        // complaint about the STUDENT, pointing the wrong way. Opening
        // hours is a suggestion. And the reading room itself -- hot,
        // dark, broken chairs -- is already Facilities, which has Rooms,
        // Aircon, Lighting and Furniture; noisy people in it are either
        // Bullying or nothing.
        //
        // What is left is a disputed charge, an unsigned clearance
        // holding up an enrolment or a graduation, and the conduct of
        // somebody at the desk. Each carries no office of its own --
        // they all belong to the same desk -- which is what a null route
        // means.
        if (! DB::table('concern_categories')->where('name', 'Library')->exists()) {
            DB::table('concern_categories')->insert([
                'name' => 'Library',
                'label' => 'Library',
                'routing_role' => 'Library',
                'subcategories' => json_encode(array_map(
                    fn (string $name) => ['name' => $name, 'route' => null],
                    [
                        'Fines and penalties',
                        'Clearance',
                        'Library staff conduct',
                        'Other library concern',
                    ]
                )),
                'is_active' => true,
                'is_builtin' => false,
                // Above "Others", which is pinned last below. A category
                // added after the bucket for everything-else reads as
                // though it were an afterthought to it.
                'sort_order' => 105,
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
