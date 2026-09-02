<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Splits "Physical / Safety" in two, and gives "Others" somewhere to say what
 * it actually is.
 *
 * The compound label bundled two different reports. An injury has already
 * happened and needs someone now; a hazard has not happened yet and needs
 * fixing before it does. A student with either one had to pick a label naming
 * the other thing first.
 *
 * Routing is unchanged -- both still reach an instructor, and both are still
 * graded High on submission. What changes is what the student has to call it,
 * and what a handler sees in their queue.
 *
 * Existing rows become "Physical", the half that was named first and so the
 * one showing in the dropdown when they chose it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('concerns', function (Blueprint $table) {
            // What "Others" actually was, in the student's own words. Every
            // other category names itself; this is the one that cannot.
            $table->string('other_category', 120)->nullable()->after('category');
        });

        DB::table('concerns')->where('category', 'Physical / Safety')
            ->update(['category' => 'Physical']);
    }

    public function down(): void
    {
        DB::table('concerns')->whereIn('category', ['Physical', 'Safety'])
            ->update(['category' => 'Physical / Safety']);

        Schema::table('concerns', function (Blueprint $table) {
            $table->dropColumn('other_category');
        });
    }
};
