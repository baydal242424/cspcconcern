<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Equipment leaves General Services and becomes the ICT Unit's.
 *
 * The two were one office because a student reporting a broken anything
 * should not have to decide which maintenance office owns it. In practice
 * they are different work: a dead lab PC, a projector that will not show
 * anything, a printer out of toner are ICT's, while a broken aircon, a
 * blocked fire exit and a classroom with no water are General Services'.
 * One person was receiving both queues.
 *
 * Facilities stays where it is. Only Equipment moves, and the concerns
 * already filed under it move with the category rather than being left
 * behind on a desk that no longer owns the subject.
 */
return new class extends Migration
{
    public function up(): void
    {
        $existing = DB::table('roles')->where('name', 'ICT Unit')->value('id');

        $roleId = $existing ?: DB::table('roles')->insertGetId([
            'name' => 'ICT Unit',
            'description' => 'Handles computers, printers, projectors and other equipment.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // The person this was created for. Written here rather than left to
        // be added by hand, because the routing below has nowhere to send an
        // Equipment concern until somebody holds the role -- and a category
        // routing to an empty role escalates away silently.
        $user = DB::table('users')->where('email', 'reytcortez@cspc.edu.ph')->first();

        if ($user) {
            DB::table('users')->where('id', $user->id)->update([
                'role_id' => $roleId,
                'status' => 'approved',
                'updated_at' => now(),
            ]);

            $userId = $user->id;
        } else {
            $userId = DB::table('users')->insertGetId([
                // Taken from the address. Correct it in Manage Users if the
                // spelling is wrong; nothing else depends on it.
                'name' => 'Rey T. Cortez',
                'email' => 'reytcortez@cspc.edu.ph',
                // No password is ever used: sign-in is Google only. A random
                // hash means the column is not empty and no value exists that
                // anybody could type.
                'password' => bcrypt(bin2hex(random_bytes(16))),
                'role_id' => $roleId,
                // The spelling AuthController offers staff when they pick a
                // department. A different one here would show this person as
                // belonging to an office that is not on the list.
                'department' => 'Information and Communications Technology Unit',
                'status' => 'approved',
                'email_verified_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Every Equipment concern already on file, including the settled
        // ones. The alternative leaves the person who now owns the subject
        // unable to read a single case about it -- visibility follows the
        // desk a concern sits on, so the history would be invisible to them.
        DB::table('concerns')
            ->where('category', 'Equipment')
            ->update(['assigned_to' => $userId, 'updated_at' => now()]);
    }

    public function down(): void
    {
        $roleId = DB::table('roles')->where('name', 'ICT Unit')->value('id');

        if (! $roleId) {
            return;
        }

        $gsu = DB::table('roles')->where('name', 'General Services')->value('id');
        $handler = $gsu
            ? DB::table('users')->where('role_id', $gsu)->value('id')
            : null;

        if ($handler) {
            DB::table('concerns')
                ->where('category', 'Equipment')
                ->update(['assigned_to' => $handler, 'updated_at' => now()]);
        }

        if ($gsu) {
            DB::table('users')->where('role_id', $roleId)->update(['role_id' => $gsu]);
        }

        DB::table('roles')->where('id', $roleId)->delete();
    }
};
