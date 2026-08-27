<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backs the 'closed_no_action' outcome: a concern that was assessed and found
 * not to be a valid complaint.
 *
 * The CSPC Student Handbook (Ch. 9 §A.2) makes this a real step -- SASO
 * assesses "whether there is a valid complaint" and forwards it to the
 * College Disciplining Committee only if it is. Until now the system had no
 * way to record that outcome: statuses were submitted / in_progress /
 * resolved / referred, so staff closing an unfounded report had to mark it
 * 'resolved'. That told the student their concern was resolved when it was
 * actually dismissed, and inflated the dashboard's resolution figures with
 * reports nobody ever acted on.
 *
 * closure_reason is required by the controller, not by the column: it is the
 * documentation the handbook expects for a case that ends without action, and
 * it is what the student is shown.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('concerns', function (Blueprint $table) {
            $table->timestamp('closed_at')->nullable()->after('resolved_at');
            $table->text('closure_reason')->nullable()->after('closed_at');
        });
    }

    public function down(): void
    {
        Schema::table('concerns', function (Blueprint $table) {
            $table->dropColumn(['closed_at', 'closure_reason']);
        });
    }
};
