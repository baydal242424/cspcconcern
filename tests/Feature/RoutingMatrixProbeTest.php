<?php

namespace Tests\Feature;

use App\Models\Concern;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DemoAccountSeeder;
use Database\Seeders\Faculty\CasFacultySeeder;
use Database\Seeders\Faculty\CcsFacultySeeder;
use Database\Seeders\Faculty\CcsSectionAdviserSeeder;
use Database\Seeders\Faculty\CcsSupportStaffSeeder;
use Database\Seeders\Faculty\CeaFacultySeeder;
use Database\Seeders\Faculty\CeaFullFacultySeeder;
use Database\Seeders\Faculty\ChsFacultySeeder;
use Database\Seeders\Faculty\CthbmFacultySeeder;
use Database\Seeders\Faculty\CtdeFacultySeeder;
use Database\Seeders\Faculty\PlaceholderFacultySeeder;
use Database\Seeders\Faculty\ProgrammeSectionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Files one concern in every category, as a student from every college, and
 * reports where each landed.
 *
 * Not a unit test of one rule -- a sweep of the whole routing table against
 * the real seeded roster, which is where a gap actually shows up. Reading the
 * category map tells you what it intends; this tells you what happens.
 *
 * It asserts only the two things that are always true regardless of staffing:
 * a concern must reach somebody, and it must never come back to the person who
 * filed it. Everything else is printed for a human to read.
 */
class RoutingMatrixProbeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RoleSeeder::class,
            UserSeeder::class,
            CcsFacultySeeder::class,
            CeaFacultySeeder::class,
            CeaFullFacultySeeder::class,
            CthbmFacultySeeder::class,
            ChsFacultySeeder::class,
            CtdeFacultySeeder::class,
            CasFacultySeeder::class,
            CcsSupportStaffSeeder::class,
            PlaceholderFacultySeeder::class,
            CcsSectionAdviserSeeder::class,
            DemoAccountSeeder::class,
            // Last: it needs the faculty to pick advisers from and the demo
            // students to place in a section.
            ProgrammeSectionSeeder::class,
        ]);
    }

    public function test_every_category_from_every_college_reaches_somebody(): void
    {
        $failures = [];

        fwrite(STDERR, "\n");

        foreach (array_keys(User::COURSES_BY_COLLEGE) as $college) {
            $student = User::whereHas('role', fn ($q) => $q->where('name', 'Student'))
                ->where('department', $college)
                ->whereNotNull('course')
                ->orderByDesc('section')
                ->first();

            if (! $student) {
                $failures[] = "{$college}: no demo student to file as";
                continue;
            }

            fwrite(STDERR, '  '.$college.'  ('.$student->course.($student->section ? ', section '.$student->section : '').")\n");

            foreach (Concern::CATEGORIES as $category) {
                $payload = [
                    'category' => $category,
                    'description' => "Routing probe for {$category} filed from {$college}.",
                    'is_anonymous' => 0,
                ];

                if ($category === 'Others') {
                    $payload['other_category'] = 'Routing probe';
                }

                $this->actingAs($student)->post('/concerns', $payload);

                $concern = Concern::where('user_id', $student->id)->latest('id')->first();
                $handler = $concern?->assignedUser;

                if (! $concern) {
                    $failures[] = "{$college} / {$category}: the concern was not created";
                    continue;
                }

                if (! $handler) {
                    $failures[] = "{$college} / {$category}: nobody was assigned";
                    fwrite(STDERR, sprintf("      %-24s UNASSIGNED\n", $category));
                    continue;
                }

                if ($handler->id === $student->id) {
                    $failures[] = "{$college} / {$category}: routed back to the reporter";
                }

                fwrite(STDERR, sprintf(
                    "      %-24s %-34s %s\n",
                    $category,
                    optional($handler->role)->name,
                    $handler->name
                ));
            }

            fwrite(STDERR, "\n");
        }

        $this->assertSame([], $failures, "Routing gaps:\n  ".implode("\n  ", $failures));
    }
}
