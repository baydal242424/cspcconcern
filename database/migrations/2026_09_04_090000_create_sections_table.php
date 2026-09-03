<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Class sections, and who advises each one.
 *
 * Section was deliberately left off users when this system was built, and the
 * reasoning was sound: the profile form runs once, section changes every year,
 * and nothing routed on it, so the stored value would have been wrong for most
 * of a student's time here.
 *
 * What changed is the last part. Academic concerns now go to a student's class
 * adviser, and an adviser is attached to a SECTION -- "BSIT 3A", not to a
 * college. Without recording which section a student is in, every academic
 * concern in Computer Studies reaches whichever adviser sorts first instead of
 * the one who actually knows them.
 *
 * Two things make the staleness survivable rather than fatal:
 *
 *  - The mapping is versioned by school year and semester, so last term's
 *    record is history rather than something to overwrite. The published lists
 *    differ between semesters -- BSIT 1D was advised by one person in the
 *    first and another in the second.
 *
 *  - A stale or missing section is not an error. Routing falls back to any
 *    adviser in the college, then to an instructor, then up the escalation
 *    chain, so a student who has not updated their section still reaches
 *    somebody who can help.
 *
 * An adviser holds several sections -- the CCS lists show one person advising
 * BSIT 4B and 4C, another BSIT 1A and BSIS 1B -- which is why this is a table
 * and not a column on the user.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sections', function (Blueprint $table) {
            $table->id();

            // The programme as students record it, so this joins to
            // users.course without translation. "BS Information Technology",
            // not "BSIT".
            $table->string('course');

            // Year and letter as the college writes them: 1A, 3C, 4B.
            $table->string('section', 12);

            $table->string('school_year', 12);
            $table->string('semester', 12);

            // Nullable, and null on delete: an adviser leaving should not take
            // the record of the section with them. Routing treats a section
            // with no adviser the same as no section at all and falls back.
            $table->foreignId('adviser_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // One adviser per section per term. Re-running a seeder updates
            // rather than duplicating.
            $table->unique(['course', 'section', 'school_year', 'semester']);
            $table->index(['course', 'section']);
        });

        Schema::table('users', function (Blueprint $table) {
            // A student's own section. Staff leave it null.
            $table->string('section', 12)->nullable()->after('course');
        });

        Schema::table('concerns', function (Blueprint $table) {
            // Snapshotted at submission, like course and department already
            // are. A concern is a record of what was reported and by whom at
            // the time -- if the student moves up a year afterwards, the
            // concern should still route to the adviser they had when they
            // filed it.
            $table->string('section', 12)->nullable()->after('course');
        });
    }

    public function down(): void
    {
        Schema::table('concerns', function (Blueprint $table) {
            $table->dropColumn('section');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('section');
        });

        Schema::dropIfExists('sections');
    }
};
