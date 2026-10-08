<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A settled concern can be reported again, as a new one that remembers it.
 *
 * Harassment is the case that makes this necessary. A student reports
 * somebody, it is handled, the case is resolved -- and months later the same
 * person does it again. Filed as an unconnected new report, the one fact that
 * matters most is missing: that this already happened once and was upheld.
 *
 * The alternative was reopening the original. That was rejected: it would
 * move the resolution date, overwrite the outcome and make "resolved" mean
 * nothing, and the first finding is exactly the record a second incident
 * must not be allowed to erase.
 *
 * Nullable, and nullOnDelete rather than cascade: erasing an old concern must
 * not take a later one with it. A follow-up that loses its parent is a
 * concern with a gap in its history, which is recoverable; a cascade would
 * delete a live case nobody asked to remove.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('concerns', function (Blueprint $table) {
            $table->foreignId('follows_up_on_id')
                ->nullable()
                ->after('user_id')
                ->constrained('concerns')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('concerns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('follows_up_on_id');
        });
    }
};
