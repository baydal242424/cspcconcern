<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Splits "Mental Health / Personal", the last compound category.
 *
 * The two are not the same thing to the student choosing between them. Someone
 * dealing with a family situation, money trouble or a housing problem is not
 * reporting a mental-health difficulty, and being made to file under a label
 * that names one first is its own small deterrent on the category where
 * deterrence costs the most.
 *
 * Routing is unchanged: both reach the Guidance Office, both are graded
 * Medium, and both stay confidential -- readable by Guidance and, for content
 * only, the Head of School. Existing rows become "Mental Health", the half
 * that was named first.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('concerns')->where('category', 'Mental Health / Personal')
            ->update(['category' => 'Mental Health']);
    }

    public function down(): void
    {
        DB::table('concerns')->whereIn('category', ['Mental Health', 'Personal'])
            ->update(['category' => 'Mental Health / Personal']);
    }
};
