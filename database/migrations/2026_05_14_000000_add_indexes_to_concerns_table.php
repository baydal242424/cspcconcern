<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add indexes to the columns the visibility and dashboard queries filter
     * by most often. This is a scalability improvement: with a large number of
     * concerns, these indexes let the database jump straight to matching rows
     * instead of scanning the whole table. No data or logic changes.
     */
    public function up(): void
    {
        Schema::table('concerns', function (Blueprint $table) {
            $table->index('status');
            $table->index('category');
            $table->index('assigned_to');
            // Composite index for the common "my open work" style queries.
            $table->index(['assigned_to', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('concerns', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['category']);
            $table->dropIndex(['assigned_to']);
            $table->dropIndex(['assigned_to', 'status']);
        });
    }
};