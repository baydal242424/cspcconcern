<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The reporter's PROGRAMME, snapshotted onto the concern beside their college.
 *
 * `department` already records which college a concern came from, which is
 * what sends a Dean referral to the dean of that college. A Program Chair is
 * a tier below that: they chair one programme, so "refer to Program Chair" on
 * a BSIS student's concern should reach the BSIS chair, not whichever of the
 * four Computer Studies chairs happens to sort first.
 *
 * Snapshotted rather than read through the reporter for the same reason
 * department is: a student can change programme, and a concern should keep
 * saying where it came from when it was filed. Reading users.course live
 * would quietly re-route an old concern the day somebody shifts course.
 *
 * Nullable, because plenty of reporters have no programme -- staff filing
 * concerns, and students whose profile predates this column. findHandler()
 * treats a null as "no preference" and falls back to the college.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('concerns', function (Blueprint $table) {
            $table->string('course')->nullable()->after('department');
            $table->index('course');
        });
    }

    public function down(): void
    {
        Schema::table('concerns', function (Blueprint $table) {
            $table->dropIndex(['course']);
            $table->dropColumn('course');
        });
    }
};
