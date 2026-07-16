<?php

namespace App\Http\Controllers;

use App\Models\Concern;
use App\Models\Notification;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Attachment;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\Request;
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
        'Faculty/Staff',
        'Department Head',
        'Guidance Counselor',
        'Admin',
        'Head of School',
    ];

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
                $q->where('status', '!=', 'resolved');
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
        $staffMembers = User::whereHas('role', function ($q) {
            $q->whereIn('name', self::STAFF_ROLES);
        })->orderBy('name')->get(['id', 'name']);

        return view('concerns.create', compact('staffMembers'));
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
            'category' => 'required|in:Academic,Mental Health / Personal,Bullying / Harassment,Administrative,Physical / Safety,Others',
            'department' => ['required', 'string', \Illuminate\Validation\Rule::in([
                'College of Engineering and Architecture',
                'College of Computer Studies',
                'College of Health Sciences',
                'College of Tourism, Hospitality and Business Management',
                'College of Technological and Development Education',
                'Guidance Office',
                'SASO',
            ])],
            // Length limits are enforced server-side too -- the form's
            // minlength/maxlength are advisory only.
            'description' => 'required|string|min:20|max:2000',
            'is_anonymous' => 'boolean',
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
        $validated['urgency'] = null; // assigned by staff during triage
        // Normalize the checkbox explicitly (absent => false), matching how
        // the student edit path handles it.
        $validated['is_anonymous'] = $request->boolean('is_anonymous');

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

        // Auto-route concern based on department (with category fallback)
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
        return view('concerns.show', compact('concern'));
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
     * Show the form for editing the specified concern.
     */
    public function edit(Concern $concern)
    {
        /** @var User $user */
        $user = Auth::user();

        if (! $user->role) {
            abort(403, 'Your account has no role assigned.');
        }

        // Only the reporter may use the details edit form, and only before
        // staff begin processing. (Admins previously had access here, but the
        // form posts reporter-only fields that the staff update path rejects,
        // and it exposed confidential case content outside scopeVisibleTo.
        // Staff act on concerns via the status form on the show page instead.)
        if ($user->id !== $concern->user_id) {
            abort(403, 'Only the reporter can edit a concern\'s details.');
        }

        if ($concern->status !== 'submitted') {
            abort(403, 'Only submitted concerns can be edited by the owner.');
        }

        return view('concerns.edit', compact('concern'));
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

        // ---- Student editing their own un-processed concern ----
        // Students may edit category/department/description/anonymity only.
        // They may NOT set urgency/severity.
        if ($user->id === $concern->user_id) {
            if ($concern->status !== 'submitted') {
                abort(403, 'Only submitted concerns can be edited by the owner.');
            }

            $validated = $request->validate([
                'category' => 'required|in:Academic,Mental Health / Personal,Bullying / Harassment,Administrative,Physical / Safety,Others',
                'department' => ['required', 'string', \Illuminate\Validation\Rule::in([
                'College of Engineering and Architecture',
                'College of Computer Studies',
                'College of Health Sciences',
                'College of Tourism, Hospitality and Business Management',
                'College of Technological and Development Education',
                'Guidance Office',
                'SASO',
            ])],
                'description' => 'required|string|min:20|max:2000',
                'is_anonymous' => 'boolean',
            ]);

            $validated['is_anonymous'] = $request->has('is_anonymous');
            $concern->update($validated);

            // Re-route if the student changed the department or category before it is processed.
            $this->routeConcern($concern);

            AuditLog::create([
                'user_id' => Auth::id(),
                'concern_id' => $concern->id,
                'action' => 'concern_edited',
                'description' => 'Student updated concern details before processing',
                'ip_address' => $request->ip(),
            ]);

            return redirect()->route('concerns.show', $concern)->with('success', 'Concern updated successfully');
        }

        // ---- Staff triage / status update ----
        // HARD GATE: whoever updates a concern must first be permitted to SEE
        // it under the exact same least-privilege rules as the list/show pages
        // (canViewConcern -> scopeVisibleTo). This enforces the conflict-of-
        // interest wall on writes too: the person a concern is about can never
        // act on it, and Admin/Department Head cannot touch confidential cases
        // outside their visibility.
        if (! $this->canViewConcern($concern, $user)) {
            abort(403, 'Unauthorized');
        }

        $role = optional($user->role)->name;
        // Loose-cast comparison: assigned_to may arrive as a string in some
        // code paths, so compare as integers to avoid a false "Unauthorized".
        $isAssignee = (int) $concern->assigned_to === (int) $user->id;
        $isPrivileged = in_array($role, ['Admin', 'Department Head'], true);
        // A user whose role matches where the concern was referred may also act on it.
        $isReferralTarget = $concern->referred_to !== null && $concern->referred_to === $role;

        if (! $isAssignee && ! $isPrivileged && ! $isReferralTarget) {
            return redirect()->route('concerns.show', $concern)
                ->with('error', 'This concern is no longer assigned to you, so you can no longer update it.');
        }

        // A resolved concern is closed and cannot be edited further.
        if ($concern->status === 'resolved') {
            return redirect()->route('concerns.show', $concern)
                ->with('error', 'This concern is already resolved and can no longer be edited.');
        }

        $validated = $request->validate([
            'status' => 'required|in:submitted,in_progress,resolved,referred',
            'urgency' => 'nullable|in:Low,Medium,High,Critical',
            'referred_to' => 'nullable|in:Guidance Counselor,Admin,Department Head,Faculty/Staff',
            'resolution_notes' => 'nullable|string',
        ]);

        // When a concern is referred, a destination role is required.
        if ($validated['status'] === 'referred' && empty($validated['referred_to'])) {
            return redirect()->back()
                ->withErrors(['referred_to' => 'Please choose where to refer this concern.'])
                ->withInput();
        }

        // Guard against a pointless "refer to where it already is": if the
        // destination role already owns this concern (the current assignee holds
        // that role), block it so the timeline isn't cluttered with no-ops.
        if ($validated['status'] === 'referred' && ! empty($validated['referred_to'])) {
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
        if ($validated['status'] === 'referred' && ! empty($validated['referred_to'])) {
            $targetQuery = User::whereHas('role', function ($q) use ($validated) {
                $q->where('name', $validated['referred_to']);
            });

            // Conflict of interest: never hand the concern to the very person
            // it is about -- the same exclusion routeConcern() applies. Without
            // this, a manual referral could assign the case to its own subject.
            if ($concern->about_staff_id) {
                $targetQuery->where('id', '!=', $concern->about_staff_id);
            }

            $targetUser = $targetQuery->first();

            // If there is no one in the destination role, the referral cannot
            // be completed -- reject it rather than silently stranding the
            // concern with no valid handler.
            if (! $targetUser) {
                return redirect()->back()
                    ->withErrors(['referred_to' => 'There is currently no ' . $validated['referred_to'] . ' available to receive this referral. Please choose another destination.'])
                    ->withInput();
            }

            $validated['assigned_to'] = $targetUser->id;
        }

        $oldStatus = $concern->status;
        $oldUrgency = $concern->urgency;
        $oldNotes = $concern->resolution_notes;

        if ($validated['status'] === 'resolved') {
            $validated['resolved_at'] = now();
        }

        $concern->update($validated);

        $statusChanged = $oldStatus !== $validated['status'];
        $isReferral = $validated['status'] === 'referred' && ! empty($validated['referred_to']);
        $notesChanged = array_key_exists('resolution_notes', $validated)
            && $validated['resolution_notes'] !== $oldNotes;

        // Log the status change with a human-readable description. A referral
        // is always logged (re-referring elsewhere keeps status 'referred' but
        // is still a hand-off); an unchanged status is not logged, so the
        // timeline isn't cluttered with "changed from X to X" noise.
        if ($statusChanged || $isReferral) {
            if ($isReferral) {
                $logDescription = "Referred to {$validated['referred_to']}";
            } elseif ($validated['status'] === 'resolved') {
                $logDescription = 'Marked as resolved';
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
        // concern was handed off), and use a human-readable label rather than
        // the raw machine value ("in_progress").
        if ($statusChanged || $isReferral) {
            $statusLabel = ucfirst(str_replace('_', ' ', $validated['status']));

            Notification::create([
                'user_id' => $concern->user_id,
                'type' => 'status_update',
                'concern_id' => $concern->id,
                'title' => 'Concern Status Updated',
                'message' => "Your concern status has been updated to {$statusLabel}",
            ]);
        }

        // Build a context-aware success message.
        if ($validated['status'] === 'referred' && ! empty($validated['referred_to'])) {
            $message = 'Referred successfully to ' . $validated['referred_to'] . '.';
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
        if (! Storage::disk('local')->exists($attachment->stored_path)) {
            abort(404);
        }

        return Storage::disk('local')->download(
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
     * Automatically route the concern to the appropriate department/user.
     */
    private function routeConcern(Concern $concern)
    {
        // Routing is decided ENTIRELY by category. The department/college the
        // student selects is informational only and never overrides this map.
        // This guarantees, e.g., that a Mental Health concern always reaches the
        // counselor and a Physical/Safety concern always reaches Faculty/Staff,
        // regardless of which college the student belongs to.
        $categoryRouting = [
            'Academic'                 => 'Faculty/Staff',
            'Mental Health / Personal' => 'Guidance Counselor',
            'Bullying / Harassment'    => 'Guidance Counselor',
            'Administrative'           => 'Admin',
            'Physical / Safety'        => 'Faculty/Staff',
            'Others'                   => 'Faculty/Staff',
        ];

        $targetRoleName = $categoryRouting[$concern->category] ?? 'Faculty/Staff';

        // Find a user in the target role, but NEVER assign the concern to the
        // very person it is about (conflict of interest). If the only/first
        // candidate is the reported person, escalate to a higher authority.
        $query = User::whereHas('role', function ($q) use ($targetRoleName) {
            $q->where('name', $targetRoleName);
        });

        if ($concern->about_staff_id) {
            $query->where('id', '!=', $concern->about_staff_id);
        }

        $targetUser = $query->first();

        // Conflict-of-interest escalation: if we couldn't find an untainted
        // handler in the target role (e.g. the reported person was the only
        // one), escalate up the chain: Department Head -> Head of School.
        if (! $targetUser && $concern->about_staff_id) {
            foreach (['Department Head', 'Head of School'] as $escalationRole) {
                $escalated = User::whereHas('role', function ($q) use ($escalationRole) {
                    $q->where('name', $escalationRole);
                })->where('id', '!=', $concern->about_staff_id)->first();

                if ($escalated) {
                    $targetUser = $escalated;
                    break;
                }
            }
        }

        if ($targetUser) {
            $concern->update(['assigned_to' => $targetUser->id]);
        }
    }

    /**
     * Notify the assigned department/user of a new concern.
     *
     * Urgency is null until triaged, so the message reflects that.
     */
    private function notifyDepartment(Concern $concern)
    {
        if ($concern->assigned_to) {
            $severity = $concern->urgency ?? 'untriaged';

            Notification::create([
                'user_id' => $concern->assigned_to,
                'type' => 'new_concern',
                'concern_id' => $concern->id,
                'title' => 'New Concern Assigned',
                'message' => "A new {$severity} {$concern->category} concern has been assigned to you and needs triage",
            ]);
        }
    }
}