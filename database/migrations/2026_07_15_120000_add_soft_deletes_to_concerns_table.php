<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Concerns are now soft-deleted instead of hard-deleted. A hard delete
     * cascaded into audit_logs and erased the entire audit trail -- including
     * the 'concern_deleted' entry written moments before -- leaving no trace
     * the record ever existed. With soft deletes the row (plus its audit
     * history and evidence metadata) is preserved while the concern
     * disappears from every listing, dashboard, and route.
     */
    public function up(): void
    {
        Schema::table('concerns', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('concerns', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
