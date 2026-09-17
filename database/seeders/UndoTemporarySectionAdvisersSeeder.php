<?php

namespace Database\Seeders;

use App\Models\AuditLog;
use App\Models\Section;
use Illuminate\Database\Seeder;

/**
 * Takes back the advisers TemporarySectionAdvisersSeeder handed out.
 *
 * Reads what that seeder recorded and clears each class's adviser -- but only
 * where the adviser is still the instructor it put there. A class somebody has
 * since given a real adviser from Manage Users keeps that adviser.
 *
 *     php artisan db:seed --class=Database\\Seeders\\UndoTemporarySectionAdvisersSeeder
 */
class UndoTemporarySectionAdvisersSeeder extends Seeder
{
    public function run(): void
    {
        $runs = AuditLog::where('action', TemporarySectionAdvisersSeeder::ACTION)->get();

        if ($runs->isEmpty()) {
            $this->command?->info('No temporary advisers on record. Nothing to undo.');

            return;
        }

        $cleared = 0;
        $kept = 0;

        foreach ($runs as $run) {
            foreach (json_decode($run->changes, true)['assigned'] ?? [] as $entry) {
                $updated = Section::whereKey($entry['section_id'])
                    ->where('adviser_id', $entry['adviser_id'])
                    ->update(['adviser_id' => null]);

                $updated ? $cleared++ : $kept++;
            }

            // Marked done, so a second undo does not go looking again.
            $run->update(['action' => TemporarySectionAdvisersSeeder::ACTION.'_undone']);
        }

        $this->command?->info("Temporary advisers removed from {$cleared} classes."
            .($kept ? " {$kept} left alone: their adviser was changed since." : ''));
    }
}
