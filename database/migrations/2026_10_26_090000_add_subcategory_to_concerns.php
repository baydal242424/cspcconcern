<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the concern is, beneath which office reads it.
 *
 * The category decides routing and nothing finer: "Academic" covers a
 * disputed grade, a timetable clash and an instructor's conduct alike, so a
 * handler could only tell which by reading the description -- workable for
 * one case, useless for counting a term's worth of them.
 *
 * Nullable, because every concern filed before today has none, and because
 * Others has no list to choose from: it is the category for what the list
 * does not cover, and it already asks the student to say what it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('concerns', function (Blueprint $table) {
            $table->string('subcategory')->nullable()->after('category');
        });
    }

    public function down(): void
    {
        Schema::table('concerns', function (Blueprint $table) {
            $table->dropColumn('subcategory');
        });
    }
};
