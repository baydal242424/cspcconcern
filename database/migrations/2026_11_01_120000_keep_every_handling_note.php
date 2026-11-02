<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every handling note, kept, instead of only the most recent one.
 *
 * The review and resolution notes lived in one column each, so each save
 * wrote over the last. What a case had been through was therefore
 * unrecoverable: the adviser's findings, the chair's, and the dean's all
 * occupied the same slot, and only whoever saved last could be read.
 * That is the opposite of what the notes are for -- they are the written
 * record of how a concern was handled, and a record that keeps only its
 * final line is not a record.
 *
 * The two columns on `concerns` stay, holding the latest entry. Half a
 * dozen places read them -- the student's view, the dashboard, exports --
 * and a log is the right shape for history, not for "what does this case
 * say right now".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('concern_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('concern_id')->constrained()->cascadeOnDelete();
            // The author. Kept even if the account is later removed, so the
            // record does not lose who wrote it -- nullOnDelete rather than
            // cascade, because deleting a staff account must not delete the
            // history of the cases they handled.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // 'review' (what was found) or 'resolution' (what is being done).
            $table->string('kind', 16);
            $table->text('body');
            $table->timestamps();

            // Reading a case means reading its notes in order.
            $table->index(['concern_id', 'kind', 'id']);
        });

        // What is already on file becomes the first entry of each log.
        // Attributed to whoever handling_notes_by recorded, and dated from
        // the concern's last update -- the best either can be known now,
        // and better than a history that starts blank.
        $concerns = DB::table('concerns')
            ->select('id', 'investigation_notes', 'resolution_notes', 'handling_notes_by', 'updated_at', 'created_at')
            ->where(function ($q) {
                $q->whereNotNull('investigation_notes')->orWhereNotNull('resolution_notes');
            })
            ->orderBy('id')
            ->get();

        foreach ($concerns->chunk(200) as $chunk) {
            $rows = [];

            foreach ($chunk as $concern) {
                $when = $concern->updated_at ?: $concern->created_at;

                foreach (['review' => 'investigation_notes', 'resolution' => 'resolution_notes'] as $kind => $column) {
                    if (blank($concern->$column)) {
                        continue;
                    }

                    $rows[] = [
                        'concern_id' => $concern->id,
                        'user_id' => $concern->handling_notes_by,
                        'kind' => $kind,
                        'body' => $concern->$column,
                        'created_at' => $when,
                        'updated_at' => $when,
                    ];
                }
            }

            if ($rows) {
                DB::table('concern_notes')->insert($rows);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('concern_notes');
    }
};
