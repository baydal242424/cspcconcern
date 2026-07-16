<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds support for:
     *  - Conflict-of-interest routing: about_staff_id records the person a
     *    concern is *about*, so the router can steer it away from them.
     *  - Break-glass identity reveal: records when/who revealed a pseudonymous
     *    reporter's identity, and why, for full accountability.
     */
    public function up(): void
    {
        Schema::table('concerns', function (Blueprint $table) {
            // The staff/person this concern is ABOUT (nullable; only when the
            // student flags a conflict of interest). Used to exclude them from
            // routing so they never handle a case against themselves.
            $table->foreignId('about_staff_id')->nullable()->after('assigned_to')
                  ->constrained('users')->nullOnDelete();

            // Break-glass identity reveal audit fields.
            $table->timestamp('identity_revealed_at')->nullable()->after('referred_to');
            $table->foreignId('identity_revealed_by')->nullable()->after('identity_revealed_at')
                  ->constrained('users')->nullOnDelete();
            $table->text('identity_reveal_reason')->nullable()->after('identity_revealed_by');
        });
    }

    public function down(): void
    {
        Schema::table('concerns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('about_staff_id');
            $table->dropConstrainedForeignId('identity_revealed_by');
            $table->dropColumn(['identity_revealed_at', 'identity_reveal_reason']);
        });
    }
};