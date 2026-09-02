<?php

namespace Tests\Feature;

use App\Models\Concern;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Not a correctness test -- a measurement. Counts the database round trips and
 * peak memory of each page a real user actually loads, because those two
 * numbers are what decide how many people the deployed instance can serve at
 * once. Run it, read the printout, delete it if you don't want it in the suite.
 */
class CapacityProbeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, UserSeeder::class]);

        // A realistic amount of data: enough rows that pagination and the
        // dashboard aggregates are doing real work, not hitting empty tables.
        $student = User::where('email', 'student@my.cspc.edu.ph')->firstOrFail();
        $staff = User::where('email', 'staff@cspc.edu.ph')->firstOrFail();

        for ($i = 0; $i < 60; $i++) {
            Concern::create([
                'user_id' => $student->id,
                'category' => ['Academic', 'Safety', 'Others'][$i % 3],
                'department' => 'College of Computer Studies',
                'description' => 'Measured load row number '.$i.' with a description of realistic length.',
                'urgency' => 'Low',
                'status' => ['submitted', 'in_progress', 'resolved'][$i % 3],
                'is_anonymous' => false,
                'assigned_to' => $staff->id,
            ]);
        }
    }

    private function probe(string $label, User $as, string $url): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $before = memory_get_peak_usage(true);
        $start = microtime(true);

        $resp = $this->actingAs($as)->get($url);

        $ms = (microtime(true) - $start) * 1000;
        $queries = count(DB::getQueryLog());
        $peak = (memory_get_peak_usage(true) - $before) / 1048576;
        DB::disableQueryLog();

        fwrite(STDERR, sprintf(
            "  %-34s %s  %3d queries  %6.0f ms  peak +%.1f MB%s",
            $label,
            $resp->getStatusCode(),
            $queries,
            $ms,
            $peak,
            PHP_EOL
        ));
    }

    public function test_measure_pages_a_real_user_loads(): void
    {
        $student = User::where('email', 'student@my.cspc.edu.ph')->firstOrFail();
        $staff = User::where('email', 'staff@cspc.edu.ph')->firstOrFail();
        $admin = User::where('email', 'admin@cspc.edu.ph')->firstOrFail();
        $concern = Concern::first();

        fwrite(STDERR, PHP_EOL.'  --- per-request cost (60 concerns, '.User::count().' users) ---'.PHP_EOL);

        $this->probe('student: concern list', $student, '/concerns');
        $this->probe('student: concern detail', $student, '/concerns/'.$concern->id);
        $this->probe('student: new concern form', $student, '/concerns/create');
        $this->probe('staff: dashboard', $staff, '/dashboard');
        $this->probe('staff: concern list', $staff, '/concerns');
        $this->probe('staff: concern detail', $staff, '/concerns/'.$concern->id);
        $this->probe('admin: user management', $admin, '/admin/users');

        fwrite(STDERR, PHP_EOL);

        $this->assertTrue(true);
    }
}
