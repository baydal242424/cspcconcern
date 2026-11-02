<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who last wrote the review and resolution notes.
 *
 * The form used to open with whatever was already saved sitting in both
 * boxes. For the person who wrote it that is right -- it is how a typo
 * gets fixed. For the next desk it is wrong: a referral hands the case
 * over, and the new handler found the previous handler's words typed
 * into their own box, above a Save button, ready to be submitted back as
 * if they were theirs. The notes are already shown in full further up
 * the page, so the copy was never needed to read them.
 *
 * One column for both boxes, because both are required on every save and
 * therefore always written by the same person at the same moment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('concerns', function (Blueprint $table) {
            $table->unsignedBigInteger('handling_notes_by')->nullable()->after('resolution_notes');
        });

        // Existing cases: take the author from the audit trail, which has
        // recorded every note written since the system went up. Without
        // this every concern already on file would open blank for the
        // person who wrote its notes, and they would have to retype them
        // to correct a word.
        $authors = DB::table('audit_logs')
            ->select('concern_id', DB::raw('MAX(id) as last_id'))
            ->whereIn('action', ['notes_updated', 'investigation_updated', 'status_updated'])
            ->whereNotNull('concern_id')
            ->groupBy('concern_id')
            ->pluck('last_id', 'concern_id');

        foreach ($authors->chunk(200) as $chunk) {
            $rows = DB::table('audit_logs')
                ->whereIn('id', $chunk->values())
                ->pluck('user_id', 'id');

            foreach ($chunk as $concernId => $logId) {
                if (! empty($rows[$logId])) {
                    DB::table('concerns')
                        ->where('id', $concernId)
                        ->update(['handling_notes_by' => $rows[$logId]]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::table('concerns', function (Blueprint $table) {
            $table->dropColumn('handling_notes_by');
        });
    }
};
