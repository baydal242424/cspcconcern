<?php

namespace App\Console\Commands;

use App\Models\Role;
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
     * The academic line, narrowed to the one programme.
     *
     * Marking every dean, chair and instructor would be most of the roster
     * and none of them would be the right one: a concern reaches the chair
     * of the student's programme and the adviser of their section.
     */
    private const ACADEMIC = ['Dean', 'Program Chair', 'Adviser', 'Instructor'];

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

        // The academic line for this one programme.
        foreach (self::ACADEMIC as $role) {
            $marked += $this->mark(
                User::whereHas('role', fn ($q) => $q->where('name', $role))
                    ->where(fn ($q) => $q->where('course', self::COURSE)
                        ->orWhere('department', 'College of Computer Studies')),
                $signedIn,
                $role === 'Instructor' ? 3 : null
            );
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
        $people = $query->whereNull('deleted_at')->where('status', 'approved')->limit($limit ?? 50)->get();

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

    /** A readable name from an address, for an account nobody has filled in. */
    private function nameFrom(string $email): string
    {
        return Str::of($email)->before('@')->replace(['.', '_'], ' ')->title()->toString();
    }
}
