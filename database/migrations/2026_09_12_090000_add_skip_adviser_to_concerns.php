<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The student's own way past their class adviser.
 *
 * Academic, Physical, Safety and Others reach the adviser first, which is
 * right until the adviser is the reason the student is filing. Naming them as
 * the subject already steps past them -- but a student who simply does not
 * want their adviser reading this had no way to say so without accusing them
 * of something.
 *
 * Recorded on the concern rather than inferred at routing time: the handler
 * who receives it needs to know why it skipped a tier, and a concern is a
 * record of what was asked for at the time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('concerns', function (Blueprint $table) {
            $table->boolean('skip_adviser')->default(false)->after('section');
        });
    }

    public function down(): void
    {
        Schema::table('concerns', function (Blueprint $table) {
            $table->dropColumn('skip_adviser');
        });
    }
};
