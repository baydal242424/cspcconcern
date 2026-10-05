<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\Section;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * A demo class adviser for each college, advising a section that has no real
 * adviser -- and a demo student in each of those sections.
 *
 * DemoAccountSeeder's students all sit in "A" sections advised by real people,
 * so filing as one hands the concern to a real instructor nobody at a demo can
 * sign in as. These advisers can be signed into from the demo dropdown, so the
 * whole path is visible: the student sees their adviser named on the form, and
 * the adviser sees the concern arrive.
 *
 * READ THIS BEFORE RUNNING IT ANYWHERE REAL. Staff demo accounts were removed
 * once (see DemoAccountSeeder) because routing cannot tell a demo account from
 * a person, and one took a real student's concern. These are kept as narrow as
 * a demo adviser can be:
 *
 *   - They advise only sections with NO real adviser in any term, so no real
 *     adviser is ever displaced. The cost: a real student who enters one of
 *     these sections (BS Nursing 1B, say) reaches the demo adviser too.
 *   - A real adviser recorded for a newer term takes the section back on its
 *     own, because Section::adviserFor() reads the newest term.
 *   - Their addresses are on placeholder.cspc.edu.ph, so a notification sent
 *     to one cannot land in a real mailbox.
 *
 * Remove them with the rest of the demo accounts when the demonstration is
 * over. The section rows stay, with the adviser nulled, which routing reads
 * as "no adviser" again:
 *
 *     php artisan tinker --execute="App\Models\User::where('email','like','demo.%')->delete();"
 *
 * Not called from DatabaseSeeder. Run it deliberately:
 *
 *     php artisan db:seed --class=Database\\Seeders\\DemoAdviserSeeder
 */
class DemoAdviserSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('Creating demo ADVISERS on a PRODUCTION system. Real students in these sections will reach them. Remove them when you are done.');
        }

        $studentRole = Role::where('name', 'Student')->first();
        $instructorRole = Role::where('name', 'Instructor')->first();

        if (! $studentRole || ! $instructorRole) {
            $this->command?->warn("The 'Student' or 'Instructor' role does not exist; nothing seeded.");

            return;
        }

        // The newest term on record, so the demo adviser is what adviserFor()
        // returns rather than something shadowed by a later term.
        $term = Section::orderByDesc('school_year')->orderByDesc('semester')->first();

        if (! $term) {
            $this->command?->warn('No sections exist yet, so there is no term to add advisers to. Run ProgrammeSectionSeeder first.');

            return;
        }

        $sections = 0;

        foreach (User::COURSES_BY_COLLEGE as $college => $courses) {
            // Advising is not a role -- the people doing it are Instructors.
            // No course and no section on the account itself: a course would
            // make findHandler() prefer them for that programme's concerns,
            // and the sections they advise live in the sections table.
            $adviser = User::updateOrCreate(
                ['email' => 'demo.adviser.'.Str::slug($college).'@placeholder.cspc.edu.ph'],
                [
                    'name' => 'Demo Adviser ('.Str::after($college, 'College of ').')',
                    'password' => Hash::make(Str::random(40)),
                    'role_id' => $instructorRole->id,
                    'department' => $college,
                    'course' => null,
                    'section' => null,
                    'status' => 'approved',
                    'email_verified_at' => now(),
                ]
            );

            foreach ($courses as $course) {
                $section = $this->firstSectionWithoutRealAdviser($course);

                if (! $section) {
                    $this->command?->warn("{$course}: every year-one section has a real adviser; skipped.");

                    continue;
                }

                Section::updateOrCreate(
                    [
                        'course' => $course,
                        'section' => $section,
                        'school_year' => $term->school_year,
                        'semester' => $term->semester,
                    ],
                    ['adviser_id' => $adviser->id]
                );

                $this->placeStudent($studentRole, $college, $course, $section, ++$sections);

                $this->command?->line("  {$course} {$section} -> {$adviser->name}");
            }
        }

        $this->command?->info("Demo advisers: ".count(User::COURSES_BY_COLLEGE)." (one per college), advising {$sections} sections "
            ."in {$term->school_year} {$term->semester} semester, each with a demo student.");
    }

    /**
     * 1A, 1B, 1C... until one has no real adviser in any term.
     *
     * Any term, not just the newest: a demo row added to the newest term would
     * outrank a real adviser recorded only in an older one.
     */
    private function firstSectionWithoutRealAdviser(string $course): ?string
    {
        foreach (range('A', 'Z') as $letter) {
            $hasRealAdviser = Section::where('course', $course)
                ->where('section', '1'.$letter)
                ->whereHas('adviser', fn ($q) => $q->where('email', 'not like', 'demo.%'))
                ->exists();

            if (! $hasRealAdviser) {
                return '1'.$letter;
            }
        }

        return null;
    }

    /**
     * The demo student in that section. Reuses the account the earlier
     * "no adviser" seeder made for the programme, if it is still there,
     * rather than leaving it beside a new one.
     */
    private function placeStudent(Role $role, string $college, string $course, string $section, int $number): void
    {
        $slug = Str::slug($course);
        $email = 'demo.'.$slug.'.'.Str::lower($section).'@my.cspc.edu.ph';

        $student = User::whereIn('email', [$email, 'demo.'.$slug.'.no-adviser@my.cspc.edu.ph'])
            ->orderByRaw('email = ? desc', [$email])
            ->first() ?? new User;

        $student->forceFill([
            'email' => $email,
            'name' => 'Demo '.$course.' '.$section,
            'password' => $student->exists ? $student->password : Hash::make(Str::random(40)),
            'role_id' => $role->id,
            'department' => $college,
            'course' => $course,
            'section' => $section,
            'student_id' => '2026-DEMO-ADV-'.str_pad((string) $number, 3, '0', STR_PAD_LEFT),
            'status' => 'approved',
            'email_verified_at' => now(),
        ])->save();
    }
}
