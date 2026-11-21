<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A notification remembers the exact thing it is about.
 *
 * Until now a notice could say "there is a new note on your concern" and
 * then drop the reader at the top of a case with eighteen timeline entries
 * and a dozen notes, leaving them to find it. On a case of any size that is
 * the same as not saying which.
 *
 * The anchor is a fragment -- "note-412" -- appended to the concern's
 * address when the notice is opened. Nullable, because most notices are
 * about the case as a whole and have nothing finer to point at.
 *
 * A string rather than a foreign key to concern_notes. What a notice points
 * at is not always a note: an attachment, a timeline entry and a summons
 * are all things a future notice might want to open the page at, and a
 * column named after one of them would be wrong for the rest. Nothing joins
 * on it; it is only ever appended to a URL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->string('anchor')->nullable()->after('concern_id');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropColumn('anchor');
        });
    }
};
