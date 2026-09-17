<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An identity number for staff, the way students already have one.
 *
 * users.student_id held the only number on the table, and it means exactly
 * what it says. Staff were left with a name and an email -- which is not what
 * CSPC's own records key on, and not what an admin has in front of them when
 * somebody asks for an account to be corrected. Two people share a name in
 * this database already.
 *
 * A separate column rather than reusing student_id. The two are different
 * things issued by different offices, and a shared column would put a staff
 * member in the results when an admin searches for a student's number -- the
 * exact confusion the number exists to prevent.
 *
 * A plain indexed string, and deliberately NOT unique: the numbers are typed
 * in by the people themselves, so a typo would otherwise block an unrelated
 * account from being saved, and the fix belongs with an admin rather than in
 * a constraint violation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('employee_id', 50)->nullable()->after('student_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('employee_id');
        });
    }
};
