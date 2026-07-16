<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stores which ROLE a concern was explicitly referred to by staff
     * (e.g. "Guidance Counselor", "Admin"). Null when not referred.
     * This drives least-privilege visibility for referred concerns.
     */
    public function up(): void
    {
        Schema::table('concerns', function (Blueprint $table) {
            $table->string('referred_to')->nullable()->after('assigned_to');
            $table->index('referred_to');
        });
    }

    public function down(): void
    {
        Schema::table('concerns', function (Blueprint $table) {
            $table->dropIndex(['referred_to']);
            $table->dropColumn('referred_to');
        });
    }
};