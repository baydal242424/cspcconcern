<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('concerns', function (Blueprint $table) {
            // Separate from resolution_notes: what staff found while looking into
            // the concern, written while it is still in_progress -- distinct from
            // the final outcome recorded in resolution_notes once it's resolved.
            $table->text('investigation_notes')->nullable()->after('description');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('concerns', function (Blueprint $table) {
            $table->dropColumn('investigation_notes');
        });
    }
};
