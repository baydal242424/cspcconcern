<?php

namespace App\Console\Commands;

use App\Http\Controllers\ConcernController;
use App\Models\Concern;
use App\Models\ConcernCategory;
use App\Models\Section;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Console\Command;
use ReflectionMethod;

/**
 * Who would receive a concern, category by category.
 *
 * Run it on a developer's machine and on the deployed site and read the
 * two side by side: the same list means the demonstration will behave the
 * same in front of the panel as it did in rehearsal, and a line that
 * differs is the one thing to fix.
 *
 * It routes NOTHING and writes NOTHING. The concern it builds is never
 * saved; it exists only to carry the course, section and college that
 * routing reads.
 *
 * findHandler() is private, and reached here by reflection. That is
 * deliberate: this has to report what the application would REALLY do,
 * and a second copy of the rules written for a diagnostic is a second
 * thing to keep in step -- which, the first time it drifts, makes this
 * command lie at exactly the moment it is believed.
 */
class DemoCheck extends Command
{
    protected $signature = 'demo:check
        {--email= : File as this student instead of the first one marked}';

    protected $description = 'Show which account would receive a concern in each category';

    public function handle(): int
    {
        $student = $this->student();

        if (! $student) {
            $this->error('No student to file as. Run demo:participants first.');

            return self::FAILURE;
        }

        $this->line('Filing as   : <info>'.$student->name.'</info> <'.$student->email.'>');
        $this->line('Programme   : '.($student->course ?? '-').' '.($student->section ?? ''));
        $this->line('College     : '.($student->department ?? '-'));
        $this->newLine();

        $this->line('Sign-in dropdown : '.$this->onOff(Setting::flag(Setting::DEMO_LOGIN, (bool) config('auth.demo_login'))));
        $this->line('Demo desks       : '.$this->onOff(ConcernController::demoDesksPreferred()));
        $this->newLine();

        $find = new ReflectionMethod(ConcernController::class, 'findHandler');
        $find->setAccessible(true);
        $controller = app(ConcernController::class);

        $rows = [];
        $missing = 0;
        $real = 0;

        foreach (ConcernCategory::orderBy('sort_order')->orderBy('id')->get() as $category) {
            $role = $category->routing_role ?: 'Adviser';

            $concern = new Concern([
                'course' => $student->course,
                'section' => $student->section,
                'department' => $student->department,
                'category' => $category->name,
            ]);
            $concern->user_id = $student->id;

            // Academic and Others reach the adviser OF THE SECTION, which is
            // a row in `sections` rather than anybody holding the role.
            $handler = $role === 'Adviser'
                ? Section::adviserFor($student->course, $student->section)
                : $find->invoke($controller, $role, $concern);

            if (! $handler) {
                $missing++;
                $who = '<error>NOBODY</error>';
            } else {
                // Academic and Others go to the class adviser by design: the
                // real adviser of the section, not a demo desk.
                $isDemo = str_starts_with($handler->email, 'demo.') || $role === 'Adviser';

                if (! $isDemo) {
                    $real++;
                }

                $who = ($isDemo ? '<info>' : '<comment>').$handler->name.($isDemo ? '</info>' : '</comment>')
                    .' <'.$handler->email.'>';
            }

            $rows[] = [$category->label ?: $category->name, $role, $who];
        }

        foreach ($rows as [$label, $role, $who]) {
            $this->line(sprintf('  %-24s %-24s %s', $label, $role, $who));
        }

        $this->newLine();
        $this->line('<info>green</info> = a demo desk you can sign in to   '
            .'<comment>amber</comment> = a real person');

        if ($missing > 0) {
            $this->newLine();
            $this->error($missing.' categor'.($missing === 1 ? 'y has' : 'ies have').' nobody to receive them.');
        }

        if ($real > 0) {
            $this->newLine();
            $this->warn($real.' would reach a real person rather than a demo desk.');
            $this->line('Usually that means the demo account for that office does not exist here:');
            $this->line("  php artisan db:seed --class='Database\\Seeders\\DemoRoleSeeder' --force");
            $this->line('  php artisan demo:participants');
        }

        if ($missing === 0 && $real === 0) {
            $this->newLine();
            $this->info('Every category reaches a demo desk. Complete.');
        }

        return self::SUCCESS;
    }

    private function student(): ?User
    {
        if ($email = $this->option('email')) {
            return User::where('email', $email)->first();
        }

        return User::whereHas('role', fn ($q) => $q->where('name', 'Student'))
            ->where('demo_signin', true)
            ->whereNotNull('course')
            ->whereNotNull('section')
            ->orderBy('id')
            ->first()
            ?? User::whereHas('role', fn ($q) => $q->where('name', 'Student'))
                ->whereNotNull('course')
                ->whereNotNull('section')
                ->orderBy('id')
                ->first();
    }

    private function onOff(bool $on): string
    {
        return $on ? '<info>ON</info>' : '<comment>OFF</comment>';
    }
}
