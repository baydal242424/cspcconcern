<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Concern;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * concerns:purge clears test data, so what matters is that it removes
 * everything it says it does and nothing it does not.
 *
 * Deleting the concern row alone leaves the student's uploaded evidence on
 * disk, their notifications in the bell, and the people it named in
 * concern_subjects -- which is how the system ended up holding thirteen
 * evidence files belonging to concerns nobody could name any more.
 */
class PurgeConcernsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, UserSeeder::class]);
        Storage::fake('local');
    }

    private function student(): User
    {
        return User::where('email', 'student@my.cspc.edu.ph')->firstOrFail();
    }

    private function concern(array $overrides = []): Concern
    {
        return Concern::create(array_merge([
            'user_id' => $this->student()->id,
            'category' => 'Academic',
            'department' => 'College of Computer Studies',
            'description' => 'A concern to be purged.',
            'status' => 'submitted',
            'is_anonymous' => false,
        ], $overrides));
    }

    private function purge(array $options = []): string
    {
        Artisan::call('concerns:purge', $options);

        return Artisan::output();
    }

    public function test_it_takes_the_whole_trail_with_the_concern(): void
    {
        $concern = $this->concern();

        Storage::disk('local')->put('attachments/evidence.pdf', 'pretend scan');

        DB::table('attachments')->insert([
            'concern_id' => $concern->id,
            'uploaded_by' => $this->student()->id,
            'original_name' => 'evidence.pdf',
            'stored_path' => 'attachments/evidence.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 12,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $concern->subjects()->attach(User::where('email', 'admin@cspc.edu.ph')->firstOrFail()->id);

        AuditLog::create([
            'user_id' => $this->student()->id,
            'concern_id' => $concern->id,
            'action' => 'status_updated',
            'description' => 'Something happened',
        ]);

        \App\Models\Notification::create([
            'user_id' => $this->student()->id,
            'concern_id' => $concern->id,
            'type' => 'status_update',
            'title' => 'Concern Status Updated',
            'message' => 'It moved.',
        ]);

        $this->purge(['--force' => true]);

        $this->assertSame(0, Concern::withTrashed()->count());
        $this->assertSame(0, DB::table('attachments')->count());
        $this->assertSame(0, DB::table('concern_subjects')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('concern_id', $concern->id)->count());
        $this->assertSame(0, DB::table('notifications')->where('concern_id', $concern->id)->count());

        // The file, not just the row that pointed at it.
        Storage::disk('local')->assertMissing('attachments/evidence.pdf');

        fwrite(STDERR, "  [purge] the concern, its evidence file, audit trail, notices and named people all go\n");
    }

    /** A soft-deleted concern is hidden, not gone, so it must be swept too. */
    public function test_it_reaches_concerns_that_were_only_hidden(): void
    {
        $hidden = $this->concern();
        $hidden->delete();

        $this->assertSame(1, Concern::withTrashed()->count());

        $this->purge(['--force' => true]);

        $this->assertSame(0, Concern::withTrashed()->count());

        fwrite(STDERR, "  [purge] an already-hidden concern is removed for good, not left behind\n");
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $this->concern();

        $output = $this->purge(['--dry-run' => true]);

        $this->assertStringContainsString('Dry run', $output);
        $this->assertSame(1, Concern::count());

        fwrite(STDERR, "  [purge] --dry-run reports and deletes nothing\n");
    }

    /** Narrowing must be honoured, or a purge takes the wrong term with it. */
    public function test_it_can_be_limited_by_date_and_by_number(): void
    {
        $old = $this->concern();
        $old->forceFill(['created_at' => now()->subYear()])->save();

        $recent = $this->concern();

        $this->purge(['--before' => now()->subMonths(6)->format('Y-m-d'), '--force' => true]);

        $this->assertNull(Concern::withTrashed()->find($old->id));
        $this->assertNotNull(Concern::find($recent->id), 'A recent concern must survive --before');

        $keep = $this->concern();
        $this->purge(['--id' => [$recent->id], '--force' => true]);

        $this->assertNull(Concern::withTrashed()->find($recent->id));
        $this->assertNotNull(Concern::find($keep->id), '--id must take only what it names');

        fwrite(STDERR, "  [purge] --before and --id delete only what they select\n");
    }

    /**
     * The files that started this: evidence whose concern was removed some
     * other way, left on disk with nothing pointing at it.
     */
    public function test_it_sweeps_evidence_no_concern_points_at(): void
    {
        Storage::disk('local')->put('attachments/orphan.jpg', 'left behind');

        $kept = $this->concern();
        Storage::disk('local')->put('attachments/in-use.jpg', 'still referenced');

        DB::table('attachments')->insert([
            'concern_id' => $kept->id,
            'uploaded_by' => $this->student()->id,
            'original_name' => 'in-use.jpg',
            'stored_path' => 'attachments/in-use.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 16,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Naming a concern that is not the one holding the file: the sweep
        // must not take a file still in use.
        $this->purge(['--id' => [999999], '--orphan-files' => true, '--force' => true]);

        Storage::disk('local')->assertMissing('attachments/orphan.jpg');
        Storage::disk('local')->assertExists('attachments/in-use.jpg');

        fwrite(STDERR, "  [purge] orphaned evidence is swept, evidence still in use is not\n");
    }

    /** Without the flag it reports the orphans and leaves them alone. */
    public function test_orphans_are_reported_before_they_are_deleted(): void
    {
        Storage::disk('local')->put('attachments/orphan.jpg', 'left behind');

        $output = $this->purge([]);

        $this->assertStringContainsString('belong to no concern', $output);
        Storage::disk('local')->assertExists('attachments/orphan.jpg');

        fwrite(STDERR, "  [purge] orphans are reported first; deleting them takes a second, explicit run\n");
    }
}
