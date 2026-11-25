<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An account can be named, one at a time, as part of a demonstration.
 *
 * On a deployed site the sign-in dropdown offers only addresses beginning
 * `test.` or `demo.`. That rule exists so nobody signs in as a real member
 * of staff on a site carrying the college's name, and it should stay.
 *
 * But a demonstration reads better as the people who actually hold the
 * posts -- the counsellor the students would really write to, not "Demo
 * Counselor" -- and the students taking part need their own addresses, so
 * the mail the system sends reaches them.
 *
 * So: an opt-in, per account. Somebody decides THIS account may be signed
 * into, and says so by name. The blanket rule still covers everybody else,
 * which is the whole roster but a handful.
 *
 * WHAT THIS DOES NOT CHANGE. google_id is untouched: the moment a real
 * person signs in with Google their id is stamped and they drop out of the
 * dropdown for good, flag or no flag. That is the fence that stops any of
 * this being an impersonation tool, and it is the one fence with no way
 * round it. Nor does this reach the System Admin, Staff Admin or Head of
 * School, who are refused on a deployed site however they are marked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // False by default, so every account that nobody has thought
            // about stays out of the dropdown.
            $table->boolean('demo_signin')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('demo_signin');
        });
    }
};
