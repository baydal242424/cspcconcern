{{-- July 2026 UI cleanup: detail fields moved into <dl> rows, section
     headings are proper h2s now, SVG icons instead of emoji, timeline
     shows "in progress" instead of the raw status value, delete got the
     same two-step confirm as the identity reveal, one date format
     everywhere. All the visibility conditionals are unchanged. --}}
@extends('layout')

@section('title', 'Concern Details')

@section('content')
<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
        <h1>Concern #{{ $concern->id }}</h1>
        <div>
            <span class="status-badge status-{{ str_replace(' ', '_', $concern->status) }}">
                {{ $concern->status_label }}@if ($concern->status === 'referred' && $concern->referred_to) → {{ $concern->referred_to }}@endif
            </span>
            <span class="urgency-badge urgency-{{ strtolower($concern->urgency ?? 'pending') }}">
                {{ $concern->urgency ?? 'Pending triage' }}
            </span>
        </div>
    </div>

    <div class="grid-2">
        <div>
            <h2 class="section-title">Concern Details</h2>
            <dl class="detail-list">
                <dt>Category</dt>
                {{-- "Others" on its own tells a handler nothing. What the student
                     called it sits beside the label, so the queue is readable
                     without opening every one. --}}
                <dd>
                    {{ $concern->category }}@if ($concern->other_category)
                        <span style="color:var(--muted);">&mdash; {{ $concern->other_category }}</span>
                    @endif
                </dd>

                <dt>Urgency</dt>
                <dd>{{ $concern->urgency ?? 'Pending triage' }}</dd>

                <dt>Submitted</dt>
                <dd>{{ $concern->created_at->local()->format('M d, Y · g:i A') }}</dd>

                <dt>Submitted by</dt>
                <dd>
                    @if ($concern->is_anonymous)
                        @if (Auth::id() === $concern->user_id)
                            {{ $concern->user->name }} <span style="color:#999;">(you, submitted anonymously)</span>
                        @elseif (optional(Auth::user()->role)->name === 'Head of School' && $concern->identityIsRevealed())
                            {{ $concern->user->name }} <span style="color:#b45309;">(identity disclosed by Head of School)</span>
                        @else
                            <span style="color: #999;">Anonymous Submission</span>
                        @endif
                    @else
                        {{ $concern->user->name }}
                    @endif
                </dd>

                @if ($concern->about_staff_id)
                    <dt>Concern is about</dt>
                    <dd>{{ optional($concern->aboutStaff)->name ?? 'A staff member' }} <span style="color:#b45309; font-size:0.85em;">(routed to a higher authority to avoid a conflict of interest)</span></dd>
                @endif

                <dt>Assigned to</dt>
                <dd>{{ $concern->assignedUser->name ?? 'Unassigned' }}</dd>

                @if ($concern->status === 'referred' && $concern->referred_to)
                    <dt>Referred to</dt>
                    <dd>{{ $concern->referred_to }}</dd>
                @endif

                @if ($concern->resolved_at)
                    <dt>Resolved</dt>
                    <dd>{{ $concern->resolved_at->local()->format('M d, Y · g:i A') }}</dd>
                @endif
            </dl>
        </div>

        <div>
            <h2 class="section-title">Statistics</h2>
            <dl class="detail-list">
                {{-- "Age" said how old the row was; what a handler actually
                     wants to know is how long the student has been waiting,
                     and once it is over, how long they waited in total. Same
                     number, but it answers a question somebody has. --}}
                @php $finishedAt = $concern->resolved_at ?? $concern->closed_at; @endphp
                @if ($finishedAt)
                    <dt>{{ $concern->status === 'resolved' ? 'Time to resolve' : 'Time to close' }}</dt>
                    <dd>{{ $concern->created_at->diffForHumans($finishedAt, true) }}</dd>
                @else
                    <dt>Waiting</dt>
                    <dd>{{ $concern->created_at->diffForHumans(null, true) }}</dd>
                @endif

                <dt>Total updates</dt>
                <dd>{{ $concern->auditLogs->count() }}</dd>
            </dl>
        </div>
    </div>

    <hr style="margin: 2rem 0; border: none; border-top: 1px solid #ddd;">

    <div>
        <h2 class="section-title">Description</h2>
        <p class="user-text">{{ $concern->description }}</p>
    </div>

    {{-- Evidence attachments. Links go through a controller that re-checks
         authorization, so only users allowed to see this concern can download. --}}
    @if ($concern->attachments->count() > 0)
        <hr style="margin: 2rem 0; border: none; border-top: 1px solid #ddd;">
        <div>
            <h2 class="section-title">Evidence Attachments ({{ $concern->attachments->count() }})</h2>
            <div style="display:flex; flex-direction:column; gap:0.6rem;">
                @foreach ($concern->attachments as $attachment)
                    <a href="{{ route('concerns.attachment', [$concern, $attachment]) }}"
                       style="display:flex; align-items:center; gap:0.7rem; padding:0.7rem 0.9rem; border:1px solid #e2e8f0; border-radius:10px; text-decoration:none; color:#1f2733;">
                        <span style="flex-shrink:0; color:#64748b; display:inline-flex;" aria-hidden="true">
                            @if ($attachment->isImage())
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-3.9-3.9a2 2 0 0 0-2.8 0L6 19"/></svg>
                            @else
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>
                            @endif
                        </span>
                        <span style="flex:1; min-width:0;">
                            <span style="display:block; font-weight:600; font-size:0.9rem; overflow-wrap:anywhere; word-break:break-word;">{{ $attachment->original_name }}</span>
                            <span style="color:#64748b; font-size:0.8rem;">{{ strtoupper(str_replace(['image/','application/'],'',$attachment->mime_type)) }} · {{ $attachment->humanSize() }}</span>
                        </span>
                        <span style="color:#2f5bea; font-size:0.85rem; font-weight:600; flex-shrink:0;">Download</span>
                    </a>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Break-glass identity reveal: only for Head of School, only on
         anonymous concerns. Reason required; the action is fully logged. --}}
    @if ($concern->is_anonymous && optional(Auth::user()->role)->name === 'Head of School')
        <hr style="margin: 2rem 0; border: none; border-top: 1px solid #ddd;">
        <div style="background:#fff7ed; border:1px solid #fed7aa; border-radius:12px; padding:1.25rem;">
            <h2 class="section-title" style="margin-bottom: 0.5rem; color:#9a3412; display:flex; align-items:center; gap:0.45rem;">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                Reporter Identity (Restricted)
            </h2>
            @if ($concern->identityIsRevealed())
                <p style="margin-bottom:0.4rem;"><strong>Identity:</strong> {{ $concern->user->name }} ({{ $concern->user->email }})</p>
                <p style="font-size:0.88rem; color:#7c2d12;">
                    Revealed by {{ optional($concern->identityRevealer)->name ?? 'Unknown' }}
                    on {{ $concern->identity_revealed_at?->local()?->format('M d, Y \a\t g:i A') }}.
                </p>
                <p style="font-size:0.88rem; color:#7c2d12; margin-top:0.3rem;"><strong>Reason given:</strong> {{ $concern->identity_reveal_reason }}</p>
            @else
                <p style="font-size:0.9rem; color:#7c2d12; margin-bottom:0.8rem;">
                    This reporter chose to remain anonymous. Revealing their identity is a serious,
                    permanently logged action. Only proceed when justified (for example, a credible
                    safety risk or a suspected false report). A reason is required.
                </p>
                <form action="{{ route('concerns.reveal', $concern) }}" method="POST">
                    @csrf
                    <label for="identity_reveal_reason" style="font-weight:600; font-size:0.9rem;">Reason for revealing identity</label>
                    <textarea name="identity_reveal_reason" id="identity_reveal_reason" rows="3" required
                        placeholder="State the specific justification (at least 20 characters, in a few words)..."
                        style="width:100%; margin:0.4rem 0 0.8rem;">{{ old('identity_reveal_reason') }}</textarea>
                    @error('identity_reveal_reason')
                        <div style="color:#dc3545; font-size:0.85rem; margin-bottom:0.5rem;">{{ $message }}</div>
                    @enderror

                    {{-- Step 1: arm the action (no native browser popup) --}}
                    <button type="button" id="reveal-arm-btn" class="btn"
                        style="background:#b45309; color:#fff;"
                        onclick="document.getElementById('reveal-confirm').style.display='block'; this.style.display='none';">
                        Reveal reporter identity (logged)
                    </button>

                    {{-- Step 2: styled in-page confirmation --}}
                    <div id="reveal-confirm" style="display:none; margin-top:0.9rem; background:#fff; border:1px solid #fed7aa; border-radius:10px; padding:1rem;">
                        <p style="font-size:0.9rem; color:#7c2d12; margin-bottom:0.8rem;">
                            <strong>Please confirm.</strong> This permanently records that <em>you</em> revealed this
                            reporter's identity, with your reason and the time. It cannot be undone.
                        </p>
                        <div style="display:flex; gap:0.6rem; flex-wrap:wrap;">
                            <button type="submit" class="btn" style="background:#b45309; color:#fff;">Yes, reveal and log it</button>
                            <button type="button" class="btn" style="background:#eef1f6; color:#475569;"
                                onclick="document.getElementById('reveal-confirm').style.display='none'; document.getElementById('reveal-arm-btn').style.display='inline-flex';">
                                Cancel
                            </button>
                        </div>
                    </div>
                </form>
            @endif
        </div>
    @endif

    {{-- Activity timeline: answers "referred to whom / when, resolved when".
         Built from the audit log so it is a faithful history of every action. --}}
    @if ($concern->auditLogs->count() > 0)
        <hr style="margin: 2rem 0; border: none; border-top: 1px solid #ddd;">
        <div>
            <h2 class="section-title">Activity Timeline</h2>
            @php
                // The viewer may only see the reporter's name on an anonymous
                // concern if they ARE the reporter, or they are the Head of
                // School after a logged break-glass reveal. Every other actor
                // (staff) is never anonymous.
                $canSeeReporter = ! $concern->is_anonymous
                    || Auth::id() === $concern->user_id
                    || (optional(Auth::user()->role)->name === 'Head of School' && $concern->identityIsRevealed());
            @endphp
            <div style="position: relative; padding-left: 1.25rem;">
                {{-- Sorted by id, not created_at. Submission writes two entries in the same
                     second (the concern, then its auto-assigned urgency), and sorting
                     on a timestamp leaves those tied and falling back to insertion
                     order -- which put the oldest event at the top of a list that is
                     meant to read newest first. The id is monotonic, so it breaks the
                     tie the way the clock cannot. --}}
                @foreach ($concern->auditLogs->sortByDesc('id') as $log)
                    <div style="position: relative; padding-bottom: 1.1rem; border-left: 2px solid #e2e8f0; padding-left: 1.1rem;">
                        <span style="position:absolute; left:-6px; top:2px; width:10px; height:10px; border-radius:50%; background:#2f5bea;"></span>
                        <div style="font-weight:600; color:#1f2733; font-size:0.92rem;">
                            @php
                                if ($log->user_id === $concern->user_id && ! $canSeeReporter) {
                                    $actor = 'Anonymous reporter';
                                } else {
                                    $actor = optional($log->user)->name ?? 'System';
                                }
                            @endphp
                            {{-- Audit descriptions store raw status values (e.g. "in_progress");
                                 swap underscores for spaces so readers see plain English. --}}
                            {{ $log->description ? str_replace('_', ' ', $log->description) : ucfirst(str_replace('_', ' ', $log->action)) }}
                        </div>
                        <div style="color:#64748b; font-size:0.82rem; margin-top:0.15rem;">
                            by {{ $actor }} · {{ $log->created_at->local()->format('M d, Y \a\t g:i A') }}
                            <span style="color:#94a3b8;">({{ $log->created_at->diffForHumans() }})</span>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @if ($concern->investigation_notes)
        <hr style="margin: 2rem 0; border: none; border-top: 1px solid #ddd;">
        <div>
            <h2 class="section-title">Investigation Notes</h2>
            <p class="user-text">{{ $concern->investigation_notes }}</p>
        </div>
    @endif

    @if ($concern->resolution_notes)
        <hr style="margin: 2rem 0; border: none; border-top: 1px solid #ddd;">
        <div>
            <h2 class="section-title">Resolution Notes</h2>
            <p class="user-text">{{ $concern->resolution_notes }}</p>
        </div>
    @endif

    {{-- A concern closed without action gives the reporter no outcome, so the
         reason stands in for one and is deliberately prominent rather than
         tucked in with the staff notes above. --}}
    @if ($concern->status === 'closed_no_action' && $concern->closure_reason)
        <hr style="margin: 2rem 0; border: none; border-top: 1px solid #ddd;">
        <div style="background:var(--warn-bg); border:1px solid #f3dca0; border-radius:12px; padding:1.1rem 1.25rem;">
            <h2 class="section-title" style="margin-bottom:.5rem; color:var(--warn-ink);">Closed without action</h2>
            <p class="user-text closure">{{ $concern->closure_reason }}</p>
            @if ($concern->closed_at)
                <p style="color:var(--muted); font-size:.82rem; margin-top:.6rem;">
                    Closed {{ $concern->closed_at->local()->format('M d, Y · g:i A') }}. If you disagree with this outcome, you may raise it with the Students Affairs and Services Office.
                </p>
            @endif
        </div>
    @endif

    {{-- Reporter feedback: shown to everyone once left; the "leave feedback"
         form is only offered to the reporter, only after resolution, and
         only once (storeFeedback() enforces the same rules server-side). --}}
    @if ($concern->feedback)
        <hr style="margin: 2rem 0; border: none; border-top: 1px solid #ddd;">
        <div>
            <h2 class="section-title">Reporter Feedback</h2>
            <p style="font-size:1.1rem; margin-bottom:0.4rem;">
                @for ($i = 1; $i <= 5; $i++)
                    <span style="color: {{ $i <= $concern->feedback->rating ? '#f5b301' : '#e2e7ef' }};">★</span>
                @endfor
                <span style="color:#64748b; font-size:0.85rem; margin-left:0.3rem;">{{ $concern->feedback->rating }}/5</span>
            </p>
            @if ($concern->feedback->comment)
                <p class="user-text">{{ $concern->feedback->comment }}</p>
            @endif
        </div>
    @elseif ($concern->status === 'resolved' && Auth::id() === $concern->user_id)
        <hr style="margin: 2rem 0; border: none; border-top: 1px solid #ddd;">
        <div>
            <h2 class="section-title">Rate How This Was Handled</h2>
            <form action="{{ route('concerns.feedback', $concern) }}" method="POST">
                @csrf
                <div class="form-group">
                    <label for="rating">Rating</label>
                    <select name="rating" id="rating" required>
                        <option value="">-- Select a rating --</option>
                        <option value="5">★★★★★ (5) Excellent</option>
                        <option value="4">★★★★ (4) Good</option>
                        <option value="3">★★★ (3) Okay</option>
                        <option value="2">★★ (2) Poor</option>
                        <option value="1">★ (1) Very poor</option>
                    </select>
                    @error('rating')
                        <div style="color:#dc3545; font-size:0.85rem; margin-top:0.25rem;">{{ $message }}</div>
                    @enderror
                </div>
                <div class="form-group">
                    <label for="comment">Comment (optional)</label>
                    <textarea name="comment" id="comment" placeholder="Anything you'd like to add about how this was handled...">{{ old('comment') }}</textarea>
                </div>
                <button type="submit" class="btn btn-primary">Submit Feedback</button>
            </form>
        </div>
    @endif

    {{-- Referral history lives in the Activity Timeline above (audit log
         entries), which is the single faithful record of every hand-off. --}}

    @php $viewerRole = optional(Auth::user()->role)->name; @endphp
    @if ($concern->status !== 'resolved' && (Auth::user()->id === $concern->assigned_to || $viewerRole === 'Admin' || $viewerRole === 'Dean' || ($concern->referred_to !== null && $viewerRole === $concern->referred_to)))
        <hr style="margin: 2rem 0; border: none; border-top: 1px solid #ddd;">
        <div>
            <h2 class="section-title" style="margin-bottom: 1.5rem;">Update Concern Status</h2>
            <form action="{{ route('concerns.update', $concern) }}" method="POST">
                @csrf
                @method('PATCH')

                <div class="form-group">
                    <label for="urgency">Severity / Urgency</label>
                    <select name="urgency" id="urgency">
                        <option value="" {{ is_null($concern->urgency) ? 'selected' : '' }}>-- Pending triage --</option>
                        <option value="Low" {{ $concern->urgency === 'Low' ? 'selected' : '' }}>Low</option>
                        <option value="Medium" {{ $concern->urgency === 'Medium' ? 'selected' : '' }}>Medium</option>
                        <option value="High" {{ $concern->urgency === 'High' ? 'selected' : '' }}>High</option>
                        <option value="Critical" {{ $concern->urgency === 'Critical' ? 'selected' : '' }}>Critical</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="status">New Status</label>
                    <select name="status" id="status">
                        <option value="submitted" {{ $concern->status === 'submitted' ? 'selected' : '' }}>Submitted</option>
                        <option value="in_progress" {{ $concern->status === 'in_progress' ? 'selected' : '' }}>In Progress</option>
                        <option value="resolved" {{ $concern->status === 'resolved' ? 'selected' : '' }}>Resolved</option>
                        <option value="referred" {{ $concern->status === 'referred' ? 'selected' : '' }}>Referred</option>
                        <option value="closed_no_action" {{ $concern->status === 'closed_no_action' ? 'selected' : '' }}>Closed — no action needed</option>
                    </select>
                </div>

                {{-- Shown only when closing without action. Required, and the
                     student reads it verbatim, so it is not an internal note. --}}
                <div class="form-group" id="closure-group" @if ($concern->status !== 'closed_no_action') style="display:none;" @endif>
                    <label for="closure_reason">Reason for closing without action *</label>
                    <textarea name="closure_reason" id="closure_reason" rows="3"
                              style="min-height:auto;"
                              placeholder="Explain why no action is being taken. The student will see this.">{{ old('closure_reason', $concern->closure_reason) }}</textarea>
                    <div style="color:var(--muted); font-size:0.82rem; margin-top:0.25rem;">
                        At least 20 characters. This is shown to the student and recorded permanently in the timeline.
                    </div>
                    @error('closure_reason')
                        <div style="color:#dc3545; font-size:0.85rem; margin-top:0.25rem;">{{ $message }}</div>
                    @enderror
                </div>

                {{-- The style attribute is emitted whole rather than built inside
                     one, so editors parse it as plain CSS. The script below
                     toggles display on change. --}}
                <div class="form-group" id="refer-to-group" @if ($concern->status !== 'referred') style="display:none;" @endif>
                    <label for="referred_to">Refer to</label>
                    <select name="referred_to" id="referred_to">
                        <option value="">-- Select destination --</option>
                        {{-- Driven by ConcernController::REFERRAL_ROLE_LABELS so the
                             options offered here and the destinations update()
                             accepts can never fall out of step. --}}
                        @foreach (\App\Http\Controllers\ConcernController::REFERRAL_ROLE_LABELS as $roleValue => $roleLabel)
                            <option value="{{ $roleValue }}" {{ $concern->referred_to === $roleValue ? 'selected' : '' }}>{{ $roleLabel }}</option>
                        @endforeach
                    </select>
                    @error('referred_to')
                        <div style="color:#dc3545; font-size:0.85rem; margin-top:0.25rem;">{{ $message }}</div>
                    @enderror
                </div>

                {{-- Hand the concern to a NAMED colleague instead of letting the
                     system pick someone in that office. Rendered only when
                     there is somebody to pick: the controller has already
                     dropped the subject of the concern, the current handler,
                     yourself and banned accounts, so an office with nobody left
                     is absent from $referralCandidates and this whole group
                     stays out of the page rather than offering an empty list.
                     The script below reveals it only for offices that have
                     people, so "Refer to" alone still works on its own. --}}
                @if ($referralCandidates->isNotEmpty())
                    <div class="form-group" id="refer-person-group" style="display:none;">
                        <label for="referred_to_user_id">Refer to a specific person <span style="font-weight:400; color:var(--muted);">(optional)</span></label>
                        <select name="referred_to_user_id" id="referred_to_user_id">
                            <option value="">-- Let the system choose --</option>
                            @foreach ($referralCandidates as $officeName => $people)
                                @foreach ($people as $person)
                                    <option value="{{ $person->id }}" data-role="{{ $officeName }}"
                                        {{ (string) old('referred_to_user_id') === (string) $person->id ? 'selected' : '' }}>
                                        {{ $person->name }}@if ($person->department) — {{ $person->department }}@endif
                                    </option>
                                @endforeach
                            @endforeach
                        </select>
                        <div style="color:var(--muted); font-size:0.82rem; margin-top:0.25rem;">
                            Pre-filled with the handler for the reporter's own programme, or
                            their college where no one covers the programme. Change it to send
                            this to somebody else in that office.
                        </div>
                        @error('referred_to_user_id')
                            <div style="color:#dc3545; font-size:0.85rem; margin-top:0.25rem;">{{ $message }}</div>
                        @enderror
                    </div>
                @endif

                <script>
                    (function () {
                        var statusEl = document.getElementById('status');
                        var referGroup = document.getElementById('refer-to-group');
                        var closureGroup = document.getElementById('closure-group');
                        var officeEl = document.getElementById('referred_to');
                        var personGroup = document.getElementById('refer-person-group');
                        var personEl = document.getElementById('referred_to_user_id');

                        if (!statusEl) {
                            return;
                        }

                        // Show only the people who belong to the office that is
                        // currently selected, and keep the group hidden when
                        // that office has none -- an empty picker is worse than
                        // no picker. Non-matching options are disabled as well
                        // as hidden so a stale selection can never be posted.
                        function syncPeople() {
                            if (!personGroup || !personEl || !officeEl) {
                                return;
                            }

                            var office = officeEl.value;
                            var matches = 0;
                            var firstMatch = '';
                            var currentStillValid = false;

                            Array.prototype.forEach.call(personEl.options, function (option) {
                                if (!option.value) {
                                    return; // the "let the system choose" placeholder
                                }

                                var belongs = option.getAttribute('data-role') === office;
                                option.hidden = !belongs;
                                option.disabled = !belongs;

                                if (!belongs) {
                                    return;
                                }

                                matches++;

                                if (!firstMatch) {
                                    firstMatch = option.value;
                                }
                                if (option.value === personEl.value) {
                                    currentStillValid = true;
                                }
                            });

                            var show = statusEl.value === 'referred' && matches > 0;
                            personGroup.style.display = show ? 'block' : 'none';

                            if (!show) {
                                personEl.value = '';
                                return;
                            }

                            // Name the recipient by default rather than making
                            // the sender pick. The list is already ordered the
                            // way the system would choose -- the reporter's own
                            // programme first, then their college -- so the top
                            // entry IS the covering dean or chair. Showing it
                            // selected means the referrer sees WHO this is going
                            // to before they save, instead of a placeholder that
                            // hides the decision until it is already made.
                            if (!currentStillValid) {
                                personEl.value = firstMatch;
                            }
                        }

                        function sync() {
                            if (referGroup) {
                                referGroup.style.display = (statusEl.value === 'referred') ? 'block' : 'none';
                            }
                            if (closureGroup) {
                                closureGroup.style.display = (statusEl.value === 'closed_no_action') ? 'block' : 'none';
                            }
                            syncPeople();
                        }

                        statusEl.addEventListener('change', sync);
                        if (officeEl) {
                            officeEl.addEventListener('change', syncPeople);
                        }

                        // Run once on load so a form that came back with old
                        // input (a validation error) opens on the same fields
                        // the user was last looking at.
                        sync();
                    })();
                </script>

                <div class="form-group">
                    <label for="investigation_notes">Investigation Notes</label>
                    <textarea name="investigation_notes" id="investigation_notes" placeholder="What did you find while looking into this? (visible once saved)">{{ $concern->investigation_notes }}</textarea>
                </div>

                <div class="form-group">
                    <label for="resolution_notes">Resolution Notes</label>
                    <textarea name="resolution_notes" id="resolution_notes" placeholder="Add any notes about this concern...">{{ $concern->resolution_notes }}</textarea>
                </div>

                <button type="submit" class="btn btn-success">Update Concern</button>
            </form>
        </div>
    @endif

    <hr style="margin: 2rem 0; border: none; border-top: 1px solid #ddd;">
    <div style="display: flex; justify-content: center; gap: 1rem; flex-wrap: wrap;">
        <a href="{{ route('concerns.index') }}" class="btn btn-muted">Back to List</a>

        @if (Auth::user()->id === $concern->user_id && $concern->status === 'submitted')
            <button type="button" id="delete-arm-btn" class="btn btn-ghost-danger"
                onclick="document.getElementById('delete-confirm').style.display='block'; this.style.display='none';">
                Delete Concern
            </button>
        @endif
    </div>

    {{-- In-page delete confirmation, same two-step pattern as the identity
         reveal above -- no native browser popup. --}}
    @if (Auth::user()->id === $concern->user_id && $concern->status === 'submitted')
        <div id="delete-confirm" style="display:none; margin-top:1rem; background:#fff; border:1px solid #f6c9cf; border-radius:10px; padding:1rem; max-width:480px; margin-left:auto; margin-right:auto;">
            <p style="font-size:0.9rem; color:#a31726; margin-bottom:0.8rem;">
                <strong>Delete this concern?</strong> This permanently removes it and cannot be undone.
            </p>
            <div style="display:flex; gap:0.6rem; flex-wrap:wrap; justify-content:center;">
                <form action="{{ route('concerns.destroy', $concern) }}" method="POST" style="display:inline-block; margin:0;">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-danger">Yes, delete it</button>
                </form>
                <button type="button" class="btn btn-muted"
                    onclick="document.getElementById('delete-confirm').style.display='none'; document.getElementById('delete-arm-btn').style.display='inline-flex';">
                    Cancel
                </button>
            </div>
        </div>
    @endif
</div>
@endsection