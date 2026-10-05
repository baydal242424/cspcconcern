<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Concern;
use App\Models\Notification;
use App\Models\Referral;
use App\Models\Role;
use App\Models\Section;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

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
    public function index(Request $request)
    {
        $this->authorizeAdmin();

        // Filtering and paging both happen in the database now. They used to
        // happen in the browser: every account was rendered as a card and
        // JavaScript hid the ones that did not match. That is fine for a few
        // hundred rows and fatal past them -- at 896 accounts the page
        // exhausted PHP's memory while rendering, so nobody saw anything at
        // all. Filtering server-side also means a search reaches the whole
        // roster rather than whatever happened to be on the page.
        $filters = [
            'q' => trim((string) $request->query('q')) !== '' ? trim((string) $request->query('q')) : null,
            'role' => $request->query('role') ?: null,
            'college' => $request->query('college') ?: null,
            'status' => $request->query('status') ?: null,
        ];

        $users = User::with(['role', 'requestedRole', 'bannedBy', 'advisedSections', 'additionalRoles'])
            ->when($filters['role'], fn ($q, $role) => $role === 'No role'
                ? $q->whereNull('role_id')
                : $q->whereHas('role', fn ($r) => $r->where('name', $role)))
            ->when($filters['college'], fn ($q, $college) => $q->where('department', $college))
            // "deleted" is not a value of the status column -- it is the
            // absence of the row from normal results. It sits in the same
            // dropdown because that is where an administrator looks for an
            // account that is not where they expect it to be.
            ->when($filters['status'] === 'deleted', fn ($q) => $q->onlyTrashed())
            ->when($filters['status'] && $filters['status'] !== 'deleted',
                fn ($q) => $q->where('status', $filters['status']))
            ->when($filters['q'], function ($q, $term) {
                // The same fields the old client-side blob covered, so a
                // search that used to work still does: name, both ID numbers,
                // email, college, programme and section.
                $q->where(function ($sub) use ($term) {
                    foreach (['name', 'email', 'student_id', 'employee_id', 'department', 'course', 'section'] as $column) {
                        $sub->orWhere($column, 'like', '%'.$term.'%');
                    }

                    $sub->orWhereHas('role', fn ($r) => $r->where('name', 'like', '%'.$term.'%'));

                    // The class they advise, and the word itself. Nearly every
                    // adviser holds another role -- Instructor, Program Chair,
                    // Dean -- so searching "adviser" found nobody, and neither
                    // did the class: the person to replace could not be found
                    // from the one thing the admin knew about them.
                    $sub->orWhereHas('advisedSections', function ($s) use ($term) {
                        $s->where('course', 'like', '%'.$term.'%')
                            ->orWhere('section', 'like', '%'.$term.'%')
                            ->orWhereRaw("CONCAT(course, ' ', section) LIKE ?", ['%'.$term.'%']);
                    });

                    if (str_contains(Str::lower($term), 'adviser')) {
                        $sub->orWhereHas('advisedSections');
                    }
                });
            })
            ->orderByDesc('last_seen_at')
            ->orderByDesc('created_at')
            ->paginate(30)
            ->withQueryString();

        // Options come from the whole table, not the page being shown: a role
        // held only by somebody on page four still has to be selectable.
        $roleOptions = Role::whereIn('id', User::whereNotNull('role_id')->distinct()->pluck('role_id'))
            ->orderBy('name')->pluck('name');

        $collegeOptions = User::whereNotNull('department')->distinct()->orderBy('department')->pluck('department');
        $statusOptions = User::whereNotNull('status')->distinct()->orderBy('status')->pluck('status');

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
            'filters' => $filters,
            'roleOptions' => $roleOptions,
            'collegeOptions' => $collegeOptions,
            'statusOptions' => $statusOptions,
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
            'year' => ['required', 'integer', 'between:1,'.User::longestProgrammeYears()],
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

        // The class they named at sign-up, now confirmed: the suggestion has
        // done its job and should stop asking to be confirmed.
        if ($user->advises_request === $validated['course'].'|'.$section) {
            $user->forceFill(['advises_request' => null])->save();
        }

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
    /**
     * Give somebody a second hat, or take it off again.
     *
     * The primary role stays where it is: it is what the account is listed as
     * and what routing matches on when choosing a handler. An extra role adds
     * what that role can READ and the pages it can open, which is what
     * somebody covering Staff Admin alongside their own job actually needs.
     * It does not start sending them that role's work.
     */
    public function updateAdditionalRoles(Request $request, User $user)
    {
        $this->authorizeAdmin();

        $validated = $request->validate([
            'role_ids' => ['nullable', 'array'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
        ]);

        $wanted = Role::whereIn('id', $validated['role_ids'] ?? [])->get();

        // The same guard as the primary role: only a System Admin may hand out
        // System Admin, whichever field it is handed out through.
        foreach ($wanted as $role) {
            $this->guardSystemAdmin($user, $role->name);
        }

        // Never the role they already hold. Listing it twice would show it
        // twice everywhere and mean nothing extra.
        $ids = $wanted->pluck('id')->reject(fn ($id) => (int) $id === (int) $user->role_id)->values()->all();

        $before = $user->additionalRoles()->pluck('roles.name')->sort()->implode(', ');
        $user->additionalRoles()->sync($ids);
        $after = $user->additionalRoles()->pluck('roles.name')->sort()->implode(', ');

        if ($before !== $after) {
            AuditLog::create([
                'user_id' => Auth::id(),
                'action' => 'additional_roles_updated',
                'description' => $user->name.': additional roles '
                    .($before === '' ? 'none' : $before).' -> '.($after === '' ? 'none' : $after),
                'ip_address' => $request->ip(),
            ]);
        }

        return back()->with('success', $after === ''
            ? "{$user->name} now holds only their main role."
            : "{$user->name} also holds: {$after}.");
    }

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
            'year' => ['nullable', 'integer', 'between:1,'.User::longestProgrammeYears()],
            'section_letter' => ['nullable', 'string', 'regex:/^[A-Za-z]$/'],
            // ignore($user->id): an admin saving somebody's row without
            // touching their number must not be told it is taken by them.
            'student_id' => ['nullable', 'string', 'max:50', AuthController::uniqueAmongLiveAccounts('student_id', $user)],
            'employee_id' => ['nullable', 'string', 'max:50', AuthController::uniqueAmongLiveAccounts('employee_id', $user)],
        ], [
            'section_letter.regex' => 'The class is a single letter, like A.',
            'student_id.unique' => 'Another account already uses that student number.',
            'employee_id.unique' => 'Another account already uses that staff number.',
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
            //
            // Except on a student ADDRESS. A my.cspc.edu.ph account given a
            // staff role is a student being used to try a role out, and
            // wiping the number made the round trip lossy: set back to Student
            // afterwards, they returned with no student number at all.
            if (! str_ends_with(strtolower((string) $user->email), '@my.cspc.edu.ph')) {
                $changes['student_id'] = null;
            }

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

        // A student advises nobody. An account turned back into a student --
        // after trying the Adviser role out, say -- releases every class it
        // was given, or it would go on receiving its own classmates' concerns
        // with nothing on its card to show why.
        if ($isStudent) {
            Section::where('adviser_id', $user->id)->update(['adviser_id' => null]);
        }

        return back()->with('success', "{$user->name} has been updated.");
    }

    /**
     * Delete an account, recoverably.
     *
     * The account stops working immediately: it cannot sign in and it is gone
     * from every list. Nothing it owns is destroyed. Everything the person
     * filed -- concerns, evidence, referrals, audit entries -- stays where it
     * is, and comes back with them if they sign in again.
     *
     * This used to be a real DELETE, which cascaded their whole reporting
     * history out of the database. Deleting the wrong row out of several
     * hundred near-identical names is an ordinary slip, and it had no undo.
     *
     * @see restore()  putting one back by hand
     * @see AuthController::handleGoogleCallback()  putting one back by returning
     */
    public function destroy(User $user)
    {
        $this->authorizeAdmin();
        $this->guardSystemAdmin($user);

        if ($user->id === Auth::id()) {
            abort(422, 'You cannot delete your own account.');
        }

        $name = $user->name;

        // A soft delete, so the foreign key never fires. The row staying put
        // is the whole mechanism: concerns.user_id cascades on a real DELETE,
        // and that cascade is what used to take an entire reporting history
        // with one misplaced click.
        //
        // Referrals and uploaded evidence are left alone for the same reason.
        // They belong to concerns that still exist, and an account that comes
        // back needs to find them intact.
        //
        // Posts they held are a different matter, and have to be given up by
        // hand. Both were database rules -- concerns.assigned_to is ON DELETE
        // SET NULL, sections.adviser_id the same -- and a real DELETE tripped
        // them. A soft delete trips nothing, so without this an account that
        // can no longer sign in would keep every case it was handling and
        // stay listed as the adviser of its classes.
        //
        // Not undone by a restore, deliberately: by then somebody else has
        // picked the work up, and handing it back would take it off their
        // desk without telling them.
        DB::transaction(function () use ($user) {
            Concern::where('assigned_to', $user->id)->update(['assigned_to' => null]);
            Section::where('adviser_id', $user->id)->update(['adviser_id' => null]);

            $user->delete();
        });

        $concerns = $user->submittedConcerns()->count();
        $filed = $concerns > 0
            ? ' The '.$concerns.' '.Str::plural('concern', $concerns)
                .' they filed are kept, hidden, and come back if the account does.'
            : '';

        return back()->with(
            'success',
            "{$name}'s account has been deleted. They can no longer sign in.".$filed
        );
    }

    /**
     * Create one account from Manage Users.
     *
     * The same job as `php artisan user:add`, which is where this lived until
     * now -- useful to whoever runs the server, and no use at all to the
     * administrator sitting in front of the page. Every rule that command
     * enforces is enforced here, because they are the rules that decide
     * whether a concern ever reaches anybody:
     *
     *  - a CSPC address, since Google sign-in turns away every other domain;
     *  - a college spelled exactly as the system spells it;
     *  - a programme that belongs to that college;
     *  - a year the programme actually runs to;
     *  - an ID number nobody else is using.
     *
     * The account is dormant: no google_id, so it comes alive on that
     * person's first CSPC Mail sign-in and keeps everything set here. Nothing
     * is emailed and no password exists, because there is no password
     * sign-in to use one.
     */
    public function storeUser(Request $request)
    {
        $this->authorizeAdmin();

        $colleges = array_keys(User::COURSES_BY_COLLEGE);
        $units = User::query()->whereNotNull('department')->distinct()->pluck('department')->all();
        $places = array_values(array_unique(array_merge($colleges, $units)));

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'email', 'max:255',
                // Deleted accounts are excluded deliberately: one of them may
                // be parking this address, and that person signing up again
                // is how it gets handed over.
                Rule::unique('users', 'email')->whereNull('deleted_at'),
                function ($attribute, $value, $fail) {
                    $domain = strtolower((string) substr(strrchr((string) $value, '@') ?: '', 1));

                    if (! in_array($domain, ['my.cspc.edu.ph', 'cspc.edu.ph'], true)) {
                        $fail('Use a CSPC address: @my.cspc.edu.ph for a student, @cspc.edu.ph for staff.');
                    }
                },
            ],
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')],
            'department' => ['nullable', 'string', Rule::in($places)],
            'course' => ['nullable', 'string', Rule::in(User::allCourses())],
            'year' => ['nullable', 'integer', 'between:1,'.User::longestProgrammeYears()],
            'section_letter' => ['nullable', 'string', 'regex:/^[A-Za-z]$/'],
            'student_id' => ['nullable', 'string', 'max:50',
                Rule::unique('users', 'student_id')->whereNull('deleted_at')],
            'employee_id' => ['nullable', 'string', 'max:50',
                Rule::unique('users', 'employee_id')->whereNull('deleted_at')],
        ], [
            'email.unique' => 'An account already uses that address.',
            'student_id.unique' => 'Another account already has that student number.',
            'employee_id.unique' => 'Another account already has that staff number.',
            'section_letter.regex' => 'A class is a single letter, like A.',
        ]);

        $email = strtolower(trim($validated['email']));

        // A programme belongs to one college. Filed under the wrong one,
        // every college-scoped lookup disagrees with the row and the concern
        // reaches neither college's staff.
        // validate() returns only the keys that were sent, so every
        // optional field is read with a default. Reading one straight was
        // the first thing to break this form.
        $college = $validated['department'] ?? null;
        $course = $validated['course'] ?? null;

        // A programme belongs to one college.
        if ($course && isset(User::COURSES_BY_COLLEGE[$college])
            && ! in_array($course, User::COURSES_BY_COLLEGE[$college], true)) {
            return back()->withInput()->withErrors([
                'course' => $course.' is not offered by '.$college.'.',
            ]);
        }

        $year = $validated['year'] ?? null;
        $letter = $validated['section_letter'] ?? null;

        if (($year === null) !== ($letter === null)) {
            return back()->withInput()->withErrors([
                'year' => 'Give both a year and a class, or neither.',
            ]);
        }

        if ($year !== null && $course && $year > User::finalYearFor($course)) {
            return back()->withInput()->withErrors([
                'year' => $course.' runs for '.User::finalYearFor($course).' years.',
            ]);
        }

        // A deleted account may still be physically holding this address or
        // ID number. Validation looks past deleted rows; the UNIQUE indexes
        // on the columns do not, so without this the create fails on a
        // constraint and the administrator is shown an error about a row
        // nobody can see.
        //
        // Gathered BEFORE anything is released, because the numbers are what
        // identifies them, and released BEFORE the create, because the index
        // is checked on the way in.
        $studentId = ($validated['student_id'] ?? null) ?: null;
        $employeeId = ($validated['employee_id'] ?? null) ?: null;

        $husks = User::onlyTrashed()
            ->where(function ($q) use ($email, $studentId, $employeeId) {
                $q->where('email', $email)
                  ->when($studentId, fn ($w) => $w->orWhere('student_id', $studentId))
                  ->when($employeeId, fn ($w) => $w->orWhere('employee_id', $employeeId));
            })
            ->get();

        foreach ($husks as $husk) {
            AuthController::releaseIdentityOf($husk);

            $husk->forceFill(['student_id' => null, 'employee_id' => null])->saveQuietly();
        }

        $user = User::create([
            'name' => $validated['name'],
            'email' => $email,
            // No password sign-in exists; this only satisfies the column.
            'password' => Hash::make(Str::random(40)),
            'role_id' => $validated['role_id'],
            'department' => $college ?: null,
            'course' => $course ?: null,
            'section' => $year !== null ? $year.strtoupper($letter) : null,
            'student_id' => ($validated['student_id'] ?? null) ?: null,
            'employee_id' => ($validated['employee_id'] ?? null) ?: null,
            'status' => 'approved',
            // Google sign-in is the only door, and it verifies the address on
            // the way through. Nothing here can be taken as proof, so the
            // account waits dormant instead.
            'email_verified_at' => null,
        ]);

        // An ID number is issued to one person, so a deleted account that was
        // holding this one is them. What they filed moves onto the account
        // just made -- the same reunion the person gets by signing up again
        // and typing their own number in.
        $moved = $husks->isEmpty()
            ? 0
            : Concern::whereIn('user_id', $husks->pluck('id'))->update(['user_id' => $user->id]);

        $history = $moved > 0
            ? ' '.$moved.' '.Str::plural('concern', $moved).' they filed before '
                .($moved === 1 ? 'has' : 'have').' been put back on this account.'
            : '';

        return back()->with(
            'success',
            "{$user->name} has been added as ".optional($user->role)->name
                .'. The account is dormant until they sign in with CSPC Mail, which keeps these details.'
                .$history
        );
    }

    /**
     * Put a deleted account back.
     *
     * The counterpart to destroy(), for the administrator who notices their
     * own mistake rather than waiting for the person to sign in and find it.
     * Everything the account owns returns with it, because none of it ever
     * went anywhere.
     */
    public function restore(User $user)
    {
        $this->authorizeAdmin();

        if (! $user->trashed()) {
            return back()->with('success', "{$user->name}'s account is already active.");
        }

        $user->restore();

        // A deleted account parks its email address so the person can sign
        // up again on it, and a restored one needs it back or it cannot sign
        // in at all. Only if it is still free: once they have signed up
        // again, that newer account is the live one and this older row is a
        // record, not a way in.
        $prefix = AuthController::DELETED_EMAIL_PREFIX.$user->id.'-';
        $superseded = '';

        if (str_starts_with((string) $user->email, $prefix)) {
            $original = substr($user->email, strlen($prefix));

            if (User::where('email', $original)->whereKeyNot($user->id)->exists()) {
                $superseded = ' They have already signed up again since, so this older'
                    .' account keeps a parked address and cannot be signed in to.';
            } else {
                $user->forceFill(['email' => $original])->saveQuietly();
            }
        }

        $concerns = $user->submittedConcerns()->count();
        $filed = $concerns > 0
            ? ' The '.$concerns.' '.Str::plural('concern', $concerns).' they filed are back.'
            : '';

        return back()->with('success', "{$user->name}'s account has been restored.".$filed.$superseded);
    }

    /**
     * Erase a deleted account for good.
     *
     * This is the destruction that Delete used to perform without being
     * asked: the row goes, the foreign key cascades their concerns, and the
     * evidence they uploaded is swept from disk so it cannot outlive the
     * record of what it was. There is no undo, which is why it is a separate
     * decision made about an account that is already deleted.
     */
    public function forceDestroy(User $user)
    {
        $this->authorizeAdmin();
        $this->guardSystemAdmin($user);

        if ($user->id === Auth::id()) {
            abort(422, 'You cannot delete your own account.');
        }

        // Only ever reached deliberately: an account still in use is deleted
        // first, seen in the Deleted list, and erased from there.
        if (! $user->trashed()) {
            abort(422, 'Delete the account first. Erasing is for accounts already deleted.');
        }

        $name = $user->name;

        // Gathered BEFORE the delete: the cascade takes the concerns and
        // their attachment rows, and once those are gone nothing on the
        // system knows these files existed. Thirteen were found orphaned on
        // disk the first time this was overlooked.
        $files = DB::table('attachments')
            ->whereIn('concern_id', DB::table('concerns')->where('user_id', $user->id)->pluck('id'))
            ->pluck('stored_path');

        DB::transaction(function () use ($user) {
            Referral::where('referred_by', $user->id)
                ->orWhere('referred_to', $user->id)
                ->delete();

            $user->forceDelete();
        });

        // After the transaction, deliberately. A rolled-back delete can
        // restore a row, never a file: better to keep a file whose rows are
        // gone -- the purge command sweeps those -- than to destroy evidence
        // for a deletion that did not happen.
        $removed = 0;
        $disk = Storage::disk('local');

        foreach ($files as $storedPath) {
            if ($storedPath && $disk->exists($storedPath)) {
                $disk->delete($storedPath);
                $removed++;
            }
        }

        $evidence = $removed > 0
            ? " {$removed} uploaded ".Str::plural('file', $removed).' removed with them.'
            : '';

        return back()->with(
            'success',
            "{$name}'s account and submitted concerns have been permanently erased.".$evidence
        );
    }

    /**
     * Only Admins may manage accounts.
     */
    private function authorizeAdmin(): void
    {
        $user = Auth::user();

        // Any hat they wear, not only the one they are listed as: somebody
        // whose primary role is Faculty/Staff and who also covers Staff Admin
        // manages accounts as surely as anyone.
        if (! $user || ! $user->hasAnyRole(self::ADMIN_ROLES)) {
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
