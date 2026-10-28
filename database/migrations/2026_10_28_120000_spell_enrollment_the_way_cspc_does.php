<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Enrolment" -> "Enrollment".
 *
 * The British spelling. CSPC writes it with two Ls, as does every form a
 * student has filled in to get here.
 *
 * The rename has to reach the rows as well as the list. A sub-category is
 * stored as the word itself, so concerns filed before this would keep
 * showing an option that no longer exists -- displayed fine, but refused
 * by subcategoryBelongsTo() the moment anything re-checked them, and odd
 * to read beside the new spelling in the same list of concerns.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('concerns')->where('subcategory', 'Enrolment')
            ->update(['subcategory' => 'Enrollment']);

        // Drafts too: they hold the same value and are reopened into the
        // same dropdown, which would not find the old spelling in it.
        if (Schema::hasTable('concern_drafts')) {
            DB::table('concern_drafts')->where('subcategory', 'Enrolment')
                ->update(['subcategory' => 'Enrollment']);
        }
    }

    public function down(): void
    {
        DB::table('concerns')->where('subcategory', 'Enrollment')
            ->update(['subcategory' => 'Enrolment']);

        if (Schema::hasTable('concern_drafts')) {
            DB::table('concern_drafts')->where('subcategory', 'Enrollment')
                ->update(['subcategory' => 'Enrolment']);
        }
    }
};
