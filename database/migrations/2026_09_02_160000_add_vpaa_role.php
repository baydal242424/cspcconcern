<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds the Vice President for Academic Affairs.
 *
 * The escalation chain ran Dean -> Head of School -> Admin, with Admin as the
 * last resort so nothing could end up unassigned. That left one case with no
 * good answer: a concern ABOUT the Admin. Admin is excluded from handling it by
 * the conflict-of-interest rule, so it fell to a college dean -- who has no
 * authority over a system administrator -- or, with no eligible dean, to
 * nobody at all.
 *
 * The VPAA sits above the Administration, so a complaint the Admin cannot take
 * now goes UP rather than sideways.
 *
 * No account is created here. CSPC publishes no address for the post, and
 * sign-in matches people by email: a guessed one would create a second, empty
 * account on that person's first sign-in while the seeded one sat in the
 * escalation chain unreachable. The role is ready; assign it at /admin/users
 * once the holder has signed in, or seed them when the address is confirmed.
 */
return new class extends Migration
{
    private const NAME = 'Vice President for Academic Affairs';

    public function up(): void
    {
        if (DB::table('roles')->where('name', self::NAME)->exists()) {
            return;
        }

        DB::table('roles')->insert([
            'name' => self::NAME,
            'description' => 'Oversees the academic division; receives concerns the Administration cannot handle itself',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Anyone holding it becomes ordinary staff rather than losing their
        // account along with the role.
        $vpaa = DB::table('roles')->where('name', self::NAME)->value('id');
        $faculty = DB::table('roles')->where('name', 'Faculty/Staff')->value('id');

        if ($vpaa && $faculty) {
            DB::table('users')->where('role_id', $vpaa)->update(['role_id' => $faculty]);
            DB::table('concerns')->where('referred_to', self::NAME)->update(['referred_to' => 'Admin']);
            DB::table('roles')->where('id', $vpaa)->delete();
        }
    }
};
