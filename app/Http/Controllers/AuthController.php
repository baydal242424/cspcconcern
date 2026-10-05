<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Concern;
use App\Models\Notification;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Laravel\Socialite\Facades\Socialite;

/**
 * Every way in and out of the app.
 *
 * CSPC Mail (Google) is the ONLY way in -- for students and for staff alike.
 * There is no password form, no registration form, and no password-reset
 * flow. That is a deliberate simplification, not a missing feature:
 *
 *  - Nothing to guess, phish, reuse or leak. The app holds no password
 *    anybody can sign in with (accounts carry a random unusable hash purely
 *    to satisfy the column).
 *  - Every account is provably tied to a real CSPC mailbox, so nobody can
 *    file concerns under a classmate's identity.
 *  - Offboarding is automatic: CSPC disabling a Google account revokes
 *    access here at the same moment, with nothing to clean up by hand.
 *
 * A first CSPC Mail sign-in auto-provisions the account, with the DOMAIN
 * deciding the starting role (see DOMAIN_ROLES): my.cspc.edu.ph is a
 * student, cspc.edu.ph is an employee. Any other domain is turned away at
 * the callback. Students then complete their details on /complete-profile
 * before they can file anything; employees skip that, since college and
 * course are student fields.
 *
 * The domain cannot tell a dean from an instructor, so employees start on
 * the lowest staff role and an Admin promotes them. Signing in establishes
 * who you are, never what you are allowed to do.
 *
 * Sign-in runs through rejectIfNotApproved(), so a pending, rejected or
 * banned account can never get in.
 */
class AuthController extends Controller
{
    /**
     * Marks the address of a deleted account so it stops colliding.
     *
     * Kept readable rather than scrambled: an administrator looking at
     * the Deleted list should still be able to tell whose row it is.
     */
    public const DELETED_EMAIL_PREFIX = 'deleted-';

    /**
     * The CSPC domains that may sign in, mapped to the role a brand-new
     * account from that domain starts with.
     *
     * CSPC issues my.cspc.edu.ph to students and cspc.edu.ph to employees, so
     * the domain reliably answers "student or staff?" -- but it cannot answer
     * "which kind of staff?", because a dean, a counselor and an instructor
     * all share cspc.edu.ph. So an employee starts on the LOWEST staff role
     * and an Admin promotes them from there (/admin/users, or the user:role
     * command). Sign-in establishes identity; a human still decides authority.
     *
     * This applies only when the account is first created. An existing
     * account keeps whatever role it has, so a promotion is never undone by
     * signing in again.
     *
     * @var array<string, string>
     */
    private const DOMAIN_ROLES = [
        'my.cspc.edu.ph' => 'Student',
        'cspc.edu.ph' => 'Faculty/Staff',
    ];

    /**
     * Offices and units, offered beside the six colleges when a staff member
     * says where they work.
     *
     * A department is not a fixed list in this system -- it holds colleges and
     * units in the same column -- so this is the starting set a new employee
     * can pick from without typing. An admin can still set anything else on
     * the Manage Users page.
     *
     * @var list<string>
     */
    public const UNITS = [
        'Office of the President',
        'Academic Affairs',
        'Graduate School',
        'Student Registration and Records',
        'Guidance Office',
        'General Services Unit',
        'Health Services Unit',
        'Information and Communications Technology Unit',
        'Information and Alumni Affairs Unit',
        'Center for Gender and Development',
        'Center for Human Rights Education',
        'Legal Affairs Office',
    ];

    /**
     * Show the login form.
     */
    public function showLogin()
    {
        if (Auth::check()) {
            return redirect()->route('concerns.index');
        }

        return view('auth.login', [
            'demoAccounts' => $this->demoAccounts(),
            // Set by rejectIfNotApproved() when a graduated account just tried
            // to sign in. Re-read from the database rather than trusted from
            // the session, so an account reactivated in the meantime does not
            // still offer to ask.
            'reactivationCandidate' => User::whereKey(session('reactivation_candidate'))
                ->where('status', 'graduated')
                ->first(),
        ]);
    }

    /**
     * Whether sign-in-as-anyone is available.
     *
     * Two conditions, and the second is not configurable. DEMO_LOGIN_ENABLED
     * turns on a dropdown that signs a visitor in as any seeded account
     * without a password -- on the deployed app that would hand anyone the
     * Head of School's login, which reads every concern in the college.
     *
     * The environment variable defaults to false, so a deploy is safe by
     * omission. This makes it safe by construction: one stray value in a
     * hosting panel cannot switch it on in production, and nobody has to
     * remember to check.
     */
    private static function demoLoginEnabled(): bool
    {
        return (bool) config('auth.demo_login') && ! app()->isProduction();
    }

    /**
     * An account made for trying the system out, rather than a real person.
     *
     * Two prefixes, both deliberate and both visible in Manage Users: `demo.`
     * from the older seeders, `test.` from testing:accounts. Anyone can read
     * an address and tell which kind of account they are looking at, which is
     * the point -- routing cannot.
     */
    private static function isTestAccount(User $user): bool
    {
        return str_starts_with($user->email, 'demo.') || str_starts_with($user->email, 'test.');
    }

    /**
     * Accounts the demo dropdown may offer, grouped by role.
     *
     * Empty unless demo sign-in is switched on, and never includes anyone who
     * has signed in with Google. That single condition is what keeps this from
     * being an impersonation tool: a seeded row is a fixture nobody owns, but
     * the moment a real person signs in their google_id is set and they drop
     * out of this list permanently.
     */
    private function demoAccounts()
    {
        if (! self::demoLoginEnabled()) {
            return collect();
        }

        return User::query()
            ->whereNull('google_id')
            ->where('status', 'approved')
            ->whereHas('role')
            ->with('role')
            ->orderBy('name')
            ->get()
            // Every account sits in its own role's group. They were collected
            // into one "Test accounts" heading for a while, which put a test
            // Program Chair under a heading that did not say Program Chair --
            // so finding one meant reading the whole list instead of going
            // straight to the lane you wanted.
            ->groupBy(fn (User $u) => $u->role->name)
            ->sortKeys()
            // Inside a lane, the purpose-made accounts come first. They are
            // what somebody opening this dropdown is looking for, and by name
            // alone "TEST ..." sorts to the bottom, behind every real one.
            ->map(fn ($people) => $people->sortBy(fn (User $u) => [
                self::isTestAccount($u) ? 0 : 1,
                $u->name,
            ])->values());
    }

    /**
     * Sign in as a seeded account chosen from the dropdown.
     *
     * Every condition the dropdown was built from is re-checked here. The form
     * is a suggestion; this method is the authority, so a hand-crafted post
     * naming a real person's id gets the same refusal as a missing account.
     */
    public function demoLogin(Request $request)
    {
        // 404, not 403: when the feature is off there is nothing here to find.
        abort_unless(self::demoLoginEnabled(), 404);

        $validated = $request->validate([
            'user_id' => 'required|integer',
        ]);

        $user = User::whereKey($validated['user_id'])
            ->whereNull('google_id')
            ->where('status', 'approved')
            ->whereHas('role')
            ->with('role')
            ->first();

        if (! $user) {
            return redirect()->route('login')->withErrors([
                'email' => 'That demo account is not available. Accounts belonging to real people cannot be used here.',
            ]);
        }

        Auth::login($user);
        $request->session()->regenerate();

        // audit_logs requires a concern_id, so a sign-in cannot go there. The
        // application log still records who was assumed and from where, which
        // is what matters if this is ever left switched on by accident.
        Log::warning('Demo sign-in used', [
            'user_id' => $user->id,
            'email' => $user->email,
            'role' => $user->role->name,
            'ip_address' => $request->ip(),
        ]);

        return redirect()->route('concerns.index')
            ->with('success', 'Signed in as '.$user->name.' ('.$user->role->name.'). This is a demo account.');
    }

    /**
     * A staff member says where they work and what they do.
     *
     * College, programme and section are stored as given: they describe where
     * somebody works and grant nothing. Role is stored as a REQUEST.
     *
     * That split is the whole design. Role IS permission here --
     * Concern::scopeVisibleTo() reads nothing else -- so a self-assigned one
     * would let anybody holding a cspc.edu.ph address pick Guidance Counselor
     * and read every mental-health and harassment report in the college. The
     * domain proves employment; it cannot tell a dean from an instructor.
     *
     * So they keep Faculty/Staff, which receives only what is deliberately
     * referred to it, until an administrator grants what they asked for.
     */
    private function completeStaffProfile(Request $request, User $user): RedirectResponse
    {
        $this->reportStudentNumberClash($request, $user);

        $requestable = Role::whereIn('name', User::REQUESTABLE_ROLES)->pluck('id')->all();
        $departments = array_merge(array_keys(User::COURSES_BY_COLLEGE), self::UNITS);

        $validated = $request->validate([
            'requested_role_id' => ['required', Rule::in($requestable)],
            // Their staff number, if they have it to hand. Optional: a new
            // employee often does not know it yet on their first day, and a
            // required field there turned them away from the whole app. An
            // administrator can add it later from Manage Users.
            'employee_id' => ['nullable', 'string', 'max:50', self::uniqueAmongLiveAccounts('employee_id', $user)],
            'department' => ['required', 'string', Rule::in($departments)],
            // Only a Program Chair covers one programme. Validated against the
            // chosen college so a hand-posted form cannot file a Computer
            // Studies chair under BS Nursing.
            'course' => ['nullable', 'string', Rule::in(User::allCourses()),
                function ($attribute, $value, $fail) use ($request) {
                    $offered = User::COURSES_BY_COLLEGE[$request->input('department')] ?? [];

                    if (! in_array($value, $offered, true)) {
                        $fail('That program is not offered by the college you selected.');
                    }
                },
            ],
            // The class they say they advise, if any: a programme, a year and a
            // class letter, all three or none. Checked together below.
            'advises_course' => ['nullable', 'string', Rule::in(User::allCourses())],
            'advises_year' => ['nullable', 'integer', 'between:1,'.User::longestProgrammeYears()],
            'advises_letter' => ['nullable', 'string', 'regex:/^[A-Za-z]$/'],
        ], [
            'requested_role_id.required' => 'Please choose the role you are asking for.',
            'requested_role_id.in' => 'That role cannot be requested here. Ask an administrator directly.',
            'department.required' => 'Please choose your college or office.',
            'advises_letter.regex' => 'The class is a single letter, like A.',
        ]);

        // A suggestion, not an assignment. It is kept apart from the sections
        // table and from their programme, because either would start routing
        // a class's concerns to them on nobody's word but their own -- an
        // administrator confirms it from Manage Users with Add class.
        $advisesRequest = null;
        $advisesParts = array_filter([
            $validated['advises_course'] ?? null,
            $validated['advises_year'] ?? null,
            $validated['advises_letter'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        if ($advisesParts) {
            // Half a class names no class: "4" with no letter matches nothing
            // an administrator could confirm.
            if (count($advisesParts) < 3) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'advises_course' => 'To name the class you advise, choose its program, year and class — or leave all three empty.',
                ]);
            }

            $finalYear = User::finalYearFor($validated['advises_course']);

            if ((int) $validated['advises_year'] > $finalYear) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'advises_year' => "{$validated['advises_course']} runs for {$finalYear} years.",
                ]);
            }

            $advisesRequest = $validated['advises_course'].'|'.$validated['advises_year'].strtoupper($validated['advises_letter']);
        }

        // Before the save: the UNIQUE index on users.employee_id still counts
        // deleted rows even though validation no longer does, so the old
        // account has to let the number go first.
        $reclaimed = self::reclaimHistoryFor($user, 'employee_id', $validated['employee_id'] ?? null);

        $user->forceFill([
            'employee_id' => $validated['employee_id'] ?? null,
            'department' => $validated['department'],
            'course' => $validated['course'] ?? null,
            'advises_request' => $advisesRequest,
            'requested_role_id' => $validated['requested_role_id'],
            'role_requested_at' => now(),
        ])->save();

        $asked = Role::find($validated['requested_role_id']);

        // Tell the administrators there is something waiting. Without this the
        // request sits in the database until somebody happens to open Manage
        // Users, and a new instructor waits days for a role they need to do
        // the job they were hired for.
        $admins = User::whereHas('role', fn ($q) => $q->whereIn('name', ['System Admin', 'Staff Admin']))
            ->where('status', 'approved')
            ->get();

        foreach ($admins as $admin) {
            Notification::create([
                'user_id' => $admin->id,
                'type' => 'role_request',
                'title' => 'Role request',
                'message' => $user->name.' ('.$user->email.') set themselves up as '
                    .$validated['department'].' and is asking for the '
                    .optional($asked)->name.' role.',
                'is_read' => false,
            ]);
        }

        return redirect()->route('concerns.index')->with(
            'success',
            'Thanks — your details are saved. An administrator has to approve the '
            .optional($asked)->name.' role before you can act on concerns as one.'
            .$reclaimed
        );
    }

    /**
     * A graduated student asks an Admin to reopen their account.
     *
     * Reachable only straight after that person signed in with Google and was
     * turned away: rejectIfNotApproved() puts their id in the session, and
     * this reads it from there. Nothing is taken from the form, so the button
     * cannot be pointed at somebody else's account, and it cannot be used to
     * find out which addresses exist.
     *
     * The alternative was "ask the admin" with no way to ask — the student is
     * locked out, so they cannot use the system to reach anybody, and an
     * irregular student with a real concern would simply give up.
     */
    public function requestReactivation(Request $request)
    {
        $user = User::whereKey($request->session()->get('reactivation_candidate'))
            ->where('status', 'graduated')
            ->first();

        if (! $user) {
            return redirect()->route('login')->withErrors([
                'email' => 'Sign in first, so we know whose account to ask about.',
            ]);
        }

        $admins = User::whereHas('role', fn ($q) => $q->where('name', 'System Admin'))
            ->where('status', 'approved')
            ->get();

        if ($admins->isEmpty()) {
            Log::warning('Reactivation requested with no Admin to receive it', [
                'user_id' => $user->id,
                'email' => $user->email,
            ]);

            return redirect()->route('login')->withErrors([
                'email' => 'There is no administrator to receive the request right now. Please contact your college office.',
            ]);
        }

        // One open request per student. Pressing the button five times must
        // not put five identical rows in every Admin's bell, which would bury
        // the other four students asking the same thing.
        $alreadyAsked = Notification::where('type', 'reactivation_request')
            ->where('message', 'like', '%('.$user->email.')%')
            ->where('is_read', false)
            ->exists();

        if ($alreadyAsked) {
            return redirect()->route('login')
                ->with('success', 'Your request is already with the admin. You will be able to sign in once they reopen your account.');
        }

        foreach ($admins as $admin) {
            Notification::create([
                'user_id' => $admin->id,
                'type' => 'reactivation_request',
                'title' => 'Reactivation requested',
                'message' => $user->name.' ('.$user->email.') says they are still enrolled in '
                    .($user->course ?: 'their program').' '.($user->section ?: '')
                    .' and is asking for their account to be reopened.',
                'is_read' => false,
            ]);
        }

        return redirect()->route('login')
            ->with('success', 'Your request has been sent to the admin. You will be able to sign in once they reopen your account.');
    }

    /**
     * Handle logout.
     */
    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('login')->with('success', 'Logged out successfully!');
    }

    /**
     * Redirect to Google's OAuth consent screen.
     */
    public function redirectToGoogle()
    {
        // Without prompt=select_account Google silently reuses the browser's
        // only signed-in session, so a student on a shared machine gets logged
        // in as whoever used it last with no chance to pick their CSPC address.
        return Socialite::driver('google')
            ->with(['prompt' => 'select_account'])
            ->redirect();
    }

    /**
     * Handle the callback from Google. Accounts are matched by email; a
     * first-time sign-in from an official CSPC address is auto-provisioned
     * as an approved Student on the spot, so no manual registration step
     * is needed.
     */
    public function handleGoogleCallback()
    {
        // A cancelled consent screen, expired state token, or Google outage
        // all surface here as exceptions -- turn them into a friendly retry
        // message instead of a 500 page.
        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (\Throwable $e) {
            // Log the real failure before showing the generic message --
            // otherwise problems like a missing CA bundle (cURL SSL failure
            // during the token exchange) are impossible to diagnose.
            \Illuminate\Support\Facades\Log::error('Google OAuth callback failed', [
                'class' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            return redirect()->route('login')->withErrors([
                'email' => 'Google sign-in was cancelled or could not be completed. Please try again.',
            ]);
        }

        $email = $googleUser->getEmail();

        // Deleted accounts are looked up deliberately, because one of them
        // may be holding this address.
        $user = User::withTrashed()->where('email', $email)->first();

        if ($user && $user->trashed()) {
            // Not restored, and not refused either. The deleted account is
            // finished; the person is not. It gives up the address so the
            // sign-in below can build them a new one, and keeps everything
            // they filed until their ID number claims it back.
            self::releaseIdentityOf($user);

            $user = null;
        }

        if (! $user) {
            $domain = strtolower(substr(strrchr($email, '@'), 1));

            if (! array_key_exists($domain, self::DOMAIN_ROLES)) {
                return redirect()->route('login')->withErrors([
                    'email' => 'Please sign in with your official CSPC email address (@my.cspc.edu.ph or @cspc.edu.ph).',
                ]);
            }

            // The domain decides the starting role: my.cspc.edu.ph is a
            // student, cspc.edu.ph is an employee. See DOMAIN_ROLES.
            $roleName = self::DOMAIN_ROLES[$domain];

            $user = User::create([
                'name' => $googleUser->getName() ?: $googleUser->getNickname() ?: $email,
                'email' => $email,
                'password' => Hash::make(str()->random(40)),
                'role_id' => Role::where('name', $roleName)->value('id'),
                'google_id' => $googleUser->getId(),
                'status' => 'approved',
                // Signing in through Google IS proof this person controls the
                // mailbox, so the address is confirmed on the spot.
                'email_verified_at' => now(),
            ]);
        }

        if (! $user->google_id) {
            $user->forceFill(['google_id' => $googleUser->getId()])->save();
        }

        // Same reasoning for an older account (e.g. a seeded staff member)
        // signing in through Google for the first time.
        if (! $user->hasVerifiedEmail()) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        Auth::login($user, true);

        if ($blocked = $this->rejectIfNotApproved($user)) {
            return $blocked;
        }

        request()->session()->regenerate();

        // Back to the page that asked them to sign in. Somebody opening
        // "concern #8" from an email was sent to sign in, and then landed on
        // their list with no idea which of the rows the email meant -- the
        // link worked, and still did not take them anywhere.
        return redirect()->intended(route('concerns.index'))->with('success', 'Logged in successfully!');
    }

    /**
     * Show the one-time "tell us your college and course" form. Only students
     * who skipped registration (CSPC Mail sign-in) ever land here.
     */
    public function showCompleteProfile()
    {
        /** @var User $user */
        $user = Auth::user();

        // Staff get their own form: they pick a role and an office, not a
        // student number and a section.
        if ($user->needsStaffProfile()) {
            return view('auth.complete-staff-profile', [
                'collegeCourses' => User::COURSES_BY_COLLEGE,
                'units' => self::UNITS,
                'roles' => Role::whereIn('name', User::REQUESTABLE_ROLES)
                    ->orderBy('name')
                    ->get(),
            ]);
        }

        if (! $user->needsProfileCompletion()) {
            return redirect()->route('concerns.index');
        }

        return view('auth.complete-profile', ['collegeCourses' => User::COURSES_BY_COLLEGE]);
    }

    /**
     * Save the missing student details. Same rules as registration, so a
     * Google-provisioned account ends up indistinguishable from a
     * self-registered one.
     */
    /**
     * Record that this person has read and agreed to the policy.
     *
     * The version is stored with the date. A policy that gets rewritten is a
     * different promise -- the September rewrite dropped an offer of anonymity
     * the system had stopped keeping -- so an agreement to the old wording is
     * not an agreement to the new one, and everybody is asked again.
     *
     * A student is sent straight to the filing form afterwards, because that
     * is what they signed in to do and they have just read what happens to
     * what they write. Staff go to their queue.
     */
    public function acceptPolicy(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $user->forceFill([
            'policy_accepted_at' => now(),
            'policy_version' => User::POLICY_VERSION,
        ])->save();

        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'policy_accepted',
            'description' => 'Accepted the privacy policy, version '.User::POLICY_VERSION,
            'ip_address' => $request->ip(),
        ]);

        $isStudent = optional($user->role)->name === 'Student';

        return redirect()
            ->route($isStudent ? 'concerns.create' : 'concerns.index')
            ->with('success', $isStudent
                ? 'Thank you. You can file your concern below.'
                : 'Thank you for reading the policy.');
    }

    /**
     * Tell the administrators when two accounts reach for one student number.
     *
     * Also covers the staff number, since the same reasoning applies: the
     * number is what the college's own records key on, and two accounts
     * carrying one stops it identifying anybody.
     *
     * One open notice per clashing pair. A student pressing submit five times
     * must not put five identical rows in every administrator's bell, which
     * would bury the other four students stuck the same way.
     */
    private function reportStudentNumberClash(Request $request, User $user): void
    {
        foreach (['student_id' => 'student number', 'employee_id' => 'staff number'] as $column => $label) {
            $submitted = trim((string) $request->input($column));

            if ($submitted === '') {
                continue;
            }

            $holder = User::where($column, $submitted)->where('id', '!=', $user->id)->first();

            if (! $holder) {
                continue;
            }

            $marker = '['.$column.':'.$submitted.']';

            $alreadyRaised = Notification::where('type', 'id_number_clash')
                ->where('message', 'like', '%'.$marker.'%')
                ->where('is_read', false)
                ->exists();

            if ($alreadyRaised) {
                continue;
            }

            $admins = User::whereHas('role', fn ($q) => $q->whereIn('name', ['System Admin', 'Staff Admin']))
                ->where('status', 'approved')
                ->get();

            if ($admins->isEmpty()) {
                Log::warning('Duplicate ID number with no administrator to tell', [
                    'column' => $column,
                    'value' => $submitted,
                    'attempted_by' => $user->id,
                    'already_held_by' => $holder->id,
                ]);

                continue;
            }

            foreach ($admins as $admin) {
                Notification::create([
                    'user_id' => $admin->id,
                    'type' => 'id_number_clash',
                    'title' => 'Two accounts, one '.$label,
                    'message' => $user->name.' ('.$user->email.') tried to register the '.$label.' '
                        .$submitted.', which already belongs to '.$holder->name.' ('.$holder->email.'). '
                        .'One of the two is wrong, and only you can see both. '.$marker,
                    'is_read' => false,
                ]);
            }
        }
    }

    public function completeProfile(Request $request)
    {
        /** @var User $user */
        $user = Auth::user();

        if ($user->isEmployee()) {
            return $this->completeStaffProfile($request, $user);
        }

        // A clash is told to the administrators, not only to the student.
        //
        // The student is stopped either way -- a student number identifies one
        // student, so the second account to claim it is refused. But the
        // student cannot fix it: either they mistyped, or somebody else did,
        // and only an administrator can see both accounts and say which. Left
        // to the error message alone, the student is stuck at a form with no
        // way forward and nobody knows.
        $this->reportStudentNumberClash($request, $user);

        $validated = $request->validate([
            'student_id' => ['required', 'string', 'max:50', self::uniqueAmongLiveAccounts('student_id', $user)],
            'department' => ['required', 'string', Rule::in(array_keys(User::COURSES_BY_COLLEGE))],
            'course' => ['required', 'string', Rule::in(User::allCourses()),
                function ($attribute, $value, $fail) use ($request) {
                    $offered = User::COURSES_BY_COLLEGE[$request->input('department')] ?? [];
                    if (! in_array($value, $offered, true)) {
                        $fail('The selected course is not offered by that college.');
                    }
                },
            ],
            // Section IS collected now, reversing an earlier decision. The
            // reasoning for leaving it out was that nothing routed on it and
            // it goes stale every year -- the first half stopped being true
            // when Academic concerns started going to a student's class
            // adviser, who is attached to a section rather than a college.
            //
            // Optional, and staleness is survivable: a concern from a student
            // with no section, or a section nobody advises any more, falls
            // back to an adviser in their college, then an instructor, then up
            // the escalation chain. It reaches somebody either way; the
            // section only decides whether it reaches the RIGHT somebody.
            // Required, reversing the decision above it. Leaving it optional
            // meant a student could finish signing up with no section, and a
            // section is what identifies their class adviser -- so their
            // Academic concerns fell to college-level routing and the filing
            // form could not offer their adviser as the subject of a
            // complaint. The form simply showed one fewer checkbox, with
            // nothing to say why.
            // Two dropdowns rather than one box. A free-text field took "3A",
            // "3-A", "III-A" and "BSIT 3A" -- all of which a student would
            // call correct, and only the first of which matches the shape
            // Section::adviserFor() looks up. A concern from any of the others
            // found no adviser and quietly fell back to the college.
            'year' => ['required', 'integer', 'between:1,'.User::longestProgrammeYears()],
            'section_letter' => ['required', 'string', 'regex:/^[A-Za-z]$/'],
        ], [
            'year.required' => 'Choose your year level.',
            'section_letter.required' => 'Choose your section.',
            'section_letter.regex' => 'A section is a single letter, like A.',
        ]);

        // A year the programme does not run to is a class that does not exist,
        // and a student filed into one has no adviser to reach.
        $finalYear = User::finalYearFor($validated['course']);

        if ((int) $validated['year'] > $finalYear) {
            return back()->withInput()->withErrors([
                'year' => $validated['course'].' runs for '.$finalYear.' years.',
            ]);
        }

        $validated['section'] = $validated['year'].strtoupper($validated['section_letter']);
        unset($validated['year'], $validated['section_letter']);

        // Before the save, not after. Validation no longer counts deleted
        // accounts, but the UNIQUE index on users.student_id still does --
        // the number is physically occupied until the old row lets go of it,
        // and writing it here would fail on the constraint.
        $reclaimed = self::reclaimHistoryFor($user, 'student_id', $validated['student_id']);

        $user->update($validated);

        return redirect()->route('concerns.index')->with(
            'success',
            'Thanks! Your student details are saved.'.$reclaimed
        );
    }

    /**
     * Unique among accounts in use, rather than among all rows.
     *
     * Rule::unique queries the table and knows nothing about soft deletes, so
     * a deleted account went on occupying its ID number. A student coming
     * back and typing their own number was told it had already been taken --
     * by an account they could not see and nobody could release. Deleting
     * somebody therefore locked their student number away for good, which is
     * the opposite of what a recoverable deletion is for.
     */
    public static function uniqueAmongLiveAccounts(string $column, User $user)
    {
        return Rule::unique('users', $column)
            ->ignore($user->id)
            ->whereNull('deleted_at');
    }

    /**
     * A deleted account stops holding the things only one account may hold.
     *
     * Email addresses are unique, so the deleted row would otherwise keep
     * the person out of the system for good -- there is no second CSPC
     * address to sign up with. The address is parked under a prefix that
     * cannot collide, rather than cleared, so the row still says plainly
     * whose it was when an administrator reads it.
     *
     * The ID number is left alone here. That one is released at the moment
     * it is claimed, by reclaimHistoryFor(), which is also when the concerns
     * attached to it move across.
     */
    public static function releaseIdentityOf(User $deleted): void
    {
        $parked = self::DELETED_EMAIL_PREFIX.$deleted->id.'-'.$deleted->email;

        $deleted->forceFill([
            'email' => $parked,
            // Released too: the new account takes the same Google subject id,
            // and two rows claiming one identity is a lookup waiting to go
            // wrong.
            'google_id' => null,
        ])->saveQuietly();

        Log::info('Deleted account released its address for a fresh sign-up', [
            'user_id' => $deleted->id,
        ]);
    }

    /**
     * Give a returning person back what they filed before.
     *
     * The ordinary way back is the address: signing in with the same Google
     * account restores the deleted row and everything on it, and nothing here
     * is needed. This covers the other way round -- a new account, a new
     * address, and the same ID number typed into the profile form.
     *
     * An ID number is issued to one person, so a deleted account holding this
     * one is that same person. Their concerns move across to the account they
     * are actually signed in to, and the old row gives up the number so it
     * cannot block anybody again. The row itself stays: the audit trail
     * points at it, and that history should not be rewritten.
     *
     * @return string  a sentence for the person, or '' when nothing was found
     */
    public static function reclaimHistoryFor(User $user, string $column, ?string $number): string
    {
        if (! $number) {
            return '';
        }

        $previous = User::onlyTrashed()
            ->where($column, $number)
            ->where('id', '!=', $user->id)
            ->get();

        if ($previous->isEmpty()) {
            return '';
        }

        $moved = 0;

        foreach ($previous as $old) {
            $moved += Concern::where('user_id', $old->id)->update(['user_id' => $user->id]);

            // The number is released, so the husk cannot refuse it to anyone
            // later -- including this person, if they ever do it again.
            $old->forceFill([$column => null])->saveQuietly();
        }

        if ($moved === 0) {
            return '';
        }

        Log::info('Concerns reclaimed by ID number after a deletion', [
            'user_id' => $user->id,
            'column' => $column,
            'concerns' => $moved,
        ]);

        return ' We also found '.$moved.' '.Str::plural('concern', $moved)
            .' you filed before, and put '.($moved === 1 ? 'it' : 'them').' back on your account.';
    }

    /**
     * If the account isn't approved yet, log it back out and bounce to the
     * login page with an explanatory error. Returns null when the account is fine.
     */
    private function rejectIfNotApproved(User $user): ?RedirectResponse
    {
        if ($user->status === 'approved') {
            return null;
        }

        Auth::logout();

        // A graduated account may be an irregular student who is still
        // enrolled, so the login page offers them a button to ask an Admin.
        //
        // The id goes in the SESSION, never in the form. The only way it gets
        // here is a completed Google sign-in as that person, so the request
        // that follows cannot be forged for somebody else's address, and the
        // button cannot be used to probe which accounts exist.
        if ($user->status === 'graduated') {
            session(['reactivation_candidate' => $user->id]);
        }

        $message = match ($user->status) {
            'pending' => 'Your account is pending admin approval. Please check back later.',
            'banned' => 'Your account has been banned. Contact the admin for details.',
            // Closed by the start-of-year promotion, which marks every
            // final-year account graduated. Nothing here distinguishes a
            // graduate from an irregular student still finishing subjects, so
            // the message has to tell the second kind how to get back in
            // rather than reading as a dead end. The login page turns this
            // into a button -- see the session key set below.
            'graduated' => 'Your account was closed at the end of the school year. '
                .'If you are still enrolled, you can ask the admin to reactivate it.',
            default => 'Your account access was not approved. Contact the admin for details.',
        };

        return redirect()->route('login')->withErrors(['email' => $message]);
    }

}
