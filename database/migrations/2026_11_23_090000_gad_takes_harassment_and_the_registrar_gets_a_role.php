<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Three offices put where the routing can see them.
 *
 * HARASSMENT GOES TO GAD. It reached the Guidance Counselor, because the
 * counselling categories all did. The Center for Gender and Development
 * exists for exactly this and already held the role; it was simply never
 * made the destination, so every harassment case arrived at a desk that
 * had to pass it on.
 *
 * Counselling is still part of the answer, which is why GAD can now refer
 * to Guidance -- see ROLE_REFERRAL_ROLES. The case starts with the office
 * that owns it and reaches the counsellor from there, rather than the
 * other way round.
 *
 * THE LIBRARY GETS ITS LIBRARIAN. The role was created with the category
 * and left empty, so a library concern climbed the escalation chain to
 * somebody who could do nothing about it.
 *
 * THE REGISTRAR BECOMES A ROLE. Dr. Malaya held the post as Faculty/Staff
 * -- a department, which routing cannot see. No category points at the
 * Registrar yet; this makes the office nameable and referrable, and the
 * admin page can point a category at it without another deployment.
 *
 * THE LIVE HARASSMENT CASES MOVE; THE SETTLED ONES DO NOT. 30 of the 67
 * are still open and belong on the desk that will answer for them. The
 * other 37 say the Guidance Office handled them, and it did.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        if (! DB::table('roles')->where('name', 'Registrar')->exists()) {
            DB::table('roles')->insert([
                'name' => 'Registrar',
                'description' => 'Student Registration and Records; handles records, enrolment documents and transcripts',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $registrarRole = DB::table('roles')->where('name', 'Registrar')->value('id');
        $libraryRole = DB::table('roles')->where('name', 'Library')->value('id');
        $gadRole = DB::table('roles')->where('name', 'Gender and Development')->value('id');

        // Dr. Malaya, already in the system under the records office.
        if ($registrarRole) {
            DB::table('users')
                ->where('email', 'registrar@cspc.edu.ph')
                ->update(['role_id' => $registrarRole, 'updated_at' => $now]);
        }

        // The librarian. Created rather than promoted: there was nobody
        // in the role at all, and the seeded placeholder is a stand-in
        // for a fresh database rather than a person.
        if ($libraryRole && ! DB::table('users')->where('email', 'lrds@cspc.edu.ph')->exists()) {
            DB::table('users')->insert([
                'name' => 'Mr. Yves Aristeo A. Febres',
                'email' => 'lrds@cspc.edu.ph',
                // Sign-in is Google, so the password is never used. A
                // random one rather than a known string, so the row
                // cannot be signed into if that ever changes.
                'password' => bcrypt(bin2hex(random_bytes(16))),
                'role_id' => $libraryRole,
                'department' => 'Library and Resource Development Services',
                'status' => 'approved',
                'email_verified_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // The placeholder seeded with the category steps aside now that a
        // real librarian holds the desk. Two accounts in one office means
        // a concern reaches whichever the routing happens to pick.
        DB::table('users')->where('email', 'library@cspc.edu.ph')->delete();

        if (! $gadRole) {
            return;
        }

        DB::table('concern_categories')
            ->where('name', 'Harassment')
            ->update(['routing_role' => 'Gender and Development', 'updated_at' => $now]);

        $gad = DB::table('users')
            ->where('role_id', $gadRole)
            ->whereNull('deleted_at')
            ->where('status', 'approved')
            ->value('id');

        if (! $gad) {
            return;
        }

        $live = DB::table('concerns')
            ->where('category', 'Harassment')
            ->whereNotIn('status', ['resolved', 'closed_no_action', 'withdrawn'])
            ->where(function ($q) use ($gad) {
                $q->whereNull('assigned_to')->orWhere('assigned_to', '!=', $gad);
            })
            ->pluck('id');

        if ($live->isEmpty()) {
            return;
        }

        DB::table('concerns')->whereIn('id', $live)->update([
            'assigned_to' => $gad,
            'updated_at' => $now,
        ]);

        // audit_logs.user_id is NOT NULL, and this is the administration's
        // decision rather than any one person's.
        $actor = DB::table('users')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->where('roles.name', 'System Admin')
            ->whereNull('users.deleted_at')
            ->value('users.id') ?? $gad;

        DB::table('audit_logs')->insert(
            $live->map(fn ($id) => [
                'user_id' => $actor,
                'concern_id' => $id,
                'action' => 'status_updated',
                'description' => 'Referred to Gender and Development',
                'created_at' => $now,
                'updated_at' => $now,
            ])->all()
        );
    }

    public function down(): void
    {
        DB::table('concern_categories')
            ->where('name', 'Harassment')
            ->update(['routing_role' => 'Guidance Counselor', 'updated_at' => now()]);

        // The accounts and the concerns stay. Who holds a case is a fact
        // about who is working it, and an account is a person.
    }
};
