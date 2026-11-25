<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Student Affairs and Services becomes a place a case can be sent.
 *
 * A REFERRAL DESTINATION, NOT A FIRST DESK. Every office added before this
 * one came with a category: the Clinic took Physical, the Library and the
 * Registrar were given categories of their own. SAS is deliberately
 * different. No category routes to it, so nothing arrives there unasked --
 * it appears only in the "Refer to" dropdown of a handler who has read a
 * case and decided it is student-affairs work.
 *
 * WHICH DESKS CAN REACH IT. Mental Health, Personal and Bullying, in
 * ConcernController::CATEGORY_REFERRAL_ROLES. Those three are where the
 * Guidance Counselor sits, and a welfare matter that needs the student
 * affairs office is one the counsellor is first to see.
 *
 * WHY IT HAD TO BE NAMED BY A CATEGORY AT ALL. referralRolesFor() works
 * out the offices reachable only by CLIMBING by subtracting every category
 * list from REFERRAL_ROLES -- so a role in REFERRAL_ROLES and in no
 * category list is treated as though it sat beside the Dean and the VPAA,
 * and becomes reachable from nowhere. That is exactly what happened to the
 * Registrar when it was first added. Three categories name SAS, so it does
 * not fall into that hole.
 *
 * NOTHING MOVES. No live concern is reassigned, because no concern was
 * ever routed here and the office has held none. There is no history to
 * rewrite, which is why this migration touches the concerns table not at
 * all.
 */
return new class extends Migration
{
    private const ROLE = 'Student Affairs and Services';

    private const EMAIL = 'sas@cspc.edu.ph';

    public function up(): void
    {
        $now = now();

        if (! DB::table('roles')->where('name', self::ROLE)->exists()) {
            DB::table('roles')->insert([
                'name' => self::ROLE,
                'description' => 'Student Affairs and Services: student welfare, discipline and student organisations.',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $roleId = DB::table('roles')->where('name', self::ROLE)->value('id');

        if (! $roleId) {
            return;
        }

        // Dr. Luzon. Promoted if the address is already here under another
        // role, created if it is not -- so this is safe to re-run and safe
        // on a database where somebody has signed in first.
        $existing = DB::table('users')->where('email', self::EMAIL)->first();

        if ($existing) {
            DB::table('users')->where('id', $existing->id)->update([
                'name' => 'Dr. Jay L. Luzon',
                'role_id' => $roleId,
                'department' => self::ROLE,
                'status' => 'approved',
                'updated_at' => $now,
            ]);

            return;
        }

        DB::table('users')->insert([
            'name' => 'Dr. Jay L. Luzon',
            'email' => self::EMAIL,
            // Sign-in is Google, so the password is never used. A random
            // one rather than a known string, so the row cannot be signed
            // into if that ever changes.
            'password' => bcrypt(bin2hex(random_bytes(16))),
            'role_id' => $roleId,
            'department' => self::ROLE,
            'status' => 'approved',
            'email_verified_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        // The account stays. Taking the role off it would leave a real
        // person signed up with no role at all, and deleting the row would
        // take their name off any concern that names them. The role itself
        // goes only if nobody holds it.
        $roleId = DB::table('roles')->where('name', self::ROLE)->value('id');

        if ($roleId && ! DB::table('users')->where('role_id', $roleId)->exists()) {
            DB::table('roles')->where('id', $roleId)->delete();
        }
    }
};
