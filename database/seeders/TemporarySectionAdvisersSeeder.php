<?php

namespace Database\Seeders;

use App\Models\AuditLog;
use App\Models\Section;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * TEMPORARY: give every class A-D that has no adviser an instructor from the
 * same college.
 *
 * Most programmes published an adviser for class A only, so a student in 2B
 * reaches no adviser at all. For testing that path end to end, this fills the
 * gaps -- but the names are NOT real assignments. The instructor picked has not
 * been told they advise the class, and on a live site that class's concerns
 * would go to somebody who does not know them. On production it refuses to
 * run unless ALLOW_TEMPORARY_ADVISERS=true is set; replace the names with the
 * colleges' real lists as they arrive.
 *
 * Only sections with no adviser are touched. Instructors are handed classes in
 * turn, whoever advises the fewest first, so no one person collects a college's
 * worth of classes.
 *
 * Every assignment is recorded in audit_logs, which is what makes it undoable:
 *
 *     php artisan db:seed --class=Database\\Seeders\\UndoTemporarySectionAdvisersSeeder
 *
 * Not called from DatabaseSeeder. Run it deliberately:
 *
 *     php artisan db:seed --class=Database\\Seeders\\TemporarySectionAdvisersSeeder
 */
class TemporarySectionAdvisersSeeder extends Seeder
{
    public const ACTION = 'temporary_advisers_filled';

    private const LETTERS = ['A', 'B', 'C', 'D'];

    public function run(): void
    {
        // On the live site only when deliberately switched on: these names
        // route real students' concerns to instructors who do not know.
        if (app()->isProduction() && ! config('app.allow_temporary_advisers')) {
            $this->command?->error('This assigns made-up advisers, so it is refused on production. '
                .'Set ALLOW_TEMPORARY_ADVISERS=true in the environment, redeploy, and run it with --force.');

            return;
        }

        $actor = User::whereHas('role', fn ($q) => $q->where('name', 'System Admin'))->orderBy('id')->first();

        if (! $actor) {
            $this->command?->error('No System Admin account to record the change under, so it could not be undone. Nothing done.');

            return;
        }

        $term = Section::currentTerm();
        $assigned = [];

        foreach (User::COURSES_BY_COLLEGE as $college => $courses) {
            $instructors = User::whereHas('role', fn ($q) => $q->where('name', 'Instructor'))
                ->where('department', $college)
                ->where('status', 'approved')
                ->orderBy('id')
                ->get(['id', 'name']);

            if ($instructors->isEmpty()) {
                $this->command?->warn("{$college}: no instructors on record, skipped.");

                continue;
            }

            // How many classes each already advises this term, so the new ones
            // go to whoever has the fewest.
            $load = $instructors->mapWithKeys(fn (User $u) => [
                $u->id => Section::where('adviser_id', $u->id)
                    ->where('school_year', $term['school_year'])
                    ->where('semester', $term['semester'])
                    ->count(),
            ])->all();

            $filled = 0;

            foreach ($courses as $course) {
                foreach (range(1, User::finalYearFor($course)) as $year) {
                    foreach (self::LETTERS as $letter) {
                        $section = $year.$letter;

                        if (Section::adviserFor($course, $section)) {
                            continue;
                        }

                        asort($load);
                        $instructorId = array_key_first($load);
                        $load[$instructorId]++;

                        $row = Section::updateOrCreate(
                            [
                                'course' => $course,
                                'section' => $section,
                                'school_year' => $term['school_year'],
                                'semester' => $term['semester'],
                            ],
                            ['adviser_id' => $instructorId]
                        );

                        $assigned[] = ['section_id' => $row->id, 'adviser_id' => $instructorId];
                        $filled++;
                    }
                }
            }

            $this->command?->line("  {$college}: {$filled} classes given an instructor (most any one holds now: ".max($load).')');
        }

        if (! $assigned) {
            $this->command?->info('Every class A-D already has an adviser. Nothing to do.');

            return;
        }

        AuditLog::create([
            'user_id' => $actor->id,
            'action' => self::ACTION,
            'changes' => json_encode(['assigned' => $assigned]),
            'description' => count($assigned).' classes given a TEMPORARY instructor adviser by TemporarySectionAdvisersSeeder',
            'ip_address' => '127.0.0.1',
        ]);

        $this->command?->info(count($assigned).' classes filled. Undo with UndoTemporarySectionAdvisersSeeder.');
    }
}
