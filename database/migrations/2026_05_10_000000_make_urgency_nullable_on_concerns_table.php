<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Urgency is no longer supplied by the student at submission time.
     * It is assigned by staff during triage, so the column must allow NULL
     * to represent the "Pending triage" state.
     */
    public function up(): void
    {
        Schema::table('concerns', function (Blueprint $table) {
            $table->string('urgency')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Backfill so the NOT NULL constraint can be re-applied safely.
        \DB::table('concerns')->whereNull('urgency')->update(['urgency' => 'Medium']);

        Schema::table('concerns', function (Blueprint $table) {
            $table->string('urgency')->nullable(false)->change();
        });
    }
};