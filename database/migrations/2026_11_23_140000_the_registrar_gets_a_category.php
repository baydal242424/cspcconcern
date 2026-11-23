<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A category for the records office.
 *
 * The Registrar became a role in the migration before this one, and had
 * nothing routed to it: nameable, referrable, and never the first desk a
 * concern reached. A student whose transcript had not been released filed
 * under "Others" and it went to their class adviser.
 *
 * THE SUB-CATEGORIES ARE DOCUMENTS AND RECORDS, deliberately. Academic
 * already carries Enrollment, Requirements and Deadlines -- 27 concerns
 * between them -- and those are arguably this office's work too. Moving
 * them would recategorise filed concerns and leave two forms asking the
 * same question, so they stay where students have been filing them, and
 * this category takes what Academic does not cover at all.
 *
 * The line that separates the two: Academic is about STUDYING -- grades,
 * subjects, teaching, schedules. Registrar is about the RECORD of it --
 * what is written down, and the documents that prove it.
 *
 * A wrong grade is the instructor's, and belongs under Academic. A grade
 * that is right but missing from the record is the Registrar's, and is
 * here.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('concern_categories')->where('name', 'Registrar')->exists()) {
            return;
        }

        $subcategories = [
            'Transcript of Records',
            'Certificate or clearance',
            'Diploma or graduation documents',
            'Error in my student record',
            'Grade missing from my record',
            'Shifting or transferring',
            'Other registrar concern',
        ];

        DB::table('concern_categories')->insert([
            'name' => 'Registrar',
            'label' => 'Records & Registrar',
            'routing_role' => 'Registrar',
            'subcategories' => json_encode(array_map(
                fn (string $name) => ['name' => $name, 'route' => null],
                $subcategories
            )),
            'is_active' => true,
            'is_builtin' => false,
            // Above "Others", which is pinned to the bottom at 9999. The
            // highest NON-Others position plus ten, so this lands after
            // the Library and before the catch-all.
            'sort_order' => (int) DB::table('concern_categories')
                ->where('name', '!=', 'Others')
                ->max('sort_order') + 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Only if nothing has been filed under it. The concerns match the
        // category BY NAME, so deleting one with history behind it leaves
        // those rows pointing at nothing.
        if (DB::table('concerns')->where('category', 'Registrar')->doesntExist()) {
            DB::table('concern_categories')->where('name', 'Registrar')->delete();
        }
    }
};
