<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop year_level and section.
 *
 * They were collected once, on /complete-profile, and never again -- so a
 * student who first signed in during 3rd year stayed a "3rd Year" in the
 * database for the rest of their degree. Sections change just as often.
 *
 * Neither was ever used for routing or permissions: a concern inherits its
 * reporter's COLLEGE (users.department), which is what routeConcern() and
 * Concern::scopeVisibleTo() read. So the columns bought nothing and
 * guaranteed wrong data -- worse than no data, because a dean looking at a
 * case would trust it.
 *
 * If year level is ever genuinely needed, it should be derived (from the
 * student number's admission year) or re-asked each term, not stored once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['year_level', 'section']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('year_level')->nullable()->after('course');
            $table->string('section')->nullable()->after('year_level');
        });
    }
};
