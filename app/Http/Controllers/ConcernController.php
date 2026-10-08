<?php

namespace App\Http\Controllers;

use App\Models\Concern;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Attachment;
use App\Models\Feedback;
use App\Services\ConcernNotificationService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;

/**
 * The heart of the system. A concern's whole lifecycle is handled here:
 * submission (anonymity, evidence uploads, the "about a staff member"
 * conflict-of-interest flag), auto-routing by category, staff triage and
 * referrals, the Head of School's logged identity reveal, and secure
 * evidence downloads.
 *
 * If you add an action to this controller: every read and write goes
 * through canViewConcern() -> Concern::scopeVisibleTo(), so gate yours the
 * same way. Every state change also writes an AuditLog row -- that is what
 * feeds the Activity Timeline on the show page.
 */
class ConcernController extends Controller
{
    /**
     * Staff-type roles a concern can be "about" (conflict-of-interest
     * subjects). Used both to build the picker and to validate submissions.
     */
    private const STAFF_ROLES = [
        'Vice President for Academic Affairs',
        'Adviser',
        'Instructor',
        'Faculty/Staff',
        'Program Chair',
        'Dean',
        'Guidance Counselor',
        'System Admin',
        'Staff Admin',
        'Head of School',
        // Every role a concern can be filed ABOUT must be listed here, not
        // just the ones that HANDLE concerns. These three were added as
        // routing destinations and left out of this list, which meant a
        // student reporting the Administration, General Services or GAD was told
        // "The selected person is not a staff member" -- so the conflict-of-
        // interest flag never got set, and scopeVisibleTo() then handed that
        // office its own complaint. Keep this in step with the roles in
        // User::EMPLOYEE_ROLES.
        'Gender and Development',
        'Legal Affairs',
        'General Services',
    ];


    /**
     * Which ROLE handles each category. Step one of routeConcern(); step two
     * picks which person in that role.
     *
     * A constant rather than a local array because the filing form tells the
     * student who will read their concern before they write it, and that
     * promise has now drifted twice -- most recently the four adviser
     * categories went on saying "an instructor in your college" after routing
     * had moved to the class adviser a tier above. CategoryRoutingHelperTest
     * reads this and the form's routingByCategory map and fails when they
     * disagree.
     */
    public const CATEGORY_ROUTING = [
        // Not 'Instructor'. Academic concerns reach the student's own class
        // adviser first; routeConcern() falls back to an instructor of the
        // college only where no adviser holds that section.
        'Academic' => 'Adviser',
        'Mental Health' => 'Guidance Counselor',
        'Personal' => 'Guidance Counselor',
        'Bullying' => 'Guidance Counselor',
        'Harassment' => 'Guidance Counselor',
        // Enrolment, records, ID, clearance, fees. This briefly went to a
        // Registrar role of its own; that role has been removed and these
        // come back to Admin, who triage and refer on to whichever office
        // owns the request. Worth knowing when reading complaints that this
        // office cannot resolve much itself -- a high referral rate here is
        // the system working, not failing.
        // A fault in the system goes to the people who run the system. It
        // reached the Administrative Office while this category meant
        // enrolment, records and fees; it now means the website is broken,
        // which that office can do nothing about.
        'Administrative' => 'System Admin',
        // Facilities/equipment problems (a dead lab PC, no water in the CR, a
        // broken aircon) have no human subject and no academic content. They
        // go to the General Services Unit, which per cspc.edu.ph performs
        // "routine maintenance on all the buildings, grounds, facilities and
        // other equipment" and is staffed for preventive maintenance, the
        // electrical system, and air-conditioning and water systems.
        //
        // This used to be 'Admin'. That was wrong twice over: Admin has no
        // maintenance function, and with the demo accounts removed the only
        // Admins left are the system's own administrators -- so a broken
        // computer was landing on the student who built the app.
        //
        // Computer faults are strictly ICTRaM's (the ICT Unit's repair arm),
        // not GSU's. They still come here on purpose: GSU is the office
        // students already report a broken anything to, and one real
        // destination beats making a student decide which of two maintenance
        // offices owns their problem.
        'Facilities' => 'General Services',
        'Equipment' => 'General Services',
        // Not the class adviser. An injury that has already happened and
        // a hazard that has not hurt anybody yet are not teaching
        // matters: the first needs somebody trained to sit with the
        // student, the second needs passing to whoever can make the
        // place safe. Guidance is the office that does both and
        // refers onward.
        'Physical' => 'Guidance Counselor',
        'Safety' => 'Guidance Counselor',
        'Others' => 'Adviser',
    ];

    /**
     * The offices a staff member may hand a concern on to. Single source of
     * truth: update()'s validation, the "Refer to" dropdown and the people
     * picker all read this list, so a destination can never be offered in the
     * UI without being accepted by the server (or the reverse).
     */
    public const REFERRAL_ROLES = [
        'Adviser',
        'Vice President for Academic Affairs',
        'Instructor',
        'Guidance Counselor',
        'Program Chair',
        // Staff Admin is no longer offered as a destination. With the
        // Administrative category now meaning "this website is broken" and
        // going to the System Admin, the Administrative Office owns no queue,
        // and the two sat side by side in the dropdown reading as the same
        // thing. It remains a ROLE -- people hold it, it can be named in a
        // concern, and it is still the last resort in routeConcern() so that
        // nothing is ever left unassigned.
        'System Admin',
        'Dean',
        'Faculty/Staff',
        'Gender and Development',
        'Legal Affairs',
        'General Services',
    ];

    /**
     * How each destination is written in the "Refer to" dropdown. Keys must
     * match REFERRAL_ROLES exactly -- the view iterates this, so a destination
     * added above without a label here would simply not be offered.
     */
    public const REFERRAL_ROLE_LABELS = [
        'Guidance Counselor'     => 'Guidance Counselor',
        'Program Chair'          => 'Program Chair (one program)',
        'System Admin'           => 'System Admin (this website)',
        'Vice President for Academic Affairs' => 'VPAA (above the Administration)',
        'Dean'                   => 'Dean (whole college)',
        'Adviser'                => 'Adviser (a college)',
        'Instructor'             => 'Instructor (teaching staff)',
        'Faculty/Staff'          => 'Faculty/Staff (offices & units)',
        'Gender and Development' => 'Gender and Development (GAD)',
        'Legal Affairs'          => 'Legal Affairs Office (legal counsel)',
        'General Services'       => 'General Services (Facilities)',
    ];

    /**
     * Who gets told what when a concern moves. Laravel resolves this from the
     * container automatically, so tests can swap in a fake to assert on the
     * notifications without sending mail.
     */
    public function __construct(
        private ConcernNotificationService $notifications,
    ) {
    }

    /**
     * Display a listing of concerns for the current user or department.
     */
    public function index(Request $request)
    {
        /** @var User $user */
        $user = Auth::user();

        if (! $user->role) {
            abort(403, 'Your account has no role assigned. Please contact an administrator.');
        }

        // By default the active list hides resolved concerns so it isn't
        // clogged. A "Show resolved" toggle (?show_resolved=1) brings them back.
        $showResolved = $request->boolean('show_resolved');

        // Filters. Every one of these is read from the query string and
        // whitelisted against the model's own constants: an unknown value is
        // dropped rather than passed to the query, so a hand-edited URL cannot
        // filter on a column that does not exist or error the page.
        //
        // They are applied AFTER visibleTo(), which is the whole safety
        // property -- a filter can only ever narrow what a role may already
        // see, never reach past it. "Show me Harassment" from an Instructor
        // returns their own visible rows, not the Guidance Office's.
        $filters = [
            'category' => in_array($request->query('category'), Concern::CATEGORIES, true)
                ? $request->query('category')
                : null,
            'status' => array_key_exists($request->query('status'), Concern::STATUS_LABELS)
                ? $request->query('status')
                : null,
            'urgency' => in_array($request->query('urgency'), ['Low', 'Medium', 'High', 'Critical'], true)
                ? $request->query('urgency')
                : null,
            'from' => $this->parseFilterDate($request->query('from')),
            'to' => $this->parseFilterDate($request->query('to')),
            'q' => trim((string) $request->query('q')) !== '' ? trim((string) $request->query('q')) : null,
            'sort' => $request->query('sort') === 'oldest' ? 'oldest' : 'newest',
        ];

        // An explicit status filter decides for itself. "Show resolved" only
        // chooses which pile is on screen by default, and picking a status by
        // name is a more specific request than either pile.
        $statusChosen = $filters['status'] !== null;

        $concerns = Concern::visibleTo($user)
            ->when($showResolved && ! $statusChosen, function ($q) {
                // The finished pile, on its own. This used to ADD the
                // finished ones to the open ones, which answered a question
                // nobody asks: "show me everything, mixed". Somebody looking
                // for a case they remember settling last term had to read
                // past every live one to find it.
                $q->whereIn('status', Concern::TERMINAL_STATUSES);
            })
            ->when(! $showResolved && ! $statusChosen, function ($q) {
                // Hides finished cases of BOTH kinds -- resolved and closed
                // without action. A closed concern is just as done as a
                // resolved one, so leaving it in the active list would keep
                // dead cases in front of staff forever.
                $q->whereNotIn('status', Concern::TERMINAL_STATUSES);
            })
            ->when($filters['category'], fn ($q, $category) => $q->where('category', $category))
            ->when($filters['status'], fn ($q, $status) => $q->where('status', $status))
            ->when($filters['urgency'], fn ($q, $urgency) => $q->where('urgency', $urgency))
            ->when($filters['from'], fn ($q, $from) => $q->where('created_at', '>=', $from->startOfDay()))
            ->when($filters['to'], fn ($q, $to) => $q->where('created_at', '<=', $to->endOfDay()))
            ->when($filters['q'], function ($q, $term) {
                // A concern number, or words from the description. Grouped so
                // the OR cannot escape the visibility and filter clauses
                // around it -- ungrouped, "or id = 4" would have returned
                // concern #4 to anybody who typed it.
                $q->where(function ($sub) use ($term) {
                    $sub->where(function ($words) use ($term) {
                        // Every word must appear, anywhere and in any order.
                        // Matching the typed text as one string found a
                        // concern only where the words sat together in that
                        // exact order -- fine for a reference number, useless
                        // for prose. Somebody who filed a letter-length
                        // account remembers two or three words from it a
                        // fortnight later, not a contiguous phrase.
                        //
                        // Capped, because the box accepts a paste: the first
                        // few words of a letter identify it, and three
                        // hundred LIKEs would not find it any better.
                        foreach (array_slice(self::searchWords($term), 0, 10) as $word) {
                            // ESCAPE spelled out, because the drivers disagree:
                            // MySQL treats a backslash as an escape inside LIKE
                            // by default and SQLite treats it as an ordinary
                            // character, so the same escaped term matched in
                            // production and missed under test. '!' needs no
                            // escaping of its own in either dialect.
                            $words->whereRaw("description LIKE ? ESCAPE '!'", ['%'.$word.'%']);
                        }
                    });

                    if (is_numeric($digits = ltrim($term, '#'))) {
                        $sub->orWhere('id', (int) $digits);
                    }
                });
            })
            ->with('user', 'assignedUser')
            ->orderBy('created_at', $filters['sort'] === 'oldest' ? 'asc' : 'desc')
            ->paginate(10)
            ->withQueryString();

        $activeFilterCount = count(array_filter([
            $filters['category'], $filters['status'], $filters['urgency'],
            $filters['from'], $filters['to'], $filters['q'],
        ]));

        return view('concerns.index', compact('concerns', 'showResolved', 'filters', 'activeFilterCount'));
    }

    /**
     * A date from the filter bar, or null.
     *
     * Anything unparseable is dropped rather than thrown: the date inputs are
     * <input type="date">, but a typed or shared URL can carry anything, and a
     * concern list is not the place to show a validation error over a query
     * string the reader did not write.
     */
    private function parseFilterDate(?string $value): ?\Illuminate\Support\Carbon
    {
        if (blank($value)) {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse($value);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Show the form for creating a new concern.
     */
    public function create()
    {
        // Staff-type users the student can name as the subject of a
        // conflict-of-interest concern (so it is routed away from them).
        // Split into two pickers: students looking for "my teacher" should not
        // have to scan past deans, counsellors and admins to find them.
        $staffMembers = User::with('role')->whereHas('role', function ($q) {
            $q->whereIn('name', self::STAFF_ROLES);
            // course comes along for Program Chairs, whose programme is what
            // tells four chairs of one college apart.
        })->orderBy('name')->get(['id', 'name', 'department', 'course', 'role_id']);

        // Instructors are dropped rather than offered. The form used to carry
        // a picker of every instructor so a student could name the teacher a
        // concern was about; it is gone, and this is what keeps those names
        // out of the staff picker below, which is for deans, program chairs,
        // counselors and offices.
        //
        // The split reads 'Instructor' and not 'Faculty/Staff': when that role
        // was divided in two the two lists silently swapped, filling the
        // teacher picker with unit heads -- ICT, Records, Health Services --
        // while the actual teachers fell into the staff list. Nothing errored;
        // the names were simply wrong.
        $otherStaff = $staffMembers->reject(
            fn (User $u) => optional($u->role)->name === 'Instructor'
        );

        // What counts as a college, for "show me my own college's people".
        // COURSES_BY_COLLEGE names the six that enrol undergraduates; the
        // Graduate School has a dean and no entry there, and was reaching
        // every undergraduate's picker as though it were a central office.
        // Anywhere a dean sits is a college.
        $colleges = array_unique(array_merge(
            array_keys(User::COURSES_BY_COLLEGE),
            User::whereHas('role', fn ($q) => $q->where('name', 'Dean'))
                ->whereNotNull('department')
                ->distinct()
                ->pluck('department')
                ->all()
        ));

        // The student's own class adviser, offered first and by name.
        //
        // Advising is not a role, so the adviser is whoever holds the section
        // -- and 14 of the 105 section assignments are held by a Program
        // Chair, a Dean or Faculty/Staff, none of whom appear in a picker
        // built from the Instructor role. Those students had no way to name
        // their adviser as the subject of a concern.
        //
        // It matters more here than anywhere else in this form: Academic,
        // Physical, Safety and Others route to the class adviser FIRST, so a
        // concern about the adviser that fails to name them is handed
        // straight to the person it is about. routeConcern() steps past an
        // adviser who is the named subject -- but only if the student could
        // name them.
        $adviser = \App\Models\Section::adviserFor(auth()->user()->course, auth()->user()->section);

        if ($adviser && $adviser->id === auth()->id()) {
            $adviser = null;
        }

        // The student has a section but nobody is recorded as advising it.
        // Distinguishing this from "no section" is the point: the row simply
        // vanished, and a student comparing their form with a classmate's had
        // no way to tell whether they had filled something in wrongly or the
        // college had not published that section's adviser. Most sections are
        // in exactly this state -- BSIS has 1A and 1B on record and nothing
        // above first year -- so silence here is the common case, not the edge
        // one.
        $adviserUnknown = ! $adviser && filled(auth()->user()->section);

        return view('concerns.create', [
            // Grouped by college so a long list stays navigable. Instructors
            // from every college are offered, not just the student's own --
            // general-education subjects are taught across colleges.
            'adviser' => $adviser,
            'adviserUnknown' => $adviserUnknown,

            // Grouped by office for the same reason, and for one more: this
            // list was a flat "Name -- Role", and "Faculty/Staff" names no
            // office at all. It covers the ICT Unit, Records, Health Services
            // and half a dozen colleges, so a student reporting a person could
            // not tell which of them they were pointing at. Two rows read
            // identically -- the same lawyer heads Human Rights Education and
            // the Legal Affairs Office under two accounts, and the only thing
            // separating them on screen was a role name.
            // Their own college's people, plus every central office.
            //
            // A BSIS student has no business naming the dean of Health
            // Sciences or a chair from Engineering: those people cannot teach
            // them, cannot handle their concern, and scrolling past them to
            // reach their own college was the whole list's worth of noise.
            // Offices that serve the whole school -- Guidance, Registrar,
            // Gender and Development, the ICT Unit, the administration -- are
            // not a college and stay for everybody, because any student may
            // need to report one.
            'otherStaffByOffice' => $otherStaff
                ->reject(fn (User $u) => $adviser && $u->id === $adviser->id)
                // The people who run the system are not part of anybody's
                // concern. A System Admin administers accounts and roles --
                // naming one routes a case away from a person who was never
                // going to handle it, and puts a student's complaint in front
                // of somebody with no standing in the matter.
                ->reject(fn (User $u) => optional($u->role)->name === 'System Admin')
                // One chair chairs one programme. A BS Information Systems
                // student has exactly one, and the other three in the college
                // hold no authority over their programme or the people in it
                // -- they are not alternatives, they are mistakes one click
                // away. Chairs with no programme recorded stay, since there
                // is nothing to tell them apart by.
                ->reject(function (User $u) {
                    return optional($u->role)->name === 'Program Chair'
                        && $u->course
                        && auth()->user()->course
                        && $u->course !== auth()->user()->course;
                })
                ->filter(function (User $u) use ($colleges) {
                    // Not a college? A central office: keep it.
                    if (! in_array($u->department, $colleges, true)) {
                        return true;
                    }

                    return $u->department === auth()->user()->department;
                })
                ->groupBy(function (User $u) {
                    $role = optional($u->role)->name;

                    return match ($role) {
                        'Program Chair' => 'Program Chair',
                        'Dean' => 'Dean',
                        'Vice President for Academic Affairs' => 'Vice President for Academic Affairs',
                        default => 'Staff and offices',
                    };
                })
                // The order a concern climbs: the chair first, then the dean
                // above them, then the VPAA. Everybody else last.
                ->sortBy(fn ($people, $group) => array_search($group, [
                    'Program Chair',
                    'Dean',
                    'Vice President for Academic Affairs',
                    'Staff and offices',
                ], true)),
        ]);
    }

    /**
     * Store a newly created concern in storage.
     *
     * Note: students do NOT set urgency/severity. It is left null
     * ("Pending triage") and assigned by staff during triage.
     */
    public function store(Request $request)
    {
        // One name or several, posted the same way. The field became a list
        // when a concern gained the ability to name more than one person;
        // wrapping a lone id here means every existing form post, link and
        // test that sends a single value keeps working unchanged.
        $request->merge([
            'about_staff_id' => array_values(array_filter(
                \Illuminate\Support\Arr::wrap($request->input('about_staff_id')),
                fn ($id) => $id !== null && $id !== '',
            )),
        ]);

        $validated = $request->validate([
            'category' => ['required', Rule::in(Concern::CATEGORIES)],
            // "Others" is the one category that does not say what it is, so it
            // has to say here. Required for that alone; ignored for the rest.
            'other_category' => ['nullable', 'required_if:category,Others', 'string', 'min:3', 'max:120'],
            // 'department' is NOT accepted from the form: it is the reporter's
            // own college, taken from their account below. Students told us
            // twice which college they belong to otherwise -- once at
            // registration and again on every concern.
            // Length limits are enforced server-side too -- the form's
            // minlength/maxlength are advisory only.
            'description' => 'required|string|min:20|max:2000',
            // Optional conflict-of-interest flag: the people this concern is
            // about. Each must be a real user holding a staff-type role.
            //
            // A list, because a complaint is often about more than one person
            // and naming only one left the others eligible to receive it. The
            // form can now name an instructor, the class adviser and a dean on
            // the same concern.
            //
            // Normalised to an array before validation (see store()), so a
            // single id still posts exactly as it always did.
            'about_staff_id' => ['nullable', 'array', 'max:10'],
            'about_staff_id.*' => ['integer', \Illuminate\Validation\Rule::exists('users', 'id'),
                function ($attribute, $value, $fail) {
                    $roleName = optional(optional(User::find($value))->role)->name;
                    if (! in_array($roleName, self::STAFF_ROLES, true)) {
                        $fail('The selected person is not a staff member.');
                    }
                },
            ],
            // Optional evidence files. Whitelisted types only, validated by real
            // MIME content (not just extension), max 5 MB each, max 5 files.
            // The student's own way past their class adviser. A checkbox, not
            // an accusation: they need not report the adviser to ask that
            // somebody else read this.
            'skip_adviser' => ['nullable', 'boolean'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['file', 'mimes:jpg,jpeg,png,pdf', 'mimetypes:image/jpeg,image/png,application/pdf', 'max:5120'],
        ], [
            'attachments.max' => 'You can attach at most 5 files.',
            'attachments.*.mimes' => 'Only JPG, PNG, or PDF files are allowed.',
            'attachments.*.mimetypes' => 'Only JPG, PNG, or PDF files are allowed.',
            'attachments.*.max' => 'Each file must be 5 MB or smaller.',
        ]);

        $validated['user_id'] = Auth::id();
        // The concern belongs to the reporter's college. routeConcern() matches
        // this against users.department to pick a handler from that college, so
        // it must come from the account, never from user input.
        $validated['department'] = Auth::user()->department;
        // Their programme, for the same reason and by the same rule: a
        // Program Chair chairs one course, so a referral has to know which.
        $validated['course'] = Auth::user()->course;
        // And the section, for the same reason one step down: an Academic
        // concern goes to the student's own class adviser, and the adviser
        // is attached to a section rather than to the college.
        $validated['section'] = Auth::user()->section;
        // Urgency is assigned automatically from the category and description
        // at submission time -- students never set it, and staff no longer
        // have to triage a blank "Pending triage" queue by hand. Staff can
        // still correct it afterward from the concern page if the automatic
        // read is wrong (see determineUrgency()).
        $validated['urgency'] = $this->determineUrgency($validated['category'], $validated['description']);
        // Anonymous submission is no longer offered on the form -- new
        // concerns are never anonymous. Existing anonymous concerns (and
        // the Head of School's identity-reveal feature for them) are
        // untouched.
        $validated['is_anonymous'] = false;

        // Only meaningful where the adviser is the first handler. Cleared
        // everywhere else so a hand-posted form cannot mark a Facilities
        // concern as skipping a tier it never had.
        $validated['skip_adviser'] = (self::CATEGORY_ROUTING[$validated['category']] ?? 'Adviser') === 'Adviser'
            ? (bool) ($validated['skip_adviser'] ?? false)
            : false;

        // Only "Others" carries a label. The form clears the field when the
        // category changes, but that is a convenience, not a rule -- a direct
        // post would otherwise store a label beside a category that already
        // says what it is, and it would show on the concern page.
        if ($validated['category'] !== 'Others') {
            $validated['other_category'] = null;
        }

        // The same concern, filed twice.
        //
        // Two ways this happens, and both end up here: a double-click or a
        // refreshed confirmation page, which posts the identical form again
        // within seconds; and a student who thinks nothing happened the first
        // time, so writes the same thing again a day later. Either way the
        // office gets two copies of one problem, each routed and assigned
        // separately, and a handler resolves one while the other sits open.
        //
        // Matched on the student, the category and the text, ignoring case
        // and surrounding space -- the same complaint retyped with a capital
        // letter is still the same complaint. Only against concerns that are
        // still open: once a case is finished, filing it again is a new
        // report about the same thing, which is allowed and often correct.
        $alreadyFiled = Concern::where('user_id', $validated['user_id'])
            ->where('category', $validated['category'])
            ->whereNotIn('status', Concern::TERMINAL_STATUSES)
            ->whereRaw('LOWER(TRIM(description)) = ?', [mb_strtolower(trim($validated['description']))])
            ->latest('id')
            ->first();

        if ($alreadyFiled) {
            return redirect()->route('concerns.show', $alreadyFiled)
                ->with('error', 'You have already submitted this concern — it is #'.$alreadyFiled->id
                    .', filed '.$alreadyFiled->created_at->diffForHumans()
                    .', and it is still being handled. Add anything new to that one rather than filing it twice.');
        }

        // 'attachments' is not a column on concerns -- handle it separately.
        $uploadedFiles = $request->file('attachments', []);
        unset($validated['attachments']);

        // Neither is the subject list: it lives in concern_subjects. Held back
        // until the row exists, then written through syncSubjects(), which is
        // the only thing that sets about_staff_id.
        $subjectIds = $validated['about_staff_id'] ?? [];
        unset($validated['about_staff_id']);

        $concern = Concern::create($validated);

        if (! empty($subjectIds)) {
            $concern->syncSubjects($subjectIds);
        }

        // Securely store any evidence files on the PRIVATE disk with randomized
        // names. The original name is kept only as a display label.
        if (! empty($uploadedFiles)) {
            foreach ($uploadedFiles as $file) {
                // store() generates a random filename on the 'local' (private) disk.
                $path = $file->store('attachments', 'local');

                Attachment::create([
                    'concern_id' => $concern->id,
                    'uploaded_by' => Auth::id(),
                    'original_name' => $file->getClientOriginalName(),
                    'stored_path' => $path,
                    'mime_type' => $file->getClientMimeType(),
                    'size_bytes' => $file->getSize(),
                ]);
            }
        }

        // Category picks the handling role; the college narrows it to a person.
        $this->routeConcern($concern);

        // Create notification for relevant department
        $this->notifyDepartment($concern);

        // Log the action
        AuditLog::create([
            'user_id' => Auth::id(),
            'concern_id' => $concern->id,
            'action' => 'concern_submitted',
            'description' => 'Student submitted a new concern',
            'ip_address' => $request->ip(),
        ]);

        AuditLog::create([
            'user_id' => Auth::id(),
            'concern_id' => $concern->id,
            'action' => 'urgency_assigned',
            'description' => "Urgency auto-assigned as {$concern->urgency}",
            'ip_address' => $request->ip(),
        ]);

        return redirect()->route('concerns.show', $concern)->with('success', 'Concern submitted successfully');
    }

    /**
     * Display the specified concern.
     */
    public function show(Concern $concern)
    {
        /** @var User $user */
        $user = Auth::user();

        if (! $this->canViewConcern($concern, $user)) {
            abort(403, 'Unauthorized');
        }

        // Roles come along for the detail panel: a name on its own does not
        // say which office is holding the concern, or what the person it is
        // about does here.
        $concern->load('user', 'assignedUser.role', 'subjects.role', 'auditLogs.user', 'attachments');

        // Named people the viewer may hand this concern to, grouped by office.
        // Empty for a student (they never see the update form) and empty for
        // any office with nobody eligible -- the view uses that to decide
        // whether to render the "Refer to a specific person" dropdown at all,
        // so staff are never shown a picker with nothing pickable in it.
        $referralCandidates = $user->isEmployee()
            ? $this->referralCandidates($concern, $user)
            : collect();

        // Only the offices somebody eligible is standing in -- and never the
        // viewer's own.
        //
        // Referring a case to the office already reading it is not a hand-off.
        // A Program Chair was offered "Program Chair", a Dean "Dean"; the
        // names behind them belong to other programmes and other colleges,
        // reached only because the one person who does hold the student's
        // programme is the one doing the referring.
        //
        // Every role the viewer holds, not just the primary one: somebody who
        // is a Faculty/Staff and also runs an office should not be handed
        // either of their own desks.
        $ownRoles = $user->allRoleNames();

        $referralDestinations = collect(self::REFERRAL_ROLE_LABELS)
            ->filter(fn ($label, $role) => $referralCandidates->has($role)
                && $referralCandidates->get($role)->isNotEmpty()
                && ! in_array($role, $ownRoles, true));

        return view('concerns.show', compact('concern', 'referralCandidates', 'referralDestinations'));
    }

    /**
     * Determine whether the current user can view a concern.
     */
    private function canViewConcern(Concern $concern, User $user): bool
    {
        if (! $user->role) {
            return false;
        }

        // Owners can always see their own concern.
        if ($concern->user_id === $user->id) {
            return true;
        }

        // Everyone else is governed by the SAME visibility rule used by the
        // list and dashboard, so view permissions can never drift out of sync.
        return Concern::whereKey($concern->id)->visibleTo($user)->exists();
    }

    /**
     * Update the specified concern in storage.
     */
    public function update(Request $request, Concern $concern)
    {
        /** @var User $user */
        $user = Auth::user();

        if (! $user->role) {
            abort(403, 'Your account has no role assigned.');
        }

        // ---- Reporters cannot edit a submitted concern ----
        // A concern is a report of something that happened, so it is final once
        // filed -- letting the reporter rewrite the category, department or
        // description after submission would change what staff are responding
        // to mid-investigation and break the audit trail. Students who need a
        // correction submit a new concern instead.
        if ($user->id === $concern->user_id) {
            abort(403, 'A submitted concern can no longer be edited. Please submit a new concern instead.');
        }

        // ---- Staff triage / status update ----
        // HARD GATE: whoever updates a concern must first be permitted to SEE
        // it under the exact same least-privilege rules as the list/show pages
        // (canViewConcern -> scopeVisibleTo). This enforces the conflict-of-
        // interest wall on writes too: the person a concern is about can never
        // act on it, and Admin/Dean cannot touch confidential cases
        // outside their visibility.
        if (! $this->canViewConcern($concern, $user)) {
            abort(403, 'Unauthorized');
        }

        $role = optional($user->role)->name;
        // Loose-cast comparison: assigned_to may arrive as a string in some
        // code paths, so compare as integers to avoid a false "Unauthorized".
        $isAssignee = (int) $concern->assigned_to === (int) $user->id;
        $isPrivileged = in_array($role, ['System Admin', 'Staff Admin', 'Dean'], true);
        // A user whose role matches where the concern was referred may also act on it.
        $isReferralTarget = $concern->referred_to !== null && $concern->referred_to === $role;

        if (! $isAssignee && ! $isPrivileged && ! $isReferralTarget) {
            return redirect()->route('concerns.show', $concern)
                ->with('error', 'This concern is no longer assigned to you, so you can no longer update it.');
        }

        // A finished concern -- resolved, or closed without action -- cannot be
        // edited further. Reopening one would let an outcome the reporter has
        // already been told about be rewritten after the fact.
        if (in_array($concern->status, Concern::TERMINAL_STATUSES, true)) {
            $what = $concern->status === 'resolved' ? 'resolved' : 'closed';

            return redirect()->route('concerns.show', $concern)
                ->with('error', "This concern is already {$what} and can no longer be edited.");
        }

        $validated = $request->validate([
            'status' => 'required|in:submitted,in_progress,resolved,referred,closed_no_action',
            // Required only when closing without action -- checked below rather
            // than with required_if so the message can explain WHY it is
            // needed. The handbook expects a case that ends without action to
            // be documented, and the reporter is shown this text.
            'closure_reason' => 'nullable|string|min:20|max:1000',
            'urgency' => 'nullable|in:Low,Medium,High,Critical',
            'referred_to' => 'nullable|in:'.implode(',', self::REFERRAL_ROLES),
            // Optional: refer to a NAMED person in that office rather than
            // letting findHandler() pick. Only ever set from the people
            // dropdown, and re-checked against the candidate list below --
            // "exists" alone would let a crafted post hand the case to anyone.
            'referred_to_user_id' => 'nullable|integer|exists:users,id',
            // Both are required on every save, not only when resolving. A
            // status change with no explanation leaves the student watching a
            // badge move with nothing to read, and leaves the next handler
            // guessing what was already looked into.
            //
            // min:3 rather than bare "required": the TrimStrings middleware
            // turns a box of spaces into an empty string, and three characters
            // is the shortest thing that can carry meaning.
            'investigation_notes' => 'required|string|min:3|max:5000',
            'resolution_notes' => 'required|string|min:3|max:5000',
        ], [
            // Named for what the reader has to do, not for the rule that
            // failed: "The investigation notes field is required" tells a
            // handler nothing they did not already see.
            'investigation_notes.required' => 'Write what you found while looking into this. The student will see it.',
            'investigation_notes.min' => 'Write what you found while looking into this. The student will see it.',
            'resolution_notes.required' => 'Write what is being done about this. The student will see it.',
            'resolution_notes.min' => 'Write what is being done about this. The student will see it.',
        ]);

        // When a concern is referred, a destination role is required.
        if ($validated['status'] === 'referred' && empty($validated['referred_to'])) {
            return redirect()->back()
                ->withErrors(['referred_to' => 'Please choose where to refer this concern.'])
                ->withInput();
        }

        // Closing a concern without acting on it is the one outcome where the
        // student gets nothing done for them, so the reason is mandatory: it
        // is what they are shown instead of a resolution, and it is the
        // documentation the handbook expects for an invalid complaint.
        if ($validated['status'] === 'closed_no_action' && blank($validated['closure_reason'] ?? null)) {
            return redirect()->back()
                ->withErrors(['closure_reason' => 'Please explain why this concern is being closed without action. The student will see this.'])
                ->withInput();
        }

        // The reason belongs only to a closure -- carrying it over to another
        // status would leave a stale explanation attached to an active case.
        if ($validated['status'] !== 'closed_no_action') {
            $validated['closure_reason'] = null;
        }

        // Guard against a pointless "refer to where it already is": if the
        // destination role already owns this concern (the current assignee holds
        // that role), block it so the timeline isn't cluttered with no-ops.
        // Naming a person is exempt: handing a case from one Dean
        // to a different Dean is a real hand-off, not a no-op.
        if ($validated['status'] === 'referred' && ! empty($validated['referred_to'])
            && empty($validated['referred_to_user_id'])) {
            $currentOwnerRole = optional(optional($concern->assignedUser)->role)->name;
            if ($currentOwnerRole === $validated['referred_to']) {
                return redirect()->back()
                    ->withErrors(['referred_to' => 'This concern is already handled by ' . $validated['referred_to'] . ', so referring it there again isn\'t needed.'])
                    ->withInput();
            }
        }

        // The office a concern was referred to is KEPT once it moves on.
        //
        // It used to be cleared the moment the status changed, so the instant
        // the Dean resolved a case the chair had sent them, the record of it
        // ever having been referred was gone: the concern page stopped saying
        // where it went, and the dashboard's breakdown by office could only
        // ever show cases still in flight. An administrator asking "how often
        // do Administrative concerns end up with Records?" got the handful
        // open at that moment, not the answer.
        //
        // Nothing reads this column without also checking the status: every
        // visibility rule in Concern::scopeVisibleTo() pairs it with
        // whereNotIn('status', TERMINAL_STATUSES), the list only prints the
        // arrow while the status is 'referred', and a finished concern cannot
        // be edited by anyone. So keeping it widens nobody's access -- it only
        // stops the system forgetting what it did.

        // When referring, transfer ownership to a user holding the destination
        // role so that person can actually act on (and resolve) the concern.
        // findHandler() picks someone from the reporter's own college first, so
        // "refer to Dean" reaches the dean of THAT college rather
        // than whichever dean happens to have the lowest id. It also applies
        // the same conflict-of-interest exclusion as routeConcern(), so a
        // manual referral can never assign the case to its own subject.
        $referralRecipient = null;

        if ($validated['status'] === 'referred' && ! empty($validated['referred_to'])) {
            if (! empty($validated['referred_to_user_id'])) {
                // A named recipient. Re-derive the candidate list server-side
                // and look the id up in it: that re-applies every rule the
                // dropdown was built from (right office, not the subject of
                // the concern, not the referrer, not banned), so a forged id
                // in the form cannot route a case to someone ineligible.
                $referralRecipient = $this->referralCandidates($concern, $user)
                    ->get($validated['referred_to'], collect())
                    ->firstWhere('id', (int) $validated['referred_to_user_id']);

                if (! $referralRecipient) {
                    return redirect()->back()
                        ->withErrors(['referred_to_user_id' => 'That person can no longer receive this referral. Please pick someone else, or leave it to the office.'])
                        ->withInput();
                }
            } else {
                // No person named, so the office is chosen for them. For a
                // college role that means the student's OWN college: a chair
                // sending a Computer Studies case to "Dean" means their dean,
                // not whichever dean findHandler() reaches first. Only if that
                // college has nobody in the role does it widen, and then the
                // error below says so rather than handing the case to a
                // stranger.
                // Scoped only when there is a college to scope TO. A concern
                // whose department is an office rather than a college -- the
                // Guidance Office, say -- has no "own dean", and narrowing to
                // one would refuse every referral it could otherwise make.
                $scoped = in_array($validated['referred_to'], self::COLLEGE_SCOPED_ROLES, true)
                    && $concern->department
                    && in_array($concern->department, $this->collegeNames(), true);

                $referralRecipient = $scoped
                    ? $this->findHandlerInCollege($validated['referred_to'], $concern)
                    : $this->findHandler($validated['referred_to'], $concern);
            }

            // If there is no one in the destination role, the referral cannot
            // be completed -- reject it rather than silently stranding the
            // concern with no valid handler.
            if (! $referralRecipient) {
                // Name the college when the role is scoped to one. "There is
                // no Dean available" is puzzling when six of them are on file;
                // "no Dean for the College of Computer Studies" says what is
                // actually missing, and points at the fix.
                $where = ($scoped ?? false) ? ' for the '.$concern->department : '';

                return redirect()->back()
                    ->withErrors(['referred_to' => 'There is currently no '.$validated['referred_to'].$where.' available to receive this referral. Please choose another destination.'])
                    ->withInput();
            }

            $validated['assigned_to'] = $referralRecipient->id;
        }

        // Not a column on `concerns` -- it only chooses WHO the referral goes
        // to, and that choice is already recorded in assigned_to. Leaving it
        // in would blow up the mass update below.
        unset($validated['referred_to_user_id']);

        $oldStatus = $concern->status;
        $oldUrgency = $concern->urgency;
        $oldNotes = $concern->resolution_notes;
        $oldInvestigationNotes = $concern->investigation_notes;

        if ($validated['status'] === 'resolved') {
            $validated['resolved_at'] = now();
        }

        // Stamped separately from resolved_at on purpose: a concern that ended
        // without action was never resolved, and reusing resolved_at would
        // make it count as one in the dashboard's resolution figures.
        if ($validated['status'] === 'closed_no_action') {
            $validated['closed_at'] = now();
        }

        $concern->update($validated);

        $statusChanged = $oldStatus !== $validated['status'];
        $isReferral = $validated['status'] === 'referred' && ! empty($validated['referred_to']);
        $notesChanged = array_key_exists('resolution_notes', $validated)
            && $validated['resolution_notes'] !== $oldNotes;
        $investigationNotesChanged = array_key_exists('investigation_notes', $validated)
            && $validated['investigation_notes'] !== $oldInvestigationNotes;

        // Log the status change with a human-readable description. A referral
        // is always logged (re-referring elsewhere keeps status 'referred' but
        // is still a hand-off); an unchanged status is not logged, so the
        // timeline isn't cluttered with "changed from X to X" noise.
        if ($statusChanged || $isReferral) {
            if ($isReferral) {
                // Name the actual recipient -- "Referred to Dean"
                // alone hid which dean received it.
                $logDescription = "Referred to {$validated['referred_to']}";

                if ($referralRecipient) {
                    $logDescription .= " ({$referralRecipient->name}"
                        .($referralRecipient->department ? ", {$referralRecipient->department}" : '').')';
                }
            } elseif ($validated['status'] === 'resolved') {
                $logDescription = 'Marked as resolved';
            } elseif ($validated['status'] === 'closed_no_action') {
                // The reason goes in the audit entry, not just the column:
                // closing a student's report without acting on it is the
                // decision most likely to be questioned later, so it has to be
                // non-repudiable on the timeline.
                $logDescription = 'Closed without action. Reason: '.$validated['closure_reason'];
            } else {
                $logDescription = "Status changed from {$oldStatus} to {$validated['status']}";
            }

            AuditLog::create([
                'user_id' => Auth::id(),
                'concern_id' => $concern->id,
                'action' => 'status_updated',
                'description' => $logDescription,
                'ip_address' => $request->ip(),
            ]);
        } elseif ($notesChanged) {
            // Editing the notes alone is still a real action on the case and
            // must stay auditable (it also preserves the actor's involvement
            // history for visibility).
            AuditLog::create([
                'user_id' => Auth::id(),
                'concern_id' => $concern->id,
                'action' => 'notes_updated',
                'description' => 'Resolution notes updated',
                'ip_address' => $request->ip(),
            ]);
        }

        if ($investigationNotesChanged) {
            AuditLog::create([
                'user_id' => Auth::id(),
                'concern_id' => $concern->id,
                'action' => 'investigation_updated',
                'description' => 'Investigation notes updated',
                'ip_address' => $request->ip(),
            ]);
        }

        // Log the triage/urgency assignment separately when it changes
        if (array_key_exists('urgency', $validated) && $validated['urgency'] !== $oldUrgency) {
            AuditLog::create([
                'user_id' => Auth::id(),
                'concern_id' => $concern->id,
                'action' => 'urgency_assigned',
                'description' => 'Urgency changed from ' . ($oldUrgency ?? 'Pending triage') . ' to ' . ($validated['urgency'] ?? 'Pending triage'),
                'ip_address' => $request->ip(),
            ]);
        }

        // Notify the student only when the status actually changed (or the
        // concern was handed off). The wording, the in-app row and the email
        // to their CSPC address are all handled by the notification service.
        if ($statusChanged || $isReferral) {
            $this->notifications->statusChanged($concern, $validated['status']);
        }

        // Build a context-aware success message.
        if ($validated['status'] === 'referred' && ! empty($validated['referred_to'])) {
            $message = $referralRecipient
                ? 'Referred successfully to '.$referralRecipient->name
                    .' ('.$validated['referred_to'].($referralRecipient->department ? ' — '.$referralRecipient->department : '').').'
                : 'Referred successfully to '.$validated['referred_to'].'.';
        } elseif ($validated['status'] === 'closed_no_action') {
            $message = 'Concern closed without action. The student has been notified and your reason is on the record.';
        } else {
            $message = 'Concern updated successfully.';
        }

        // Redirect to the concern page (a fresh GET) so the view reloads clean
        // with the updated state, matching how the referral flow behaves.
        return redirect()->route('concerns.show', $concern)->with('success', $message);
    }

    /**
     * Break-glass: a Head of School reveals the identity of a pseudonymous
     * reporter. This is a deliberate, logged, reason-required action -- the
     * separation of powers is between HANDLING a concern (staff/counselor) and
     * UNMASKING a reporter (only Head of School). Every reveal is audited.
     */
    public function revealIdentity(Request $request, Concern $concern)
    {
        /** @var User $user */
        $user = Auth::user();

        // Only the Head of School may perform a break-glass reveal.
        if (! $user->role || $user->role->name !== 'Head of School') {
            abort(403, 'Only the Head of School can reveal a reporter\'s identity.');
        }

        // The concern must be within the Head of School's visibility. The
        // conflict-of-interest wall means a concern filed ABOUT the Head of
        // School is not -- they must never unmask their own accuser.
        if (! $this->canViewConcern($concern, $user)) {
            abort(403, 'Unauthorized');
        }

        // A reveal only makes sense for anonymous submissions; on a named
        // concern it would just write a misleading audit entry.
        if (! $concern->is_anonymous) {
            return redirect()->route('concerns.show', $concern)
                ->with('error', 'This concern was not submitted anonymously, so there is no identity to reveal.');
        }

        $validated = $request->validate([
            'identity_reveal_reason' => ['required', 'string', 'min:20', 'regex:/(?:\S+\s+){2,}\S+/'],
        ], [
            'identity_reveal_reason.min' => 'Please give a clear, specific reason (at least 20 characters).',
            'identity_reveal_reason.regex' => 'Please provide a proper explanation of at least a few words, not random text.',
        ]);

        // If already revealed, do not overwrite the original record.
        if ($concern->identityIsRevealed()) {
            return redirect()->route('concerns.show', $concern)
                ->with('error', 'This reporter\'s identity has already been revealed.');
        }

        $concern->update([
            'identity_revealed_at' => now(),
            'identity_revealed_by' => $user->id,
            'identity_reveal_reason' => $validated['identity_reveal_reason'],
        ]);

        // Permanent, non-repudiable audit trail of the reveal.
        AuditLog::create([
            'user_id' => $user->id,
            'concern_id' => $concern->id,
            'action' => 'identity_revealed',
            'description' => 'Reporter identity disclosed by Head of School. Reason: ' . $validated['identity_reveal_reason'],
            'ip_address' => $request->ip(),
        ]);

        return redirect()->route('concerns.show', $concern)
            ->with('success', 'Reporter identity revealed and the action has been logged.');
    }

    /**
     * The reporter rates how their resolved concern was handled -- a 1-5
     * rating plus an optional comment. Only the reporter may leave it, only
     * once the concern is resolved, and only once per concern (the unique
     * index on feedbacks.concern_id backs this up at the DB level too).
     */
    public function storeFeedback(Request $request, Concern $concern)
    {
        /** @var User $user */
        $user = Auth::user();

        if ($user->id !== $concern->user_id) {
            abort(403, 'Only the reporter can leave feedback on this concern.');
        }

        if ($concern->status !== 'resolved') {
            return redirect()->route('concerns.show', $concern)
                ->with('error', 'Feedback can only be left once a concern is resolved.');
        }

        if ($concern->feedback) {
            return redirect()->route('concerns.show', $concern)
                ->with('error', 'You have already left feedback on this concern.');
        }

        $validated = $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:1000',
        ]);

        $validated['concern_id'] = $concern->id;
        $validated['user_id'] = $user->id;

        Feedback::create($validated);

        AuditLog::create([
            'user_id' => $user->id,
            'concern_id' => $concern->id,
            'action' => 'feedback_submitted',
            'description' => "Reporter rated the resolution {$validated['rating']}/5",
            'ip_address' => $request->ip(),
        ]);

        return redirect()->route('concerns.show', $concern)->with('success', 'Thanks for your feedback!');
    }

    /**
     * Securely serve an evidence attachment. Authorization is enforced FIRST
     * using the exact same rule as viewing the concern -- so an attachment can
     * only be downloaded by someone allowed to see the concern it belongs to.
     * The reported person, other students, etc. get 403 even with a direct URL.
     */
    public function downloadAttachment(Concern $concern, Attachment $attachment)
    {
        /** @var User $user */
        $user = Auth::user();

        // The attachment must belong to this concern (no cross-concern access).
        if ($attachment->concern_id !== $concern->id) {
            abort(404);
        }

        // Same least-privilege gate as the concern itself.
        if (! $this->canViewConcern($concern, $user)) {
            abort(403, 'You are not authorized to view this attachment.');
        }

        // Files live on the private disk; stream from there. Never expose path.
        // Storage::disk() is typed as the Filesystem contract, which does not
        // declare download() -- the concrete adapter does.
        /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
        $disk = Storage::disk('local');

        if (! $disk->exists($attachment->stored_path)) {
            abort(404);
        }

        return $disk->download(
            $attachment->stored_path,
            $attachment->original_name
        );
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Concern $concern)
    {
        /** @var User $user */
        $user = Auth::user();

        if (! $user->role) {
            abort(403, 'Your account has no role assigned.');
        }

        $isOwner = $user->id === $concern->user_id;
        $isAdmin = in_array($user->role->name, ['System Admin', 'Staff Admin'], true);

        if (! $isOwner && ! $isAdmin) {
            abort(403, 'Unauthorized');
        }

        // An Admin may only delete concerns they are permitted to SEE. This
        // keeps the conflict-of-interest wall and counselor confidentiality
        // intact on deletes too -- an Admin can never remove a complaint that
        // was filed about them or a confidential case outside their scope.
        if (! $isOwner && ! $this->canViewConcern($concern, $user)) {
            abort(403, 'Unauthorized');
        }

        // A student may only delete their own concern while it is still
        // 'submitted'. Once staff have begun processing it, deletion is blocked
        // to preserve the record and its audit trail (admins may still delete).
        if ($isOwner && ! $isAdmin && $concern->status !== 'submitted') {
            return redirect()->route('concerns.show', $concern)
                ->with('error', 'This concern is already being processed and can no longer be deleted.');
        }

        AuditLog::create([
            'user_id' => Auth::id(),
            'concern_id' => $concern->id,
            'action' => 'concern_deleted',
            'description' => 'Concern was deleted',
            'ip_address' => request()->ip(),
        ]);

        $concern->delete();

        return redirect()->route('concerns.index')->with('success', 'Concern deleted successfully');
    }

    /**
     * Automatically classify a concern's urgency from its category and
     * description -- no staff judgment call required before it has a
     * severity. Alarming language in the description can escalate the
     * category's normal baseline, but it never gets downgraded below it.
     */
    private function determineUrgency(string $category, string $description): string
    {
        $text = strtolower($description);

        // Immediate danger to life or physical safety -- always Critical,
        // regardless of category.
        $criticalTerms = [
            'suicide', 'suicidal', 'kill myself', 'self-harm', 'self harm',
            'weapon', 'gun', 'knife', 'bomb', 'rape', 'raped',
            'overdose', 'kill him', 'kill her', 'kill them', 'about to die',
        ];
        foreach ($criticalTerms as $term) {
            if (str_contains($text, $term)) {
                return 'Critical';
            }
        }

        // Serious but not life-threatening -- harassment, active threats,
        // injury, or anything already unfolding.
        $highTerms = [
            'harass', 'threat', 'threatened', 'bully', 'bullying', 'abuse',
            'abused', 'emergency', 'injury', 'injured', 'accident', 'fire',
            'unsafe', 'in danger', 'assault', 'assaulted',
        ];
        foreach ($highTerms as $term) {
            if (str_contains($text, $term)) {
                return 'High';
            }
        }

        // No alarming language found -- fall back to the category's normal
        // baseline severity.
        return match ($category) {
            'Physical', 'Safety' => 'High',
            'Mental Health', 'Personal', 'Bullying', 'Harassment' => 'Medium',
            // Academic, Administrative, Facilities, Equipment, Others.
            // A broken PC is genuinely Low; a genuinely dangerous facility
            // fault still escalates on its own through the keyword scan above
            // ('unsafe', 'fire', 'accident', 'injury'), so exposed wiring or a
            // blocked fire exit does not sit here at Low.
            default => 'Low',
        };
    }

    /**
     * Automatically route the concern to the appropriate department/user.
     *
     * Two steps, in this order:
     *  1. CATEGORY decides the ROLE that handles it. This is absolute -- a
     *     Mental Health concern always reaches a counselor and never a
     *     teacher, whichever college the student belongs to.
     *  2. DEPARTMENT decides WHICH person in that role. A handler belonging to
     *     the concern's own college is preferred, so a BSIT academic complaint
     *     reaches a Computer Studies instructor rather than whoever happens to
     *     have the lowest user id. When that college has nobody in the role
     *     (or the concern names an institution-wide office such as the
     *     Guidance Office), it falls back to any holder of the role, which is
     *     the original behaviour.
     */
    private function routeConcern(Concern $concern)
    {
        $targetRoleName = self::CATEGORY_ROUTING[$concern->category] ?? 'Adviser';

        // The Adviser role never reaches outside the student's own college.
        // findHandler()'s last tier is "anybody in the role": with a single
        // Adviser account on record, every Academic concern from every college
        // whose section has no adviser would have gone to that one person.
        $targetUser = $targetRoleName === 'Adviser'
            ? $this->findHandlerInCollege('Adviser', $concern)
            : $this->findHandler($targetRoleName, $concern);

        // Conflict-of-interest escalation: if we couldn't find an untainted
        // handler in the target role (e.g. the reported person was the only
        // one), escalate up the chain. The escalation is department-aware
        // too, so a Computer Studies case reaches the CCS dean before any
        // other dean.
        //
        // Admin is the last resort, not a peer of the others: several roles
        // now have exactly ONE holder (General Services, Guidance),
        // so a concern filed ABOUT that person leaves their role with nobody
        // eligible. The chain used to stop at Head of School -- a role with no
        // holder in production -- after which the concern was created with
        // assigned_to NULL and was visible to nobody but its reporter. An
        // unassigned complaint about an office is exactly the one that must
        // not disappear, so it lands on a system administrator who can refer
        // it by hand.
        // Escalate whenever the target role has nobody eligible, whatever the
        // reason. This used to require about_staff_id, back when a reported
        // person was the only thing that could empty a role. Excluding the
        // REPORTER added a second way -- a one-person office filing a concern
        // in its own category -- and that path skipped the chain entirely,
        // leaving assigned_to NULL and the concern visible to nobody but the
        // person who filed it. An empty role is a routing failure regardless
        // of what caused it.
        // The student's OWN class adviser, before anybody else in the role.
        // "Adviser" means the person who advises their section -- BSIT 3A --
        // not whichever adviser in the college sorts first, and reaching the
        // wrong one is the whole thing this is meant to avoid.
        //
        // Every exclusion still applies afterwards: an adviser who is the
        // subject of the concern, or its reporter, is not eligible to handle
        // it however well they know the student.
        // A concern that NAMES a Program Chair goes to the Dean, with no
        // checkbox to tick. Both tiers under a chair are disqualified by rank
        // rather than by conflict: the class adviser is usually an instructor,
        // and an instructor cannot investigate the chair of their own
        // programme -- handing it to them puts a junior colleague in charge of
        // a complaint about their senior, which is how a complaint quietly
        // goes nowhere.
        //
        // Read from the subject list, not about_staff_id: a concern naming two
        // chairs and one instructor must still reach the dean.
        $chairIsSubject = User::whereIn('id', $concern->subjectIds())
            ->whereHas('role', fn ($q) => $q->where('name', 'Program Chair'))
            ->exists();

        // And a Dean among them sends it to the VPAA. Excluding the named dean
        // is not enough on its own: findHandler() falls back to anybody in the
        // role, so a complaint about the Computer Studies dean was landing on
        // the dean of Health Sciences -- a colleague of equal rank with no
        // standing over them, in a college with nothing to do with it. The
        // first office above a dean is the VPAA.
        $deanIsSubject = User::whereIn('id', $concern->subjectIds())
            ->whereHas('role', fn ($q) => $q->where('name', 'Dean'))
            ->exists();

        $adviserRefused = false;

        if ($targetRoleName === 'Adviser') {
            $sectionAdviser = \App\Models\Section::adviserFor($concern->course, $concern->section);

            // The adviser tier is spent when the student asks to skip it, or
            // when the concern is about the adviser. Same outcome either way:
            // a student who does not want their adviser reading this should
            // not have to accuse them of something to be heard elsewhere.
            $adviserRefused = (bool) $concern->skip_adviser
                || $chairIsSubject
                || $deanIsSubject
                || ($sectionAdviser && in_array($sectionAdviser->id, $concern->subjectIds(), true));

            if ($adviserRefused) {
                // findHandler() may already have found somebody holding the
                // Adviser role. That whole tier is refused, not just the
                // student's own adviser -- an adviser is no better placed to
                // investigate a Program Chair than the adviser who was skipped.
                $targetUser = null;
            } elseif ($sectionAdviser
                && $sectionAdviser->id !== (int) $concern->user_id
                && $sectionAdviser->status !== 'banned') {
                $targetUser = $sectionAdviser;
            }
        }

        // Past the adviser: the Program Chair of the student's own programme,
        // and the Dean instead when a chair is the person being reported.
        // Upward, never down to an instructor -- an instructor is junior to
        // the adviser just stepped over, so dropping there would land the
        // concern below the tier the student was trying to leave.
        if (! $targetUser && $adviserRefused) {
            if ($deanIsSubject) {
                $targetUser = $this->findHandler('Vice President for Academic Affairs', $concern);
            } elseif ($chairIsSubject) {
                $targetUser = $this->findHandlerInCollege('Dean', $concern)
                    ?: $this->findHandler('Dean', $concern);
            } else {
                // The student's OWN college, then that college's dean. Not
                // findHandler() here: its last tier is "anybody in the role",
                // which sent a BS Nursing concern to a Computer Studies chair
                // -- a stranger to the student, to the programme and to the
                // people involved. Health Sciences and Engineering have no
                // chair on record at all, so this is the common case there,
                // and their dean is the right next stop.
                $targetUser = $this->findHandlerInCollege('Program Chair', $concern)
                    ?: $this->findHandlerInCollege('Dean', $concern)
                    ?: $this->findHandler('Dean', $concern);
            }
        }

        // No adviser named for that section yet, and nobody refused one: try
        // the tier below before climbing. An instructor is closer to the
        // student than a dean is, and a college that has not named its
        // advisers should not have every academic concern land on its dean.
        if (! $targetUser && $targetRoleName === 'Adviser' && ! $adviserRefused) {
            $targetUser = $this->findHandler('Instructor', $concern);
        }

        // A complaint about the administration is never handed to the
        // administration. Excluding the individual is not enough: the office
        // is small and its members answer to each other, so someone
        // investigating the colleague at the next desk is not an independent
        // review. It goes above them instead.
        if (in_array($targetRoleName, ['Staff Admin', 'System Admin'], true) && $concern->about_staff_id) {
            // ANY named administrator disqualifies the office, not just the
            // first one listed.
            $subjectIsAdmin = User::whereIn('id', $concern->subjectIds())
                ->whereHas('role', fn ($q) => $q->whereIn('name', ['Staff Admin', 'System Admin']))
                ->exists();

            if ($subjectIsAdmin) {
                $targetUser = null;
            }
        }

        if (! $targetUser) {
            // Where the concern goes depends on WHICH office could not take it.
            // A complaint the Admin cannot handle is almost always a complaint
            // ABOUT the Admin, and a college dean has no standing over a
            // system administrator -- so it goes up to the VPAA rather than
            // sideways. Everything else still climbs the academic ladder, with
            // Admin last so nothing can end up assigned to nobody.
            $chain = in_array($targetRoleName, ['Staff Admin', 'System Admin'], true)
                ? ['Vice President for Academic Affairs', 'Head of School', 'Dean']
                : ['Dean', 'Head of School', 'Vice President for Academic Affairs', 'Staff Admin'];

            // A concern about a dean never climbs back down to one. Deans are
            // peers: the college is different, the rank is not, and "someone
            // else's dean" is not an independent reviewer of this one.
            if ($deanIsSubject) {
                $chain = array_values(array_diff($chain, ['Dean']));
            }

            foreach ($chain as $escalationRole) {
                $escalated = $this->findHandler($escalationRole, $concern);

                if ($escalated) {
                    $targetUser = $escalated;
                    break;
                }
            }
        }

        if ($targetUser) {
            $concern->update(['assigned_to' => $targetUser->id]);

            return;
        }

        // Nothing matched at all -- not the category's role, and not the
        // escalation chain. The concern still exists and the reporter can see
        // it, but no handler can, so it would sit unread with nothing
        // reporting the failure. Log it loudly: this means a role has no
        // holder, which is a configuration problem an Admin has to fix at
        // /admin/users.
        \Illuminate\Support\Facades\Log::warning('Concern could not be routed to any handler', [
            'concern_id' => $concern->id,
            'category' => $concern->category,
            'target_role' => $targetRoleName,
            'department' => $concern->department,
            'about_staff_id' => $concern->about_staff_id,
        ]);
    }

    /**
     * Pick a user in the given role to own this concern: someone from the
     * concern's own department first, otherwise anyone in the role. The person
     * the concern is about is never eligible (conflict of interest).
     */
    /**
     * Everyone the given user may refer this concern TO, grouped by office
     * (role name => Collection of users).
     *
     * A referral is a hand-off to a person, so the list is filtered down to
     * people who can actually take it:
     *
     *  - the subject of the concern is excluded, so a complaint about someone
     *    can never be referred to that same someone (the same conflict-of-
     *    interest wall findHandler() and routeConcern() enforce);
     *  - the referrer is excluded, because referring to yourself is a no-op;
     *  - the current assignee is excluded for the same reason;
     *  - banned accounts are excluded -- they are signed out on their next
     *    request, so a case handed to one would simply stall.
     *
     * Offices left with nobody are dropped entirely rather than returned as an
     * empty list, so `isset($candidates[$role])` is a straight answer to "is
     * there anyone in there to pick?".
     */
    private function referralCandidates(Concern $concern, User $user)
    {
        $colleges = $this->collegeNames();

        return User::query()
            ->whereHas('role', fn ($q) => $q->whereIn('name', self::REFERRAL_ROLES))
            // The student's own college, and nowhere else.
            //
            // The list used to hold every college's people, merely SORTED so
            // that the right ones came first. A Computer Studies case
            // therefore offered all seven deans, and picking the wrong one was
            // a single mis-click that handed a student's concern to a college
            // with no connection to them, the programme or the people in it.
            //
            // Central offices stay: Guidance, General Services, the VPAA and
            // the administrators serve every college, so they are not scoped
            // to one. Anyone with no department recorded stays too -- removing
            // them would quietly shrink the list over a missing field.
            ->where(function ($q) use ($concern, $colleges) {
                $q->whereNull('department')
                    ->orWhereNotIn('department', $colleges);

                if ($concern->department) {
                    $q->orWhere('department', $concern->department);
                }
            })
            // A college-bound role stays inside the student's own college,
            // including Faculty/Staff. Without this, "refer to Faculty/Staff"
            // on a Computer Studies case offered the Legal Affairs Office,
            // Records, Health Services and the ICT Unit -- every office in the
            // institution, none of them with any part in it.
            //
            // Only when the concern itself belongs to a college. One filed
            // from an office has no college to be kept inside.
            ->when(
                $concern->department && in_array($concern->department, $colleges, true),
                fn ($q) => $q->where(function ($sub) use ($concern) {
                    $sub->where('department', $concern->department)
                        ->orWhereHas('role', fn ($r) => $r->whereNotIn('name', self::COLLEGE_SCOPED_ROLES));
                })
            )
            ->where('id', '!=', $user->id)
            // Nobody the concern names, however many that is.
            ->whereNotIn('id', $concern->subjectIds())
            // ...and never the reporter, so the picker cannot offer to hand
            // someone their own concern. Matches findHandler().
            ->where('id', '!=', $concern->user_id)
            ->when($concern->assigned_to, fn ($q) => $q->where('id', '!=', $concern->assigned_to))
            ->where('status', '!=', 'banned')
            ->with('role')
            // Same order of preference findHandler() applies when it picks on
            // its own: the reporter's own programme first, then their college,
            // then everyone else. So the name at the top of the list is the
            // one the system would have chosen anyway, and picking somebody
            // else is a deliberate override rather than a correction.
            ->orderByRaw('CASE WHEN course IS NOT NULL AND course = ? THEN 0 ELSE 1 END', [$concern->course])
            ->orderByRaw('CASE WHEN department = ? THEN 0 ELSE 1 END', [$concern->department])
            ->orderBy('name')
            ->get()
            ->pipe(fn ($people) => $this->narrowChairsToTheProgramme($people, $concern))
            ->groupBy(fn (User $candidate) => $candidate->role->name);
    }

    /**
     * A Program Chair chairs ONE programme, so only that one is offered.
     *
     * Scoping to the college was not enough: Computer Studies has four chairs,
     * and a BS Information Systems case listed all four. Three of them chair a
     * programme the student is not enrolled in and have no standing over the
     * people in it, so they are not alternatives -- they are mistakes waiting
     * to be clicked.
     *
     * If no chair matches the programme, it matters why. A programme with no
     * chair recorded falls back to the college's chairs -- some are stored
     * without a programme, and several colleges are covered by a placeholder,
     * so narrowing to nothing would leave a case that could not be referred.
     * But where the programme does have a chair and they were left out --
     * they are the one referring, or the concern is about them -- the other
     * chairs are not stand-ins, and none is offered.
     *
     * @param  \Illuminate\Support\Collection<int, User>  $people
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function narrowChairsToTheProgramme($people, Concern $concern)
    {
        if (! $concern->course) {
            return $people;
        }

        $isChair = fn (User $u) => optional($u->role)->name === 'Program Chair';

        $theirs = $people->filter(fn (User $u) => $isChair($u) && $u->course === $concern->course);

        if ($theirs->isEmpty()) {
            // Nobody matched, which has two very different causes.
            //
            // The programme's chair may have been left out on purpose: they
            // are the one referring, or the concern is about them. The other
            // chairs are not stand-ins for them -- they chair different
            // programmes -- so the office is simply not offered.
            //
            // Or the programme has no chair recorded at all, which is the case
            // this fallback was written for: some chairs are stored without a
            // programme, and several colleges are covered by a placeholder.
            // There the college's chairs are the best available answer.
            $programmeHasAChair = User::whereHas('role', fn ($q) => $q->where('name', 'Program Chair'))
                ->where('course', $concern->course)
                ->exists();

            return $programmeHasAChair
                ? $people->reject($isChair)
                : $people;
        }

        return $people->reject(fn (User $u) => $isChair($u) && $u->course !== $concern->course);
    }

    /**
     * The words of a search box, ready for a LIKE.
     *
     * Split on any run of whitespace, so a phrase pasted out of a concern --
     * newlines, double spaces and all -- becomes the words it is made of.
     *
     * LIKE's own wildcards are escaped, with '!' rather than a backslash:
     * MySQL reads a backslash inside LIKE as an escape by default and SQLite
     * does not, so a backslash matched in production and missed under test.
     * Without any escaping, a student searching for "100%" asked the database
     * for "anything containing 100 followed by anything", which quietly
     * matches far more than they meant; an underscore does the same for any
     * single character.
     *
     * @return list<string>
     */
    private static function searchWords(string $term): array
    {
        $words = preg_split('/\s+/u', trim($term), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_map(
            fn (string $word) => str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $word),
            $words
        ));
    }

    /**
     * What counts as a college, as opposed to a central office.
     *
     * COURSES_BY_COLLEGE names the six that enrol undergraduates; the Graduate
     * School has a dean and no entry there. Anywhere a dean sits is a college,
     * and everything else -- Guidance, General Services, ICT, Records -- serves
     * the whole institution and is never scoped to one.
     *
     * @return list<string>
     */
    private function collegeNames(): array
    {
        return array_values(array_unique(array_merge(
            array_keys(User::COURSES_BY_COLLEGE),
            User::whereHas('role', fn ($q) => $q->where('name', 'Dean'))
                ->whereNotNull('department')
                ->distinct()
                ->pluck('department')
                ->all()
        )));
    }

    /**
     * Roles that belong to one college. A referral to one of these must stay
     * inside the student's own, rather than falling through to whoever holds
     * the role somewhere else.
     */
    private const COLLEGE_SCOPED_ROLES = ['Dean', 'Program Chair', 'Adviser', 'Instructor', 'Faculty/Staff'];

    /**
     * The same as findHandler(), minus its last resort.
     *
     * findHandler() ends with "anybody in the role", which is right when the
     * alternative is an unassigned concern -- and wrong when there is a tier
     * above to climb to instead. A chair of another college is not a smaller
     * version of the right chair: they hold no authority over the programme,
     * the people or the student. Returning nobody lets the caller go up to the
     * dean, who does.
     */
    private function findHandlerInCollege(string $roleName, Concern $concern): ?User
    {
        $candidates = User::whereHas('role', fn ($q) => $q->where('name', $roleName))
            ->whereNotIn('id', $concern->subjectIds())
            ->where('id', '!=', $concern->user_id);

        if ($concern->course) {
            $sameCourse = (clone $candidates)->where('course', $concern->course)->first();

            if ($sameCourse) {
                return $sameCourse;
            }
        }

        if ($concern->department) {
            return (clone $candidates)->where('department', $concern->department)->first();
        }

        return null;
    }

    private function findHandler(string $roleName, Concern $concern): ?User
    {
        $candidates = User::whereHas('role', function ($q) use ($roleName) {
            $q->where('name', $roleName);
        });

        // Every person the concern names is out, not just the first.
        $candidates->whereNotIn('id', $concern->subjectIds());

        // The REPORTER is excluded too, and for the same reason the subject is:
        // nobody investigates their own case. Staff file concerns as well as
        // handle them, so a dean who reports a facilities problem could be
        // handed it straight back the moment it was referred to Department
        // Head -- free to write the resolution notes on their own complaint
        // and close it. Excluding about_staff_id alone left that open on the
        // reporter's side of the wall.
        $candidates->where('id', '!=', $concern->user_id);

        // Narrowest match first. A Program Chair chairs a single programme, so
        // a BSIS student's concern should reach the BSIS chair rather than
        // whichever Computer Studies chair happens to sort first. Roles that
        // are not programme-scoped carry no course at all, so this tier simply
        // finds nobody for them and falls through to the college below --
        // which is why it can be applied to every role rather than special-
        // cased for chairs.
        if ($concern->course) {
            $sameCourse = (clone $candidates)
                ->where('course', $concern->course)
                ->first();

            if ($sameCourse) {
                return $sameCourse;
            }
        }

        // Same college next. Cloned so the fallback below still sees the
        // unfiltered candidate list.
        if ($concern->department) {
            $sameDepartment = (clone $candidates)
                ->where('department', $concern->department)
                ->first();

            if ($sameDepartment) {
                return $sameDepartment;
            }
        }

        return $candidates->first();
    }

    /**
     * Notify the staff member a new concern was just routed to -- in-app and
     * by email. Delegated to the notification service so the wording and the
     * delivery rules live in one place.
     */
    private function notifyDepartment(Concern $concern)
    {
        $this->notifications->assigned($concern);
    }
}