<?php

namespace App\Console\Commands;

use App\Models\Concern;
use App\Models\Role;
use App\Models\Section;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Name the accounts taking part in a demonstration.
 *
 * On a deployed site the sign-in dropdown offers only `test.` and `demo.`
 * addresses. This marks particular REAL accounts as well, one at a time,
 * so a demonstration can be given as the people who hold the posts -- the
 * counsellor the students would really write to -- and so the students
 * taking part use their own addresses, which is where the system's mail
 * goes.
 *
 *   php artisan demo:participants            mark them
 *   php artisan demo:participants --list     show who is marked
 *   php artisan demo:participants --clear    unmark every one
 *
 * WHO GETS MARKED. The offices a concern can land on, and the academic
 * line for one class -- the one the students below are in, because a
 * concern is routed by programme and section and any other adviser would
 * never see it. Plus the students named here.
 *
 * WHO NEVER DOES. The System Admin, the Staff Admin and the Head of
 * School. A deployed site refuses all three whatever this writes, and this
 * does not pretend otherwise by marking them.
 *
 * AND ONE THING THIS CANNOT UNDO. Anybody who has signed in with Google
 * has a google_id, and that takes them out of the dropdown permanently.
 * The flag does not override it, and is not meant to: it is the fence that
 * keeps the dropdown from being a way to sign in as a real person who
 * never agreed to it. Those accounts sign in with CSPC Mail, as they
 * already have.
 */
class DemoParticipants extends Command
{
    protected $signature = 'demo:participants
        {--list : Show who is marked, then stop}
        {--clear : Unmark every account instead of marking them}';

    protected $description = 'Name the accounts a demonstration may be signed in as';

    /** The class the students below are in. Routing follows it. */
    private const COURSE = 'BS Information Systems';

    private const SECTION = '4A';

    /**
     * One desk each. Every holder is marked, because there is one.
     */
    private const OFFICES = [
        'Guidance Counselor',
        'Gender and Development',
        'General Services',
        'Clinic',
        'Library',
        'Registrar',
        'Student Affairs and Services',
        'ICT Unit',
        'Legal Affairs',
        'Vice President for Academic Affairs',
    ];

    /**
     * The academic posts, EVERY holder of them.
     *
     * This was narrowed to one programme at first, on the reasoning that a
     * concern reaches the chair of the student's own programme and nobody
     * else -- which is true of ROUTING and wrong for a DEMONSTRATION. A
     * panel asks about a college the marking left out, and the answer is a
     * dropdown with one dean in it.
     *
     * There are seven deans and twenty-eight chairs across the institution.
     * A list that long is still a list somebody can read.
     */
    private const ACADEMIC = ['Dean', 'Program Chair', 'Adviser'];

    /**
     * Instructors are the exception, and stay narrow.
     *
     * There are several hundred. All of them would bury every other lane in
     * the dropdown, and one instructor demonstrates what any of them does.
     */
    private const INSTRUCTORS_PER_COLLEGE = 2;

    /** The students taking part, by the address the system will write to. */
    private const STUDENTS = [
        'chrbaydal@my.cspc.edu.ph',
        'yncalibara@my.cspc.edu.ph',
        'jobalila@my.cspc.edu.ph',
        'japadayhag@my.cspc.edu.ph',
        'direyes@my.cspc.edu.ph',
        'jacardiel@my.cspc.edu.ph',
        'jemacalalag@my.cspc.edu.ph',
        'tobrinas@my.cspc.edu.ph',
        'jotormes@my.cspc.edu.ph',
        'chhosana@my.cspc.edu.ph',
    ];

    public function handle(): int
    {
        if ($this->option('list')) {
            return $this->listMarked();
        }

        if ($this->option('clear')) {
            $n = User::where('demo_signin', true)->update(['demo_signin' => false]);
            $this->info("Unmarked {$n} account".($n === 1 ? '' : 's').'.');

            Setting::putFlag(Setting::DEMO_ROUTING, false);
            $this->line('Concerns go to the real desks again.');

            $this->restoreAdviser();

            return self::SUCCESS;
        }

        $marked = 0;
        $made = 0;
        $signedIn = [];

        // The students. Created where they are not here yet, because a
        // classmate who has never opened the site still has to be able to
        // take part -- and the address is the point: it is where the mail
        // about their own concern goes.
        $studentRole = Role::where('name', 'Student')->value('id');

        foreach (self::STUDENTS as $email) {
            $user = User::where('email', $email)->first();

            if (! $user && $studentRole) {
                $user = User::create([
                    'name' => $this->nameFrom($email),
                    'email' => $email,
                    // Sign-in is Google or this dropdown; the password is
                    // never used. Random, so the row cannot be signed into
                    // if that ever changes.
                    'password' => bcrypt(Str::random(32)),
                    'role_id' => $studentRole,
                    'course' => self::COURSE,
                    'section' => self::SECTION,
                    'department' => 'College of Computer Studies',
                    'status' => 'approved',
                    'email_verified_at' => now(),
                ]);
                $made++;
            }

            if (! $user) {
                continue;
            }

            $user->forceFill(['demo_signin' => true])->save();
            $marked++;

            if ($user->google_id) {
                $signedIn[] = $user->email;
            }
        }

        // The offices.
        foreach (self::OFFICES as $role) {
            $marked += $this->mark(
                User::whereHas('role', fn ($q) => $q->where('name', $role)),
                $signedIn
            );
        }

        // Every dean, every chair, every adviser, whatever the college.
        foreach (self::ACADEMIC as $role) {
            $marked += $this->mark(
                User::whereHas('role', fn ($q) => $q->where('name', $role)),
                $signedIn,
                500
            );
        }

        // NO INSTRUCTORS. They were marked here once, two per college, and
        // they do not belong: teaching staff are not a destination in this
        // system -- REFERRAL_ROLES says so in its own comment, because the
        // panel took them off the list of places a case can be sent. An
        // instructor in the sign-in list is an office that cannot receive
        // anything, and on the screen it reads as the one holding an
        // academic case.
        //
        // Unmarked rather than left, so a list written before this is
        // corrected by running the command again.
        $unmarked = User::whereHas('role', fn ($q) => $q->where('name', 'Instructor'))
            ->where('demo_signin', true)
            ->update(['demo_signin' => false]);

        if ($unmarked > 0) {
            $this->line("Unmarked {$unmarked} instructor".($unmarked === 1 ? '' : 's').': teaching staff receive nothing here.');
        }

        $this->info("Marked {$marked} account".($marked === 1 ? '' : 's').'.');

        if ($made > 0) {
            $this->line("Created {$made} student account".($made === 1 ? '' : 's').' that were not here yet.');
        }

        if ($signedIn !== []) {
            $this->newLine();
            $this->warn('These have signed in with Google already, so the dropdown will NOT offer them:');
            foreach (array_unique($signedIn) as $email) {
                $this->line("  {$email}");
            }
            $this->line('That is the rule that stops this being a way to sign in as a real person.');
            $this->line('They sign in with CSPC Mail themselves, as they already have.');
        }

        // Concerns land on the demo desks from here.
        Setting::putFlag(Setting::DEMO_ROUTING, true);
        $this->newLine();
        $this->info('Concerns now land on the demo desks.');

        $this->handTheClassToTheDemoAdviser();

        $this->retireTheDemoInstructor();

        $this->newLine();
        $this->line('The sign-in dropdown still has to be switched on, in Manage Users or with:');
        $this->line("  php artisan tinker --execute=\"App\\Models\\Setting::putFlag('demo_login', true);\"");

        return self::SUCCESS;
    }

    /**
     * Mark a set of accounts, skipping anybody already marked so the count
     * means what it says.
     */
    private function mark($query, array &$signedIn, ?int $limit = null): int
    {
        // No default cap. It used to be 50, which quietly stopped at the
        // fiftieth dean of a list that is seven long and the fiftieth
        // chair of a list that is twenty-eight long -- harmless there,
        // and a silent truncation waiting for the roster to grow.
        $people = $query->whereNull('deleted_at')->where('status', 'approved');

        if ($limit !== null) {
            $people->limit($limit);
        }

        $people = $people->get();

        $n = 0;

        foreach ($people as $person) {
            if (! $person->demo_signin) {
                $person->forceFill(['demo_signin' => true])->save();
                $n++;
            }

            if ($person->google_id) {
                $signedIn[] = $person->email;
            }
        }

        return $n;
    }

    private function listMarked(): int
    {
        $people = User::with('role')->where('demo_signin', true)->orderBy('name')->get();

        if ($people->isEmpty()) {
            $this->line('No account is marked.');

            return self::SUCCESS;
        }

        foreach ($people->groupBy(fn (User $u) => $u->role->name ?? '-')->sortKeys() as $role => $group) {
            $this->line("<info>{$role}</info>");
            foreach ($group as $person) {
                $this->line(sprintf(
                    '  %-30s %-34s %s',
                    $person->name,
                    $person->email,
                    $person->google_id ? '(signed in with Google -- not offered)' : ''
                ));
            }
        }

        $this->newLine();
        $this->info($people->count().' marked, '.$people->whereNull('google_id')->count().' of them offerable.');

        return self::SUCCESS;
    }

    /**
     * Academic reaches the ADVISER OF THE SECTION, not whoever holds the
     * role, so demoDeskFor() never sees it: Section::adviserFor() has
     * already named a person by the time routing asks.
     *
     * The demo instructor takes the class for the demonstration, and the
     * adviser who had it is remembered so --clear can hand it straight
     * back. Without this one step, every Academic concern lands on a real
     * adviser nobody at the demonstration can sign in as.
     */
    private function handTheClassToTheDemoAdviser(): void
    {
        $demo = User::where('email', 'demo.adviser@cspc.edu.ph')->first();

        if (! $demo) {
            $this->warn('No demo adviser, so Academic still goes to the real one.');
            $this->line("  php artisan db:seed --class='Database\\Seeders\\DemoRoleSeeder' --force");

            return;
        }

        $section = Section::where('course', self::COURSE)
            ->where('section', self::SECTION)
            ->orderByDesc('school_year')
            ->orderByDesc('semester')
            ->orderByDesc('id')
            ->first();

        if (! $section) {
            $section = Section::create([
                'course' => self::COURSE,
                'section' => self::SECTION,
                'adviser_id' => $demo->id,
            ]);

            $this->line(self::COURSE.' '.self::SECTION.' had no row; created one advised by '.$demo->name.'.');

            return;
        }

        if ((int) $section->adviser_id === (int) $demo->id) {
            $this->line('Academic already reaches '.$demo->name.'.');

            return;
        }

        // Remembered so it can be given back, and only the FIRST time, so
        // running this twice cannot record the demo adviser as the one to
        // restore.
        if (Setting::flag(self::PRIOR, false) === false
            && ! Setting::where('key', self::PRIOR_ID)->exists()) {
            Setting::updateOrCreate(
                ['key' => self::PRIOR_ID],
                ['value' => $section->id.':'.($section->adviser_id ?? '')]
            );
            Setting::forget();
        }

        $was = User::find($section->adviser_id);

        $section->forceFill(['adviser_id' => $demo->id])->save();

        $this->line('Academic for '.self::COURSE.' '.self::SECTION.' now reaches '.$demo->name
            .($was ? ' (was '.$was->name.')' : '').'.');
    }

    /**
     * Take the old demo instructor out of the dropdown.
     *
     * The `demo.` prefix offers an account whether it is marked or not, so
     * unmarking is not enough to remove this one. It is a fixture rather
     * than a person, but it is NOT deleted while anything points at it: a
     * concern whose handler row is gone is a concern nobody holds, which is
     * worse than one held by the wrong desk.
     */
    private function retireTheDemoInstructor(): void
    {
        $old = User::where('email', 'demo.instructor@cspc.edu.ph')->first();

        if (! $old) {
            return;
        }

        $holds = Concern::where('assigned_to', $old->id)->count();
        $advises = Section::where('adviser_id', $old->id)->count();

        if ($holds > 0 || $advises > 0) {
            $this->warn('The old Demo Instructor still holds '.$holds.' concern'.($holds === 1 ? '' : 's')
                .' and advises '.$advises.' section'.($advises === 1 ? '' : 's').', so it stays.');
            $this->line('  Re-file those concerns, or hand them on, and run this again.');

            return;
        }

        $old->forceDelete();
        $this->line('Retired the old Demo Instructor; teaching staff are out of the dropdown.');
    }
    /** Give the class back to whoever was advising it. */
    private function restoreAdviser(): void
    {
        $row = Setting::where('key', self::PRIOR_ID)->first();

        if (! $row || ! $row->value) {
            return;
        }

        [$sectionId, $adviserId] = array_pad(explode(':', $row->value, 2), 2, '');

        $section = Section::find($sectionId);

        if ($section) {
            $section->forceFill(['adviser_id' => $adviserId === '' ? null : (int) $adviserId])->save();
            $was = User::find($adviserId);
            $this->line('Academic goes back to '.($was->name ?? 'nobody').'.');
        }

        $row->delete();
        Setting::forget();
    }

    /** Where the adviser who had the class is remembered. */
    private const PRIOR = 'demo_prior_adviser_done';

    private const PRIOR_ID = 'demo_prior_adviser';

    /** A readable name from an address, for an account nobody has filled in. */
    private function nameFrom(string $email): string
    {
        return Str::of($email)->before('@')->replace(['.', '_'], ' ')->title()->toString();
    }
}
