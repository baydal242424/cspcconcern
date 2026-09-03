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
        'Admin',
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
        'General Services',
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
        'Admin',
        'Dean',
        'Faculty/Staff',
        'Gender and Development',
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
        'Admin'                  => 'Admin',
        'Vice President for Academic Affairs' => 'VPAA (above the Administration)',
        'Dean'                   => 'Dean (whole college)',
        'Adviser'                => 'Adviser (a college)',
        'Instructor'             => 'Instructor (teaching staff)',
        'Faculty/Staff'          => 'Faculty/Staff (offices & units)',
        'Gender and Development' => 'Gender and Development (GAD)',
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

        $concerns = Concern::visibleTo($user)
            ->when(! $showResolved, function ($q) {
                // Hides finished cases of BOTH kinds -- resolved and closed
                // without action. A closed concern is just as done as a
                // resolved one, so leaving it in the active list would keep
                // dead cases in front of staff forever.
                $q->whereNotIn('status', Concern::TERMINAL_STATUSES);
            })
            ->with('user', 'assignedUser')
            ->latest()
            ->paginate(10)
            ->withQueryString();

        return view('concerns.index', compact('concerns', 'showResolved'));
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
        })->orderBy('name')->get(['id', 'name', 'department', 'role_id']);

        // Splits on Instructor. This read 'Faculty/Staff' until that role was
        // divided in two, at which point the two lists silently swapped: the
        // "which instructor" picker filled up with unit heads -- ICT, Records,
        // Health Services -- while the actual teachers dropped into the
        // other-staff list. Nothing errored; the names were just wrong.
        [$instructors, $otherStaff] = $staffMembers->partition(
            fn (User $u) => optional($u->role)->name === 'Instructor'
        );

        return view('concerns.create', [
            // Grouped by college so a long list stays navigable. Instructors
            // from every college are offered, not just the student's own --
            // general-education subjects are taught across colleges.
            'instructorsByCollege' => $instructors->groupBy(fn (User $u) => $u->department ?: 'Other'),
            'otherStaff' => $otherStaff,
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
            // Optional conflict-of-interest flag: the staff member this concern
            // is about. Must be a real user holding a staff-type role.
            'about_staff_id' => ['nullable', 'integer', \Illuminate\Validation\Rule::exists('users', 'id'),
                function ($attribute, $value, $fail) {
                    $roleName = optional(optional(User::find($value))->role)->name;
                    if (! in_array($roleName, self::STAFF_ROLES, true)) {
                        $fail('The selected person is not a staff member.');
                    }
                },
            ],
            // Optional evidence files. Whitelisted types only, validated by real
            // MIME content (not just extension), max 5 MB each, max 5 files.
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

        // Only "Others" carries a label. The form clears the field when the
        // category changes, but that is a convenience, not a rule -- a direct
        // post would otherwise store a label beside a category that already
        // says what it is, and it would show on the concern page.
        if ($validated['category'] !== 'Others') {
            $validated['other_category'] = null;
        }

        // 'attachments' is not a column on concerns -- handle it separately.
        $uploadedFiles = $request->file('attachments', []);
        unset($validated['attachments']);

        $concern = Concern::create($validated);

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

        $concern->load('user', 'assignedUser', 'auditLogs.user', 'attachments');

        // Named people the viewer may hand this concern to, grouped by office.
        // Empty for a student (they never see the update form) and empty for
        // any office with nobody eligible -- the view uses that to decide
        // whether to render the "Refer to a specific person" dropdown at all,
        // so staff are never shown a picker with nothing pickable in it.
        $referralCandidates = $user->isEmployee()
            ? $this->referralCandidates($concern, $user)
            : collect();

        return view('concerns.show', compact('concern', 'referralCandidates'));
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
        $isPrivileged = in_array($role, ['Admin', 'Dean'], true);
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
            'investigation_notes' => 'nullable|string',
            'resolution_notes' => 'nullable|string',
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

        // If status is not "referred", clear any previous referral target.
        if ($validated['status'] !== 'referred') {
            $validated['referred_to'] = null;
        }

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
                $referralRecipient = $this->findHandler($validated['referred_to'], $concern);
            }

            // If there is no one in the destination role, the referral cannot
            // be completed -- reject it rather than silently stranding the
            // concern with no valid handler.
            if (! $referralRecipient) {
                return redirect()->back()
                    ->withErrors(['referred_to' => 'There is currently no ' . $validated['referred_to'] . ' available to receive this referral. Please choose another destination.'])
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
        $isAdmin = $user->role->name === 'Admin';

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
        $categoryRouting = [
            'Academic'                 => 'Adviser',
            'Mental Health'            => 'Guidance Counselor',
            'Personal'                 => 'Guidance Counselor',
            'Bullying'                 => 'Guidance Counselor',
            'Harassment'               => 'Guidance Counselor',
            // Enrolment, records, ID, clearance, fees. This briefly went to a
            // Registrar role of its own; that role has been removed and these
            // come back to Admin, who triage and refer on to whichever office
            // owns the request. Worth knowing when reading complaints that
            // this office cannot resolve much itself -- a high referral rate
            // here is the system working, not failing.
            'Administrative'           => 'Admin',
            // Facilities/equipment problems (a dead lab PC, no water in the CR,
            // a broken aircon) have no human subject and no academic content.
            // They go to the General Services Unit, which per cspc.edu.ph
            // performs "routine maintenance on all the buildings, grounds,
            // facilities and other equipment" and is staffed for preventive
            // maintenance, the electrical system, and air-conditioning and
            // water systems.
            //
            // This used to be 'Admin'. That was wrong twice over: Admin has no
            // maintenance function, and with the demo accounts removed the
            // only Admins left are the system's own administrators -- so a
            // broken computer was landing on the student who built the app.
            //
            // Computer faults are strictly ICTRaM's (the ICT Unit's repair
            // arm), not GSU's. They still come here on purpose: GSU is the
            // office students already report a broken anything to, and one
            // real destination beats making a student decide which of two
            // maintenance offices owns their problem.
            'Facilities'               => 'General Services',
            'Equipment'                => 'General Services',
            'Physical'                 => 'Adviser',
            'Safety'                   => 'Adviser',
            'Others'                   => 'Adviser',
        ];

        $targetRoleName = $categoryRouting[$concern->category] ?? 'Adviser';

        $targetUser = $this->findHandler($targetRoleName, $concern);

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
        // No adviser in that college yet? Try the tier below before climbing.
        // An instructor is closer to the student than a dean is, and a college
        // that has not named its advisers should not have every academic
        // concern land on its dean in the meantime.
        if (! $targetUser && $targetRoleName === 'Adviser') {
            $targetUser = $this->findHandler('Instructor', $concern);
        }

        // A complaint about an administrator is never handed to another
        // administrator. Excluding the individual is not enough here: Admin is
        // the role that manages accounts, roles and bans -- including each
        // other's -- so an administrator investigating a colleague they could
        // promote or suspend is not an independent review. It goes above the
        // Administration instead.
        if ($targetRoleName === 'Admin' && $concern->about_staff_id) {
            $subjectIsAdmin = User::whereKey($concern->about_staff_id)
                ->whereHas('role', fn ($q) => $q->where('name', 'Admin'))
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
            $chain = $targetRoleName === 'Admin'
                ? ['Vice President for Academic Affairs', 'Head of School', 'Dean']
                : ['Dean', 'Head of School', 'Vice President for Academic Affairs', 'Admin'];

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
        return User::query()
            ->whereHas('role', fn ($q) => $q->whereIn('name', self::REFERRAL_ROLES))
            ->where('id', '!=', $user->id)
            ->when($concern->about_staff_id, fn ($q) => $q->where('id', '!=', $concern->about_staff_id))
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
            ->groupBy(fn (User $candidate) => $candidate->role->name);
    }

    private function findHandler(string $roleName, Concern $concern): ?User
    {
        $candidates = User::whereHas('role', function ($q) use ($roleName) {
            $q->where('name', $roleName);
        });

        if ($concern->about_staff_id) {
            $candidates->where('id', '!=', $concern->about_staff_id);
        }

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