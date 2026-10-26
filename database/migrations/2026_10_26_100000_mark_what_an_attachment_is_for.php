<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tell a student's evidence apart from a handler's proof of resolution.
 *
 * Both are files hanging off the same concern, and until now both were the
 * same row. They answer opposite questions -- "here is what happened" and
 * "here is what was done about it" -- and a student opening a resolved
 * concern should be able to see the second without hunting through the
 * first.
 *
 * Everything already on file is a student's evidence, which is what the
 * default records.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->string('kind', 20)->default('evidence')->after('concern_id');
        });
    }

    public function down(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
