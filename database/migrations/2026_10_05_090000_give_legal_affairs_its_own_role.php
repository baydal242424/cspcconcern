<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;

/**
 * The Legal Affairs Office stops borrowing the Gender and Development role.
 *
 * referred_to stores a ROLE NAME, and there was no Legal Affairs role to
 * store -- so the office was filed under GAD to make it reachable at all. The
 * reasoning was sound and the effect was not: two unrelated offices behind
 * one door, so a handler choosing "Gender and Development" was offered the
 * lawyer, and choosing the lawyer meant choosing GAD.
 *
 * Only the Legal Affairs Office account moves. The Center for Gender and
 * Development keeps the GAD role, which is its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        $legal = Role::firstOrCreate(
            ['name' => 'Legal Affairs'],
            ['description' => 'Legal Affairs Office; receives cases needing legal counsel, including Disciplinary Board and CMO No. 3 s. 2022 referrals']
        );

        // Matched on the office, not the person: the same lawyer also holds a
        // Human Rights Education account, which is Faculty/Staff and stays
        // exactly as it is.
        User::where('department', 'Legal Affairs Office')
            ->whereHas('role', fn ($q) => $q->where('name', 'Gender and Development'))
            ->update(['role_id' => $legal->id]);
    }

    public function down(): void
    {
        $gad = Role::where('name', 'Gender and Development')->first();
        $legal = Role::where('name', 'Legal Affairs')->first();

        if ($gad && $legal) {
            User::where('role_id', $legal->id)->update(['role_id' => $gad->id]);

            // Anything referred there goes back to the door it came through,
            // or those cases become unreachable.
            \Illuminate\Support\Facades\DB::table('concerns')
                ->where('referred_to', 'Legal Affairs')
                ->update(['referred_to' => 'Gender and Development']);

            $legal->delete();
        }
    }
};
