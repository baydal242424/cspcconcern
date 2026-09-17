<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\Referral;
use App\Models\Role;
use App\Models\Section;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Admin view of every registered account: who they are, whether they're
 * currently online (see User::isOnline, backed by UpdateLastSeen), and the
 * ability to ban an account suspected of being fake or filing fraudulent
 * reports. A ban takes effect immediately, even mid-session (see
 * UpdateLastSeen middleware) -- it doesn't just block the next login.
 */
class AdminController extends Controller
{
    /**
     * Both administrator tiers can manage accounts.
     *
     * A Staff Admin covers when the System Admin is away -- an account left
     * locked out, or a graduated student waiting to be reactivated, should not
     * have to wait for one specific person to be free.
     *
     * They are not equal, though, and the difference is deliberate: only a
     * System Admin may create, demote, ban or delete another System Admin (see
     * guardSystemAdmin). Without that limit the tiers collapse the moment a
     * Staff Admin promotes themselves, and "who can grant the keys" is exactly
     * the boundary worth keeping.
     *
     * @var list<string>
     */
    private const ADMIN_ROLES = ['System Admin', 'Staff Admin'];

    /**
     * List every registered account.
     */
    public function index()
    {
        $this->authorizeAdmin();

        $users = User::with(['role', 'requestedRole', 'bannedBy', 'advisedSections'])
            ->orderByDesc('last_seen_at')
            ->orderByDesc('created_at')
            ->get();

        // Colleges first, then the units and offices already in use. A
        // department is not a fixed list: colleges come from COURSES_BY_COLLEGE,
        // but office staff carry things like "Information and Communications
        // Technology Unit" that exist only as data. Offering only the colleges
        // would quietly wipe those the next time somebody's role was changed.
        $colleges = array_keys(User::COURSES_BY_COLLEGE);
        $otherUnits = User::query()
            ->whereNotNull('department')
            ->whereNotIn('department', $colleges)
            ->distinct()
            ->orderBy('department')
            ->pluck('department')
            ->all();

        return view('admin.users', [
            'users' => $users,
            'promotion' => $this->promotionPreview(),
            'lastPromotion' => AuditLog::whereIn('action', ['students_promoted', 'students_promotion_undone'])
                ->latest('id')
                ->first(),
            'roles' => Role::orderBy('name')->get(),
            'colleges' => $colleges,
            'otherUnits' => $otherUnits,
            'courses' => User::COURSES_BY_COLLEGE,
        ]);
    }

    /**
     * Move every student up one year level: 1A becomes 2A, 2A becomes 3A.
     *
     * Run once at the start of a school year. The alternative was an admin
     * opening 500-odd accounts and editing a digit in each, which is not a
     * thing anybody does -- so sections went stale, and a stale section is
     * worse than none: it routes a student's academic concerns to the adviser
     * of a class they left a year ago.
     *
     * Only the leading digit moves. The letter is the student's class within
     * the year and does not change, so 1A follows 1A into 2A.
     *
     * Students in their FINAL year are not moved up -- there is no year above
     * theirs, and inventing one would put them in a class nobody advises.
     * They are marked 'graduated' instead, which closes the account: sign-in
     * is refused and any open session ends on the next request.
     *
     * That is reversible on purpose. An irregular student still finishing
     * subjects is indistinguishable from a graduate at this level -- the
     * system knows a year and a section, not a curriculum -- so the account is
     * closed rather than deleted, they are told to ask an Admin, and the Admin
     * reactivates them in one click. Guessing the other way would leave real
     * graduates able to file concerns for years.
     *
     * Everything is recorded in audit_logs, including the previous section and
     * status of every account touched, which is what makes undoPromotion()
     * able to put back exactly what changed.
     */
    public function promoteYearLevels(Request $request)
    {
        $this->authorizeAdmin();

        $students = User::students()->whereNotNull('section')->get();

        $moved = [];
        $graduated = [];
        $unreadable = 0;

        foreach ($students as $student) {
            // "3A" -> year 3, class A. Anything else is left untouched rather
            // than guessed at.
            if (! preg_match('/^([1-6])([A-Za-z])$/', trim($student->section), $parts)) {
                $unreadable++;
                continue;
            }

            [$year, $class] = [(int) $parts[1], strtoupper($parts[2])];

            if ($year >= User::finalYearFor($student->course)) {
                // Only accounts that are currently open. Re-running must not
                // record 'banned' as something to restore later.
                if ($student->status === 'approved') {
                    $graduated[$student->id] = ['status' => $student->status];
                }

                continue;
            }

            $moved[$student->id] = ['from' => $student->section, 'to' => ($year + 1).$class];
        }

        if (empty($moved) && empty($graduated)) {
            return back()->with('error', 'Nothing to do — no student has a section that can be moved.');
        }

        DB::transaction(function () use ($moved, $graduated, $unreadable, $request) {
            // One UPDATE per target section rather than one per student.
            //
            // The id list is built by hand on purpose. Collection::groupBy()
            // re-indexes by default, so grouping the id-keyed array and asking
            // for its keys back gave 0, 1, 2 -- which whereIn() then matched
            // against real user ids and rewrote the wrong people's sections.
            $byTarget = [];

            foreach ($moved as $id => $move) {
                $byTarget[$move['to']][] = $id;
            }

            foreach ($byTarget as $to => $ids) {
                User::whereIn('id', $ids)->update(['section' => $to]);
            }

            if (! empty($graduated)) {
                User::whereIn('id', array_keys($graduated))->update(['status' => 'graduated']);
            }

            AuditLog::create([
                'user_id' => Auth::id(),
                'action' => 'students_promoted',
                // The full before-and-after, so the change can be reversed
                // exactly rather than by subtracting one from everybody --
                // which would also hit students who were never moved.
                'changes' => json_encode(['moved' => $moved, 'graduated' => $graduated]),
                'description' => count($moved).' students moved up a year level; '
                    .count($graduated).' final-year accounts closed as graduated'
                    .($unreadable > 0 ? "; {$unreadable} skipped with an unreadable section" : ''),
                'ip_address' => $request->ip(),
            ]);
        });

        $message = count($moved).' students moved up a year level.';

        if (! empty($graduated)) {
            $message .= ' '.count($graduated).' final-year accounts were closed as graduated — '
                .'anyone still enrolled can ask you to reactivate them.';
        }

        if ($unreadable > 0) {
            $message .= " {$unreadable} had a section that could not be read and were skipped.";
        }

        return back()->with('success', $message);
    }

    /**
     * Put a staff member in charge of a class.
     *
     * Advising is a relationship, not a field. One instructor advises several
     * sections -- three each is normal here -- so it could never live in
     * users.section, which holds one string. It goes in the sections table,
     * one row per class per term, which is also what Section::adviserFor()
     * reads when an Academic concern needs a destination.
     *
     * Assigning a class that already has an adviser REPLACES them rather than
     * failing. Handovers happen mid-year, and a form that refused would leave
     * the admin deleting a row by hand to do something ordinary.
     */
    public function assignSection(Request $request, User $user)
    {
        $this->authorizeAdmin();

        if (! $user->isEmployee()) {
            return back()->with('error', 'Only a staff account can advise a class.');
        }

        $validated = $request->validate([
            'course' => ['required', 'string', Rule::in(User::allCourses())],
            'year' => ['required', 'integer', 'between:1,6'],
            'section_letter' => ['required', 'string', 'regex:/^[A-Za-z]$/'],
        ], [
            'course.required' => 'Choose the programme the class belongs to.',
            'section_letter.regex' => 'The class is a single letter, like A.',
        ]);

        $term = Section::currentTerm();
        $section = $validated['year'].strtoupper($validated['section_letter']);

        $row = Section::updateOrCreate(
            [
                'course' => $validated['course'],
                'section' => $section,
                'school_year' => $term['school_year'],
                'semester' => $term['semester'],
            ],
            ['adviser_id' => $user->id]
        );

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'section_adviser_assigned',
            'changes' => json_encode(['section_id' => $row->id, 'adviser_id' => $user->id]),
            'description' => "{$user->name} now advises {$validated['course']} {$section}",
            'ip_address' => $request->ip(),
        ]);

        return back()->with('success', "{$user->name} now advises {$validated['course']} {$section}.");
    }

    /**
     * Take a staff member off a class.
     *
     * Clears the adviser rather than deleting the row. The class still exists
     * and students are still in it -- deleting would take the record with it,
     * and their concerns would stop matching a section at all rather than
     * falling back to the college, which is the softer failure.
     */
    public function unassignSection(Request $request, User $user, Section $section)
    {
        $this->authorizeAdmin();

        if ((int) $section->adviser_id !== $user->id) {
            return back()->with('error', 'That class is not advised by this person.');
        }

        $section->forceFill(['adviser_id' => null])->save();

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'section_adviser_removed',
            'changes' => json_encode(['section_id' => $section->id, 'was_adviser_id' => $user->id]),
            'description' => "{$user->name} no longer advises {$section->course} {$section->section}",
            'ip_address' => $request->ip(),
        ]);

        return back()->with('success',
            "{$user->name} no longer advises {$section->course} {$section->section}. "
            .'That class now has no adviser, so its concerns fall back to the college.');
    }

    /**
     * Grant or refuse the role a staff member asked for when they signed up.
     *
     * The person filled in their own college, programme and section -- those
     * were saved as given, because they describe where somebody works and
     * grant nothing. The role waited here, because role IS permission: a
     * self-granted Guidance Counselor would read every mental-health and
     * harassment report in the college.
     *
     * Granting is one press rather than re-picking from a dropdown, so the
     * common case -- the request is correct -- costs an admin nothing. Getting
     * it wrong is what the Role field below is still for.
     */
    public function decideRoleRequest(Request $request, User $user)
    {
        $this->authorizeAdmin();

        $validated = $request->validate([
            'decision' => ['required', Rule::in(['grant', 'refuse'])],
        ]);

        if (! $user->requested_role_id) {
            return back()->with('error', 'That account has no role request waiting.');
        }

        $asked = Role::find($user->requested_role_id);

        // A Staff Admin cannot grant what they could not assign directly --
        // otherwise the request queue becomes a way around guardSystemAdmin.
        $this->guardSystemAdmin($user, optional($asked)->name);

        $granted = $validated['decision'] === 'grant';

        $user->forceFill(array_merge(
            // Cleared either way: the request has been answered, and leaving
            // it would keep the account in the pending list forever.
            ['requested_role_id' => null, 'role_requested_at' => null],
            $granted ? ['role_id' => $asked->id] : []
        ))->save();

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => $granted ? 'role_request_granted' : 'role_request_refused',
            'changes' => json_encode(['user_id' => $user->id, 'role' => optional($asked)->name]),
            'description' => ($granted ? 'Granted ' : 'Refused ').optional($asked)->name
                .' to '.$user->name,
            'ip_address' => $request->ip(),
        ]);

        Notification::create([
            'user_id' => $user->id,
            'type' => $granted ? 'role_granted' : 'role_refused',
            'title' => $granted ? 'Your role was approved' : 'Your role request was not approved',
            'message' => $granted
                ? 'You are now recorded as '.optional($asked)->name.'. Concerns for this role will start reaching you.'
                : 'An administrator did not approve the '.optional($asked)->name
                    .' role. Contact them if you think this is a mistake.',
            'is_read' => false,
        ]);

        // Everyone else's copy of the request is done with.
        Notification::where('type', 'role_request')
            ->where('message', 'like', '%('.$user->email.')%')
            ->where('is_read', false)
            ->update(['is_read' => true, 'read_at' => now()]);

        return back()->with('success', $granted
            ? $user->name.' is now '.optional($asked)->name.'.'
            : 'The request from '.$user->name.' was refused; their role is unchanged.');
    }

    /**
     * Reopen a graduated account.
     *
     * The irregular student's way back in. Nothing in the data distinguishes
     * them from a graduate -- the system holds a year and a section, not a
     * curriculum -- so a person decides, and this records who.
     */
    public function reactivate(Request $request, User $user)
    {
        $this->authorizeAdmin();

        if ($user->status !== 'graduated') {
            return back()->with('error', 'That account is not closed as graduated.');
        }

        $user->forceFill(['status' => 'approved'])->save();

        // Clear the request from every Admin's bell. Without this the badge
        // keeps showing a request that has already been granted, and a second
        // Admin opens it to find nothing to do.
        Notification::where('type', 'reactivation_request')
            ->where('message', 'like', '%('.$user->email.')%')
            ->where('is_read', false)
            ->update(['is_read' => true, 'read_at' => now()]);

        // Waiting for them when they sign in, so they know it was acted on
        // rather than having to keep trying the login page.
        Notification::create([
            'user_id' => $user->id,
            'type' => 'account_reactivated',
            'title' => 'Your account is open again',
            'message' => 'An administrator reopened your account. You can file and track concerns as before.',
            'is_read' => false,
        ]);

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'student_reactivated',
            'changes' => json_encode(['user_id' => $user->id, 'from' => 'graduated', 'to' => 'approved']),
            'description' => "Reactivated {$user->name} after graduation was recorded",
            'ip_address' => $request->ip(),
        ]);

        return back()->with('success', "{$user->name} can sign in again.");
    }

    /**
     * Put back exactly what the last promotion changed.
     *
     * One button that rewrites every student row wants a way back. This
     * reverses by the recorded before-and-after rather than by subtracting a
     * year from everybody, so it cannot touch a student who was not part of
     * that run.
     *
     * An account whose section has been edited since is left alone and
     * counted: the admin's later edit is newer information than this undo.
     */
    public function undoPromotion(Request $request)
    {
        $this->authorizeAdmin();

        $last = AuditLog::where('action', 'students_promoted')->latest('id')->first();

        if (! $last) {
            return back()->with('error', 'There is no promotion to undo.');
        }

        $record = json_decode($last->changes, true) ?: [];
        $moved = $record['moved'] ?? [];
        $graduated = $record['graduated'] ?? [];

        $restored = 0;
        $reopened = 0;
        $changedSince = 0;

        DB::transaction(function () use ($moved, $graduated, &$restored, &$reopened, &$changedSince, $last, $request) {
            foreach ($moved as $id => $move) {
                $affected = User::whereKey($id)
                    ->where('section', $move['to'])
                    ->update(['section' => $move['from']]);

                $affected ? $restored++ : $changedSince++;
            }

            foreach ($graduated as $id => $was) {
                // Only accounts still sitting where the promotion left them. An
                // account banned since is left banned -- that decision is newer.
                $affected = User::whereKey($id)
                    ->where('status', 'graduated')
                    ->update(['status' => $was['status']]);

                $affected ? $reopened++ : $changedSince++;
            }

            AuditLog::create([
                'user_id' => Auth::id(),
                'action' => 'students_promotion_undone',
                'changes' => json_encode(['undid_audit_log_id' => $last->id]),
                'description' => "{$restored} students put back a year level, {$reopened} accounts reopened"
                    .($changedSince > 0 ? "; {$changedSince} skipped, changed since" : ''),
                'ip_address' => $request->ip(),
            ]);
        });

        $message = "{$restored} students were put back a year level";
        $message .= $reopened > 0 ? ", and {$reopened} graduated accounts were reopened." : '.';

        if ($changedSince > 0) {
            $message .= " {$changedSince} were left alone because they had been changed since.";
        }

        return back()->with('success', $message);
    }

    /**
     * What a promotion would do, without doing it.
     *
     * Shown beside the button, because the admin should not have to press an
     * irreversible-looking button on 500 accounts to find out what it touches.
     *
     * @return array{moving:int, graduating:int, unreadable:int, noSection:int, closed:int}
     */
    private function promotionPreview(): array
    {
        $preview = ['moving' => 0, 'graduating' => 0, 'unreadable' => 0, 'noSection' => 0, 'closed' => 0];

        foreach (User::students()->get() as $student) {
            if ($student->status === 'graduated') {
                $preview['closed']++;
            } elseif (blank($student->section)) {
                $preview['noSection']++;
            } elseif (! preg_match('/^([1-6])([A-Za-z])$/', trim($student->section), $parts)) {
                $preview['unreadable']++;
            } elseif ((int) $parts[1] >= User::finalYearFor($student->course)) {
                $preview['graduating']++;
            } else {
                $preview['moving']++;
            }
        }

        return $preview;
    }

    /**
     * Ban an account: blocks login and signs out any active session.
     */
    public function ban(Request $request, User $user)
    {
        $this->authorizeAdmin();
        $this->guardSystemAdmin($user);

        if ($user->id === Auth::id()) {
            abort(422, 'You cannot ban your own account.');
        }

        $validated = $request->validate([
            'reason' => 'nullable|string|max:255',
        ]);

        $user->update([
            'status' => 'banned',
            'banned_by' => Auth::id(),
            'banned_at' => now(),
            'ban_reason' => $validated['reason'] ?? null,
        ]);

        return back()->with('success', "{$user->name}'s account has been banned.");
    }

    /**
     * Lift a ban and restore normal access.
     */
    public function unban(User $user)
    {
        $this->authorizeAdmin();
        $this->guardSystemAdmin($user);

        $user->update([
            'status' => 'approved',
            'banned_by' => null,
            'banned_at' => null,
            'ban_reason' => null,
        ]);

        return back()->with('success', "{$user->name}'s account has been unbanned.");
    }

    /**
     * Change an account's role (e.g. a student moving to a staff position).
     */
    public function updateRole(Request $request, User $user)
    {
        $this->authorizeAdmin();

        if ($user->id === Auth::id()) {
            abort(422, 'You cannot change your own role.');
        }

        $this->guardSystemAdmin($user, Role::whereKey($request->input('role_id'))->value('name'));

        // Department is validated as free text against what is actually in use
        // rather than against a fixed list, because it holds two different
        // kinds of thing: the six colleges, and the units and offices that
        // exist only as data on other accounts.
        $validated = $request->validate([
            'role_id' => 'required|exists:roles,id',
            'department' => 'nullable|string|max:255',
            'course' => ['nullable', 'string', Rule::in(User::allCourses())],
            // Year and class letter, combined into users.section below.
            'year' => ['nullable', 'integer', 'between:1,6'],
            'section_letter' => ['nullable', 'string', 'regex:/^[A-Za-z]$/'],
            'student_id' => ['nullable', 'string', 'max:50'],
            'employee_id' => ['nullable', 'string', 'max:50'],
        ], [
            'section_letter.regex' => 'The class is a single letter, like A.',
        ]);

        $changes = ['role_id' => $validated['role_id']];

        // Only touch what was actually submitted. The admin form always sends
        // all three, but a role-only post -- from a script, or an older form --
        // would otherwise silently blank someone's college, and a handler with
        // no college is skipped by findHandler() without anything appearing to
        // be wrong.
        if ($request->has('department')) {
            $changes['department'] = $validated['department'] ?? null;
        }

        // Only programme-scoped accounts carry a course: a student's own, and
        // the programme a Program Chair covers. On anybody else findHandler()
        // starts preferring them for that programme's concerns -- a routing
        // bug with nothing on screen to show for it.
        //
        // Cleared here rather than trusted to the form. The programme picker is
        // hidden for other roles, but a hidden field still posts its value, so
        // promoting a BSIS student to Instructor carried their programme along
        // with them and quietly made them the preferred handler for every BSIS
        // concern in the college.
        $programmeScoped = Role::whereKey($validated['role_id'])
            ->whereIn('name', ['Student', 'Program Chair'])
            ->exists();

        if (! $programmeScoped) {
            $changes['course'] = null;
        } elseif ($request->has('course')) {
            $changes['course'] = $validated['course'] ?? null;
        }

        // Year level and section are stored as one value -- "4A" is fourth
        // year, class A -- because that is what Section::adviserFor() matches
        // and what the start-of-year promotion increments. They are edited as
        // two dropdowns because that is how a person thinks of them, and
        // because a free-text box invited "4-A", "IV-A" and "4a", none of
        // which the adviser lookup would match.
        $isStudent = Role::whereKey($validated['role_id'])->where('name', 'Student')->exists();

        if (! $isStudent) {
            // The two numbers come from different offices and mean different
            // things, so an account holds one or the other -- never both. A
            // student number left on a staff account would return them when an
            // admin searches for a student by id, which is the exact confusion
            // the number exists to prevent.
            $changes['student_id'] = null;

            if ($request->has('employee_id')) {
                $changes['employee_id'] = $validated['employee_id'] ?? null;
            }
        } else {
            $changes['employee_id'] = null;

            if ($request->has('student_id')) {
                $changes['student_id'] = $validated['student_id'] ?? null;
            }

            if ($request->has('year')) {
                $year = $validated['year'] ?? null;
                $letter = strtoupper($validated['section_letter'] ?? '');

                // Both or neither. Half of a section is not a section: "4"
                // with no class letter matches no row in sections, so the
                // student would silently drop to college-level routing.
                $changes['section'] = ($year && $letter !== '') ? $year.$letter : null;
            }
        }

        $user->update($changes);

        return back()->with('success', "{$user->name} has been updated.");
    }

    /**
     * Permanently delete an account. Referrals restrict deletion of either
     * party, so any referral this user sent or received is removed first;
     * everything else (their own concerns, attachments, audit entries,
     * notifications) cascades at the database level.
     */
    public function destroy(User $user)
    {
        $this->authorizeAdmin();
        $this->guardSystemAdmin($user);

        if ($user->id === Auth::id()) {
            abort(422, 'You cannot delete your own account.');
        }

        $name = $user->name;

        DB::transaction(function () use ($user) {
            Referral::where('referred_by', $user->id)
                ->orWhere('referred_to', $user->id)
                ->delete();

            $user->delete();
        });

        return back()->with('success', "{$name}'s account and submitted concerns have been permanently deleted.");
    }

    /**
     * Only Admins may manage accounts.
     */
    private function authorizeAdmin(): void
    {
        $user = Auth::user();

        if (! $user->role || ! in_array($user->role->name, self::ADMIN_ROLES, true)) {
            abort(403, 'Only an administrator can manage accounts.');
        }
    }

    /**
     * The one thing a Staff Admin may not touch: a System Admin account, or
     * the System Admin role itself.
     *
     * Everything else on this page is shared, so the office can keep working
     * while the System Admin is busy. This is the line that stops a Staff
     * Admin promoting themselves, or banning the System Admin and becoming the
     * only administrator left -- which would turn "cover for them" into "take
     * over from them".
     *
     * @param  User|null  $target  the account being acted on
     * @param  string|null  $newRole  the role being granted, if any
     */
    private function guardSystemAdmin(?User $target = null, ?string $newRole = null): void
    {
        if (optional(Auth::user()->role)->name === 'System Admin') {
            return;
        }

        if ($target && optional($target->role)->name === 'System Admin') {
            abort(403, 'Only a System Admin can change another System Admin account.');
        }

        if ($newRole === 'System Admin') {
            abort(403, 'Only a System Admin can appoint another System Admin.');
        }
    }
}
