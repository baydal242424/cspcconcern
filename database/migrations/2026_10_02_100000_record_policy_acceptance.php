<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When somebody agreed to the policy, and to which version of it.
 *
 * The page existed and nobody had to read it. Recording the acceptance is
 * what turns "we published a policy" into "this student agreed to it on this
 * date" -- which is the half that matters if a report is ever disputed, and
 * what the Data Privacy Act expects of a notice like this.
 *
 * The version is stored beside the date on purpose. A policy that is rewritten
 * -- as this one was, in September -- is a different promise, and an agreement
 * to the old wording is not an agreement to the new one. Storing only a date
 * would leave no way to tell who had seen what.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('policy_accepted_at')->nullable()->after('email_verified_at');
            $table->string('policy_version', 20)->nullable()->after('policy_accepted_at');
        });

        // Everybody already using the system is left alone.
        //
        // The notice is for people arriving from here on. Stopping 896
        // existing accounts at a page on their next sign-in -- including every
        // dean and counsellor mid-case -- would be a change they never asked
        // for, to tell them about a system they already use. Anyone who has
        // signed in before has, in practice, been working under this policy
        // already; it is the new arrivals who have never been shown it.
        DB::table('users')
            ->whereNotNull('google_id')
            ->update([
                'policy_accepted_at' => now(),
                'policy_version' => \App\Models\User::POLICY_VERSION,
            ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['policy_accepted_at', 'policy_version']);
        });
    }
};
