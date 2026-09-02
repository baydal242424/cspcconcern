<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Splits the last two compound categories.
 *
 * "Bullying / Harassment" covered a pattern of behaviour aimed at someone by a
 * peer, and conduct that may be a single incident from anyone on campus --
 * different in who does it and in what the Handbook says about it, though both
 * are assessed by the same office.
 *
 * "Facilities / Equipment" covered a building and a thing inside it. A blocked
 * fire exit and a dead lab PC reach the same unit but not the same person in
 * it, and the split lets General Services see which kind of job is waiting
 * without opening each one.
 *
 * Neither split changes routing. Bullying and Harassment both reach the
 * Guidance Office and are still graded Medium; Facilities and Equipment both
 * reach General Services. Existing rows take the half that was named first,
 * which is the one showing in the dropdown when the student chose it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('concerns')->where('category', 'Bullying / Harassment')
            ->update(['category' => 'Bullying']);

        DB::table('concerns')->where('category', 'Facilities / Equipment')
            ->update(['category' => 'Facilities']);
    }

    public function down(): void
    {
        DB::table('concerns')->whereIn('category', ['Bullying', 'Harassment'])
            ->update(['category' => 'Bullying / Harassment']);

        DB::table('concerns')->whereIn('category', ['Facilities', 'Equipment'])
            ->update(['category' => 'Facilities / Equipment']);
    }
};
