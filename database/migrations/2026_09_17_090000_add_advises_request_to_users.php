<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The class a staff member SAYS they advise, waiting for an administrator.
 *
 * Asked on the staff sign-up form, where it is optional. It is kept apart from
 * the two places that actually route concerns -- the sections table, and the
 * account's own programme -- because either would start sending a class's
 * concerns to this person on nobody's word but their own. An administrator
 * confirms it from Manage Users with Add class, which writes the real
 * assignment and clears this.
 *
 * Stored as "BS Information Systems|4A": the programme and the section, the
 * same pair Section::adviserFor() matches on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('advises_request', 150)->nullable()->after('section');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('advises_request');
        });
    }
};
