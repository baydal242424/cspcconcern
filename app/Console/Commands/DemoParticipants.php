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

            $this->restoreTheRealHolders();

            Setting::putFlag(Setting::DEMO_ROUTING, false);
            $this->line('Concerns go to the real desks again.');

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

        // The real holders step aside first, so every desk below is a demo one.
        $this->parkTheRealHolders();

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

        $this->assignTheClassAdviser();

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
     * The class adviser of the students taking part: Glaiza Pocaan.
     *
     * Academic reaches the adviser OF THE SECTION, named by
     * Section::adviserFor() before routing is asked, so the demo-desk
     * preference never applies to it. This used to hand the class to a demo
     * account for the demonstration. It is the real adviser now, because she
     * is the one who would answer, and the mail about the case goes to her
     * own address.
     *
     * She is in no seeder: her account was made the first time she signed in
     * with Google, and promoted by hand. On a freshly deployed site she is
     * therefore either absent or a Student. Both are put right here.
     */
    private const CLASS_ADVISER_EMAIL = 'glpocaan@my.cspc.edu.ph';

    private const CLASS_ADVISER_NAME = 'Glaiza Pocaan';

    private function assignTheClassAdviser(): void
    {
        $adviserRole = Role::where('name', 'Adviser')->value('id');

        if (! $adviserRole) {
            $this->warn('There is no Adviser role here, so the class adviser cannot be set.');

            return;
        }

        $adviser = User::withTrashed()->where('email', self::CLASS_ADVISER_EMAIL)->first();

        if (! $adviser) {
            $adviser = User::create([
                'name' => self::CLASS_ADVISER_NAME,
                'email' => self::CLASS_ADVISER_EMAIL,
                'password' => bcrypt(Str::random(32)),
                'role_id' => $adviserRole,
                'department' => 'College of Computer Studies',
                'course' => self::COURSE,
                'status' => 'approved',
                'email_verified_at' => now(),
            ]);
            $this->line('Created '.$adviser->name.' as an Adviser.');
        } else {
            if ($adviser->trashed()) {
                $adviser->restore();
            }

            if ((int) $adviser->role_id !== (int) $adviserRole) {
                $adviser->forceFill(['role_id' => $adviserRole])->save();
                $this->line($adviser->name.' is an Adviser now.');
            }

            if ($adviser->status !== 'approved') {
                $adviser->forceFill(['status' => 'approved'])->save();
            }
        }

        $section = Section::where('course', self::COURSE)
            ->where('section', self::SECTION)
            ->orderByDesc('school_year')
            ->orderByDesc('semester')
            ->orderByDesc('id')
            ->first();

        if (! $section) {
            $term = Section::currentTerm();
            Section::create([
                'course' => self::COURSE,
                'section' => self::SECTION,
                'school_year' => $term['school_year'],
                'semester' => $term['semester'],
                'adviser_id' => $adviser->id,
            ]);
        } elseif ((int) $section->adviser_id !== (int) $adviser->id) {
            $section->forceFill(['adviser_id' => $adviser->id])->save();
        }

        // The remembered "adviser before the demonstration" from the old
        // hand-over has nothing left to restore.
        Setting::where('key', 'demo_prior_adviser')->delete();
        Setting::forget();

        $this->line('Academic for '.self::COURSE.' '.self::SECTION.' reaches '.$adviser->name.' <'.$adviser->email.'>.');
    }
    /**
     * Roles taken off the real people holding them while a demonstration
     * runs, so the only holder of each is a demo account.
     *
     * Every desk a category lands on or a case can be referred to. NOT the
     * System Admin, Staff Admin or Head of School -- the people running the
     * demonstration sign in as those -- and NOT the Adviser, because the
     * class adviser is Glaiza Pocaan on purpose.
     */
    private const PARKED_ROLES = [
        'Guidance Counselor',
        'Gender and Development',
        'General Services',
        'ICT Unit',
        'Clinic',
        'Library',
        'Registrar',
        'Student Affairs and Services',
        'Legal Affairs',
        'Program Chair',
        'Dean',
        'Vice President for Academic Affairs',
        'Faculty/Staff',
    ];

    /** Where each parked person's own role is kept, so --clear can give it back. */
    private const PARKED_KEY = 'demo_parked_roles';

    /**
     * Take the real holders out of each office, remembering who they were.
     *
     * Their role is set to nothing rather than to some other role. Any other
     * role is a desk of its own -- Faculty/Staff is a referral destination,
     * Instructor is the fallback below a refused adviser -- and parking
     * sixty people in one would hand them a queue they never had. With no
     * role they are in no picker, no dropdown and no routing pool, and the
     * "No role" filter in Manage Users finds every one of them.
     *
     * A role is only cleared where a demo account already holds it. Taking
     * the last holder out of an office leaves its concerns landing on
     * nobody, which is the one outcome worse than landing on the wrong
     * person.
     */
    private function parkTheRealHolders(): void
    {
        $parked = json_decode((string) Setting::where('key', self::PARKED_KEY)->value('value'), true) ?: [];
        $moved = 0;
        $kept = [];

        foreach (self::PARKED_ROLES as $roleName) {
            $roleId = Role::where('name', $roleName)->value('id');

            if (! $roleId) {
                continue;
            }

            $hasDemo = User::where('role_id', $roleId)
                ->where(fn ($q) => $q->where('email', 'like', 'demo.%')->orWhere('email', 'like', 'test.%'))
                ->exists();

            if (! $hasDemo) {
                $kept[] = $roleName;

                continue;
            }

            $real = User::where('role_id', $roleId)
                ->where('email', 'not like', 'demo.%')
                ->where('email', 'not like', 'test.%')
                ->get();

            foreach ($real as $person) {
                // The FIRST role recorded is the one given back, so running
                // this twice cannot record "no role" as somebody's own.
                $parked[(string) $person->id] ??= (int) $person->role_id;
                $person->forceFill(['role_id' => null, 'demo_signin' => false])->save();
                $moved++;
            }
        }

        Setting::updateOrCreate(['key' => self::PARKED_KEY], ['value' => json_encode($parked)]);
        Setting::forget();

        $this->line("Stepped {$moved} real holder".($moved === 1 ? '' : 's').' aside; demo accounts hold those desks now.');

        if ($kept !== []) {
            $this->warn('Left with their real holders, because no demo account holds them yet: '.implode(', ', $kept));
            $this->line("  php artisan db:seed --class='Database\\Seeders\\DemoRoleSeeder' --force");
        }
    }

    /** Give every parked person their own role back. */
    private function restoreTheRealHolders(): void
    {
        $row = Setting::where('key', self::PARKED_KEY)->first();
        $parked = $row ? (json_decode((string) $row->value, true) ?: []) : [];

        $n = 0;

        foreach ($parked as $userId => $roleId) {
            $n += User::where('id', $userId)->whereNull('role_id')->update(['role_id' => $roleId]);
        }

        $row?->delete();
        Setting::forget();

        $this->line("Gave {$n} real holder".($n === 1 ? '' : 's').' their own role back.');
    }
    /**
     * Take the old demo instructor out of the dropdown.
     *
     * The `demo.` prefix offers an account whether it is marked or not, so
     * unmarking is not enough to remove this one. It is a fixture rather
     * than a person, but it is NOT deleted while anything points at it: a
     * concern whose handler row is gone is a concern nobody holds, which is
     * worse than one held by the wrong desk.
     *
     * (This method was lost once, cut out with the code around it when the
     * class adviser changed, and the command died on its last line. A test
     * now runs the command end to end so that cannot happen quietly again.)
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

            return;
        }

        $old->forceDelete();
        $this->line('Retired the old Demo Instructor; teaching staff are out of the dropdown.');
    }
    /** A readable name from an address, for an account nobody has filled in. */
    private function nameFrom(string $email): string
    {
        return Str::of($email)->before('@')->replace(['.', '_'], ' ')->title()->toString();
    }
}
