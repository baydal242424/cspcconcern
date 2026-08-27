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
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_seen_at')->nullable()->after('approved_at');
            $table->timestamp('banned_at')->nullable()->after('last_seen_at');
            $table->foreignId('banned_by')->nullable()->after('banned_at')->constrained('users')->nullOnDelete();
            $table->string('ban_reason')->nullable()->after('banned_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['banned_by']);
            $table->dropColumn(['last_seen_at', 'banned_at', 'banned_by', 'ban_reason']);
        });
    }
};
