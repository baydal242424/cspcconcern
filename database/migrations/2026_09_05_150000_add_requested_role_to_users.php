<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a staff member say what they are, without it being true yet.
 *
 * New employees arrive with nothing but a cspc.edu.ph address. The domain
 * proves they work here and nothing more -- a dean, a counsellor and an
 * instructor all share it -- so every account started as Faculty/Staff and an
 * Admin typed in the rest. For a college with hundreds of staff that meant the
 * admin filling in details only the person themselves knows.
 *
 * They can now fill it in on first sign-in. College, programme and section
 * apply straight away: they describe where somebody works and grant nothing.
 *
 * ROLE is different, and that is what this column is for. Role IS permission
 * in this system -- Concern::scopeVisibleTo() reads nothing else -- so a
 * self-assigned one would let anybody with a staff address pick Guidance
 * Counselor and read every mental-health and harassment report in the college.
 * The choice is recorded as a REQUEST and an administrator grants it.
 *
 * Nullable and empty for everyone: an account with no request pending is the
 * normal state, and clearing the column is how a request is answered either
 * way. role_requested_at is kept so the admin list can show how long somebody
 * has been waiting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // A plain column with an index, NOT a foreign key.
            //
            // SQLite -- which the test suite runs on -- cannot add a foreign
            // key with ALTER TABLE, so Laravel rebuilds the whole table
            // instead. Eleven people_* views select from users, and every one
            // of them breaks while that rebuild is in flight: the suite failed
            // with "no such table: main.users" in a view, on tests that had
            // nothing to do with this column.
            //
            // Nothing is lost by leaving it off. Roles are effectively never
            // deleted, this column is cleared the moment a request is answered
            // either way, and a stale id would render as a blank request the
            // admin can refuse.
            $table->unsignedBigInteger('requested_role_id')->nullable()->after('role_id')->index();
            $table->timestamp('role_requested_at')->nullable()->after('requested_role_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['requested_role_id', 'role_requested_at']);
        });
    }
};
