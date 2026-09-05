<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets an audit entry describe something other than a concern.
 *
 * audit_logs.concern_id was NOT NULL, which quietly limited the trail to
 * things done TO a concern. Account-level actions had nowhere to go: the
 * start-of-year promotion rewrites the section of every student in one press,
 * and that is exactly the kind of action a trail exists for -- who ran it,
 * when, and what each account was before.
 *
 * Nullable rather than a second table, because "who did what, when" is one
 * question and the answer should be readable in one place, in order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('concern_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Entries that belong to no concern cannot survive the column becoming
        // NOT NULL again, and inventing a concern_id for them would be worse
        // than losing them.
        \Illuminate\Support\Facades\DB::table('audit_logs')->whereNull('concern_id')->delete();

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('concern_id')->nullable(false)->change();
        });
    }
};
