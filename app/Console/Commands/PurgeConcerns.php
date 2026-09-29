<?php

namespace App\Console\Commands;

use App\Models\Concern;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Delete concerns and everything hanging off them.
 *
 * Testing leaves real rows behind. They are not only clutter: a leftover
 * concern is visible to whichever office handles its category, so a dean
 * signing in for the first time meets a queue of somebody's "fffffff" before
 * a single student has filed anything.
 *
 * Deleting the concern alone is not enough. Its audit log, the notifications
 * it produced, the people it named and any uploaded evidence all outlive it,
 * and the evidence outlives the database row entirely -- the file sits on disk
 * until something removes it. This takes the lot.
 *
 *   php artisan concerns:purge --dry-run     show what would go, change nothing
 *   php artisan concerns:purge               ask first, then delete
 *   php artisan concerns:purge --force       no prompt (for a deploy terminal)
 *   php artisan concerns:purge --before=2026-09-25
 *
 * There is no undo. --dry-run first is the habit worth having, especially
 * against the live database, where a real student's concern is one row away
 * from a test one.
 */
class PurgeConcerns extends Command
{
    protected $signature = 'concerns:purge
        {--before= : Only those filed before this date (YYYY-MM-DD)}
        {--id=* : Only these concern numbers}
        {--orphan-files : Also delete evidence files that no concern points at any more}
        {--dry-run : List what would be deleted, then stop}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Delete concerns permanently, with their evidence, audit trail and notifications';

    public function handle(): int
    {
        // withTrashed: a soft-deleted concern still holds its files and its
        // audit trail, so "already deleted" is not the same as gone.
        $query = Concern::withTrashed()->with('user');

        if ($before = $this->option('before')) {
            try {
                $query->where('created_at', '<', \Illuminate\Support\Carbon::parse($before)->startOfDay());
            } catch (\Exception $e) {
                $this->error("Not a date: {$before}. Use YYYY-MM-DD.");

                return self::FAILURE;
            }
        }

        if ($ids = $this->option('id')) {
            $query->whereIn('id', $ids);
        }

        $concerns = $query->orderBy('id')->get();

        if ($concerns->isEmpty()) {
            $this->info('Nothing matches. No concerns were deleted.');

            // Files can still be left over from concerns removed some other
            // way -- an account deletion cascades the rows and leaves these.
            if (! $this->option('dry-run')) {
                $this->sweepOrphanFiles(Storage::disk('local'));
            }

            return self::SUCCESS;
        }

        $this->table(['#', 'Category', 'Status', 'Filed by', 'When'], $concerns->map(fn (Concern $c) => [
            $c->id.($c->deleted_at ? ' (already hidden)' : ''),
            $c->category,
            $c->status,
            optional($c->user)->name ?? 'deleted account',
            $c->created_at->format('M d, Y'),
        ])->all());

        $ids = $concerns->pluck('id');
        $counts = $this->attachedCounts($ids);
        $files = DB::table('attachments')->whereIn('concern_id', $ids)->pluck('stored_path');

        $this->newLine();
        $this->line('Going with them:');

        foreach ($counts as $table => $count) {
            $this->line("  {$count} × {$table}");
        }

        $this->line('  '.$files->count().' × uploaded file, deleted from storage');

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->info('Dry run. Nothing was deleted.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->warn('This cannot be undone.');

        if (! $this->option('force') && ! $this->confirm("Permanently delete {$concerns->count()} concern(s)?")) {
            $this->info('Left alone.');

            return self::SUCCESS;
        }

        // The evidence first, and outside the transaction: a rolled-back
        // delete can restore a row, never a file. Better to leave a database
        // row pointing at a missing file, which the download route already
        // handles, than a file nobody can reach or account for.
        $disk = Storage::disk('local');
        $filesDeleted = 0;

        foreach ($files as $path) {
            if ($path && $disk->exists($path)) {
                $disk->delete($path);
                $filesDeleted++;
            }
        }

        DB::transaction(function () use ($ids) {
            foreach (['attachments', 'audit_logs', 'notifications', 'concern_subjects', 'feedbacks', 'referrals'] as $table) {
                if (\Illuminate\Support\Facades\Schema::hasTable($table)) {
                    DB::table($table)->whereIn('concern_id', $ids)->delete();
                }
            }

            // forceDelete, not delete: these are already being removed on
            // purpose, and a soft delete would leave exactly what this command
            // exists to clear.
            Concern::withTrashed()->whereIn('id', $ids)->forceDelete();
        });

        $this->newLine();
        $this->info("Deleted {$concerns->count()} concern(s) and {$filesDeleted} file(s).");
        $this->line('Remaining concerns: '.Concern::withTrashed()->count());

        $this->sweepOrphanFiles($disk);

        return self::SUCCESS;
    }

    /**
     * Evidence files no row points at any more.
     *
     * Deleting an ACCOUNT cascades that person's concerns out of the database,
     * and nothing has ever removed the files they uploaded -- so a student's
     * evidence outlives the concern, the account and the audit trail, sitting
     * on disk with nothing left to say whose it was. The policy tells students
     * deleting an account takes their concerns with it, so this is the half of
     * that promise the code was not keeping.
     */
    private function sweepOrphanFiles($disk): void
    {
        $referenced = DB::table('attachments')->pluck('stored_path')->filter()->all();
        $orphans = collect($disk->files('attachments'))
            ->reject(fn (string $path) => in_array($path, $referenced, true));

        if ($orphans->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->warn($orphans->count().' evidence file(s) on disk belong to no concern.');

        if (! $this->option('orphan-files')) {
            $this->line('Run again with --orphan-files to delete them.');

            return;
        }

        foreach ($orphans as $path) {
            $disk->delete($path);
        }

        $this->info('Deleted '.$orphans->count().' orphaned file(s).');
    }

    /**
     * @return array<string, int>
     */
    private function attachedCounts(\Illuminate\Support\Collection $ids): array
    {
        $counts = [];

        foreach (['attachments', 'audit_logs', 'notifications', 'concern_subjects', 'feedbacks', 'referrals'] as $table) {
            if (\Illuminate\Support\Facades\Schema::hasTable($table)) {
                $counts[$table] = DB::table($table)->whereIn('concern_id', $ids)->count();
            }
        }

        return $counts;
    }
}
