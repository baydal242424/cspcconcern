<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One student number per student, one staff number per staff member.
 *
 * Nothing stopped two accounts carrying the same number. An admin holding a
 * class list and searching for "231002370" could land on either of them, and
 * the one thing the college's own records key on stopped identifying anybody.
 *
 * NULL is left free: the column is optional for staff, and every database
 * here allows repeated NULLs in a unique index. It is the blank string that
 * would collide, so any of those are turned into NULL first.
 */
return new class extends Migration
{
    public function up(): void
    {
        // An empty string is not an ID. Left as-is, the second account saved
        // with a blank box would collide with the first.
        DB::table('users')->where('student_id', '')->update(['student_id' => null]);
        DB::table('users')->where('employee_id', '')->update(['employee_id' => null]);

        Schema::table('users', function (Blueprint $table) {
            $table->unique('student_id');
        });

        // employee_id already carries a plain index; the unique one replaces
        // what it did, so the old one is dropped to avoid keeping two.
        Schema::table('users', function (Blueprint $table) {
            $table->unique('employee_id', 'users_employee_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['student_id']);
            $table->dropUnique('users_employee_id_unique');
        });
    }
};
