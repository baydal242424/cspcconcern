{{-- July 2026 UI cleanup: detail fields moved into <dl> rows, section
     headings are proper h2s now, SVG icons instead of emoji, timeline
     shows "in progress" instead of the raw status value, delete got the
     same two-step confirm as the identity reveal, one date format
     everywhere. All the visibility conditionals are unchanged. --}}
@extends('layout')

@section('title', 'Concern Details')

@section('content')

    @php
        // Staff read the case as handlers; the reporter reads it as the
        // person it belongs to. The difference decides what the page is
        // allowed to say about who is holding it.
        $viewerIsStaff = Auth::user()->isEmployee();
    @endphp
<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
        <h1>Concern #{{ $concern->id }}</h1>
        <div>
            <span class="status-badge status-{{ str_replace(' ', '_', $concern->status) }}">
                {{ $concern->status_label }}@if ($viewerIsStaff && $concern->status === 'referred' && $concern->referred_to) → {{ $concern->referred_to }}@endif
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
                    {{ $concern->category_label }}@if ($concern->other_category)
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

                {{-- Programme and section as they were when the concern was filed
                     (snapshotted on the concern, so a later year-level promotion
                     does not rewrite it). They decided which class adviser it
                     reached, so the handler should be able to see them.

                     Same visibility as the name above. A section is a class of
                     forty; "BS Information Technology 1F" beside an anonymous
                     submission would all but name the student. --}}
                @php
                    $reporterVisible = ! $concern->is_anonymous
                        || Auth::id() === $concern->user_id
                        || (optional(Auth::user()->role)->name === 'Head of School' && $concern->identityIsRevealed());
                    $sectionParts = [];
                    preg_match('/^([1-6])([A-Za-z])$/', trim((string) $concern->section), $sectionParts);

                    // Advising is a section assignment, not a role, so the
                    // class adviser of 4A shows as "Instructor" -- beside a
                    // concern that reached them precisely BECAUSE they advise
                    // 4A, which reads as the wrong person having it. Said
                    // first, before the role, because it is the reason they
                    // are on this concern at all.
                    //
                    // Read from the section as it stands now rather than from
                    // the concern: if the section has since changed hands, the
                    // person holding it today is who the label is true of.
                    $sectionAdviserId = optional(\App\Models\Section::adviserFor($concern->course, $concern->section))->id;

                    // Who somebody is, in one line: their part in this concern,
                    // then their role, then where they sit. A name alone leaves
                    // the reader guessing whether the concern is with a chair, a
                    // counselor or the dean's office.
                    $describe = function ($person) use ($sectionAdviserId) {
                        // "Class adviser" REPLACES the role rather than sitting
                        // beside it. Advising is what puts them on this concern,
                        // and "Class adviser · Instructor" invited the reading
                        // that two different people were meant. Their role is
                        // still on their account, and Manage Users still shows
                        // it; this line answers "who is this to me?".
                        $role = $person->id === $sectionAdviserId
                            ? 'Class adviser'
                            : optional($person->role)->name;

                        return implode(' · ', array_filter([
                            $role,
                            $person->department,
                            // Dropped when the name already carries it: an office
                            // account named "BS Nursing Program Chair" does not
                            // need "· BS Nursing" after it.
                            $person->course && ! str_contains($person->name, $person->course)
                                ? $person->course
                                : null,
                        ]));
                    };
                @endphp
                @if ($concern->course || $concern->section)
                    @if ($reporterVisible)
                        @if ($concern->course)
                            <dt>Program</dt>
                            <dd>{{ $concern->course }}</dd>
                        @endif

                        @if ($concern->section)
                            <dt>Year &amp; section</dt>
                            <dd>
                                @if ($sectionParts)
                                    Year {{ $sectionParts[1] }} &middot; Section {{ strtoupper($sectionParts[2]) }}
                                    <span style="color:var(--muted);">({{ strtoupper($concern->section) }})</span>
                                @else
                                    {{ $concern->section }}
                                @endif
                            </dd>
                        @endif
                    @else
                        <dt>Year &amp; section</dt>
                        <dd><span style="color:#999;">Withheld &mdash; anonymous submission</span></dd>
                    @endif
                @endif

                {{-- Why this skipped a tier. The chair who receives it should
                     not have to guess whether the adviser was bypassed on
                     purpose or simply missing. --}}
                @if ($concern->skip_adviser)
                    <dt>Class adviser</dt>
                    <dd><span style="color:#b45309;">Skipped at the student's request</span></dd>
                @endif

                @if ($concern->about_staff_id)
                    {{-- Every named person, not just the first: the handler
                         has to know who is walled out of this concern, and
                         "about" one of two reported instructors would read as
                         though the other were uninvolved. --}}
                    @php $named = $concern->subjects; @endphp
                    <dt>Concern is about</dt>
                    <dd>
                        @php $about = $named->isEmpty() ? collect([$concern->aboutStaff])->filter() : $named; @endphp
                        @forelse ($about as $person)
                            <div>
                                {{ $person->name }}
                                <span style="color:var(--muted);">&mdash; {{ $describe($person) ?: 'no role recorded' }}</span>
                            </div>
                        @empty
                            A staff member
                        @endforelse
                        <span style="color:#b45309; font-size:0.85em;">(routed to a higher authority to avoid a conflict of interest)</span>
                    </dd>
                @endif

                {{-- Who is holding the case is staff business. A reporter who
                     can read it here can follow their own report around the
                     college desk by desk, which is exactly what the Activity
                     Timeline below stopped showing them. --}}
                @if ($viewerIsStaff)
                    <dt>Assigned to</dt>
                    <dd>
                        @if ($concern->assignedUser)
                            {{ $concern->assignedUser->name }}
                            <span style="color:var(--muted);">&mdash; {{ $describe($concern->assignedUser) ?: 'no role recorded' }}</span>
                        @else
                            Unassigned
                        @endif
                    </dd>

                    @if ($concern->status === 'referred' && $concern->referred_to)
                        <dt>Referred to</dt>
                        <dd>{{ $concern->referred_to }}</dd>
                    @endif
                @else
                    {{-- The reporter is told WHO has their case, by name.
                         It read "An office of the college" for a while, which
                         was a step too far: a student with a complaint needs
                         somebody to follow it up with, and an office that
                         names nobody is the thing people complain about.

                         What they still do not get is the ROUTE -- the list
                         of every desk it passed through on the way here. That
                         is the part the Activity Timeline below collapses,
                         and the part that would let them work out who read
                         it. Who holds it now is accountability; who held it
                         before is a map. --}}
                    <dt>Being handled by</dt>
                    <dd>
                        @if ($concern->assignedUser)
                            {{ $concern->assignedUser->name }}
                            <span style="color:var(--muted);">&mdash; {{ $describe($concern->assignedUser) ?: 'no role recorded' }}</span>
                        @else
                            Not yet assigned
                        @endif
                    </dd>
                @endif

                {{-- When the desk holding it received it, which is not the
                     filing date once a case has moved: a concern filed a
                     fortnight ago may have reached its present handler an
                     hour ago.

                     For the person holding it, and nobody else. "How long
                     have you had this" is a question about one desk, so it is
                     answered on that desk's screen. On the reporter's page it
                     sat directly under the handler's name, which made the
                     pair read as a record of the hand-off they are not shown;
                     for a dean or an admin looking in, it is a timestamp
                     about somebody else's work. What the reporter gets is
                     Waiting, below: how long since THEY filed it. --}}
                @if ($concern->assignedUser && Auth::id() === $concern->assigned_to)
                    <dt>Received</dt>
                    <dd>
                        {{ $concern->receivedAt()->local()->format('M d, Y · g:i A') }}
                        <span style="color:var(--muted);">({{ $concern->receivedAt()->diffForHumans() }})</span>
                    </dd>
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
                    {{-- Floored to a minute. A concern opened straight after
                         filing read "3 seconds", which looks like a stopwatch
                         rather than a queue and told nobody anything. --}}
                    <dd>{{ $concern->created_at->diffInSeconds() < 60
                            ? 'Less than a minute'
                            : $concern->created_at->diffForHumans(null, true) }}</dd>
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
    @php $timeline = $concern->timelineFor(Auth::user()); @endphp
    @if ($timeline->isNotEmpty())
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
                {{-- Entries come from Concern::timelineFor(), which decides what
                     this viewer may read: staff get the audit log in full, the
                     reporter gets the state of their own case without the names
                     of the people holding it. The order is set there too --
                     sorted by id, not created_at, because submission writes two
                     entries in the same second and a timestamp leaves those tied,
                     falling back to insertion order and putting the oldest event
                     at the top of a list meant to read newest first. --}}
                @foreach ($timeline as $entry)
                    @php $log = $entry['log']; @endphp
                    <div style="position: relative; padding-bottom: 1.1rem; border-left: 2px solid #e2e8f0; padding-left: 1.1rem;">
                        <span style="position:absolute; left:-6px; top:2px; width:10px; height:10px; border-radius:50%; background:#2f5bea;"></span>
                        <div style="font-weight:600; color:#1f2733; font-size:0.92rem;">
                            {{ $entry['label'] }}
                        </div>
                        <div style="color:#64748b; font-size:0.82rem; margin-top:0.15rem;">
                            @if ($entry['showActor'])
                                @php
                                    if ($log->user_id === $concern->user_id && ! $canSeeReporter) {
                                        $actor = 'Anonymous reporter';
                                    } else {
                                        $actor = optional($log->user)->name ?? 'System';
                                    }
                                @endphp
                                by {{ $actor }} ·
                            @endif
                            {{ $log->created_at->local()->format('M d, Y \a\t g:i A') }}
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
            <h2 class="section-title">{{ $concern->handlingNoteLabel() }}</h2>
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

    {{-- Reporting it again. Not a reopen: the first finding stays exactly as
         it is, because a second incident is the one thing that must not be
         allowed to overwrite the first. The new concern carries a link back,
         so whoever picks it up starts out knowing this has happened before. --}}
    @if ($concern->canBeReportedAgainBy(Auth::user()))
        <hr style="margin: 2rem 0; border: none; border-top: 1px solid #ddd;">
        <div>
            <h2 class="section-title" style="margin-bottom:.4rem;">Has this happened again?</h2>
            <p style="color:var(--muted); font-size:.9rem; margin:0 0 .9rem; max-width:62ch;">
                This concern is settled. If the same thing has happened since, report it
                again &mdash; it opens a new case linked to this one, so the staff who
                handle it can see it is not the first time.
            </p>
            <a href="{{ route('concerns.create', ['follows_up_on' => $concern->id]) }}"
               class="btn btn-primary">Report this again</a>
        </div>
    @endif

    {{-- Earlier reports of the same thing, and later ones. A handler opening
         a harassment case needs to know at a glance that it is the second
         time, not read to the bottom of a timeline to find out. --}}
    @if ($concern->followsUpOn || $concern->followUps->isNotEmpty())
        <hr style="margin: 2rem 0; border: none; border-top: 1px solid #ddd;">
        <div>
            <h2 class="section-title" style="margin-bottom:.6rem;">Reported before</h2>
            <ul style="margin:0; padding-left:1.1rem; color:var(--ink); font-size:.92rem; line-height:1.7;">
                @if ($concern->followsUpOn)
                    <li>
                        Follows
                        <a href="{{ route('concerns.show', $concern->followsUpOn) }}">#{{ $concern->followsUpOn->id }}</a>,
                        settled
                        {{ optional($concern->followsUpOn->resolved_at ?: $concern->followsUpOn->updated_at)->local()->format('M d, Y') }}.
                    </li>
                @endif
                @foreach ($concern->followUps as $later)
                    <li>
                        Reported again as
                        <a href="{{ route('concerns.show', $later) }}">#{{ $later->id }}</a>
                        on {{ $later->created_at->local()->format('M d, Y') }}.
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Referral history lives in the Activity Timeline above (audit log
         entries), which is the single faithful record of every hand-off. --}}

    @php $viewerRole = optional(Auth::user()->role)->name; @endphp
    @if ($concern->status !== 'resolved' && (Auth::user()->id === $concern->assigned_to || in_array($viewerRole, ['System Admin', 'Staff Admin', 'Dean'], true) || ($concern->referred_to !== null && $viewerRole === $concern->referred_to)))
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
                        {{-- "Refer", not "Referred". This list is a set of
                             actions to take, and the other entries read that
                             way; the past tense made it look like a state the
                             concern was already in rather than the thing the
                             handler is about to do. The stored value and the
                             status label elsewhere are unchanged. --}}
                        <option value="referred" {{ $concern->status === 'referred' ? 'selected' : '' }}>Refer</option>
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
                        {{-- Only the offices with somebody eligible in them.
                             The labels still come from
                             ConcernController::REFERRAL_ROLE_LABELS, so what
                             is offered and what update() accepts cannot fall
                             out of step -- this only leaves out the ones that
                             would be refused anyway.

                             It is why an adviser is not offered "Adviser":
                             the only person in it is the one doing the
                             referring, and handing a case back to the role
                             already holding it is not a hand-off. --}}
                        @foreach ($referralDestinations as $roleValue => $roleLabel)
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
                    {{-- Shown in place of the dropdown when the chosen office
                         holds exactly one eligible person: the same
                         information, with nothing to click. --}}
                    <p id="refer-sole-recipient" hidden
                       style="color:var(--muted); font-size:.85rem; margin:-0.4rem 0 1rem;"></p>

                    <div class="form-group" id="refer-person-group" style="display:none;">
                        <label for="referred_to_user_id">Refer to a specific person <span style="font-weight:400; color:var(--muted);">(optional)</span></label>
                        <select name="referred_to_user_id" id="referred_to_user_id">
                            <option value="">-- Let the system choose --</option>
                            @foreach ($referralCandidates as $officeName => $people)
                                @foreach ($people as $person)
                                    <option value="{{ $person->id }}" data-role="{{ $officeName }}"
                                        {{ (string) old('referred_to_user_id') === (string) $person->id ? 'selected' : '' }}>
                                        {{-- A Program Chair heads one programme, and that
                                             is what distinguishes them: four Computer
                                             Studies chairs all read "— College of Computer
                                             Studies", which says nothing about which of
                                             them owns the concern in hand. Routing already
                                             prefers the chair of the reporter's own
                                             programme, so the label should show what the
                                             system is matching on. Everyone else keeps the
                                             college, which is what distinguishes them. --}}
                                        {{-- And the class adviser is marked as one. The list
                                             groups by office, so the adviser of the reporter's
                                             section sits under "Instructor" like any other --
                                             the one name in there with a standing claim on this
                                             concern, and nothing to say so. --}}
                                        {{-- One expression rather than a run of @if/@endif:
                                             Blade does not compile a directive that starts
                                             immediately after @endif, so the second condition
                                             printed as literal text in the dropdown. --}}
                                        {{ $person->name }}{{ $person->course ? ' — '.$person->course : ($person->department ? ' — '.$person->department : '') }}{{ $person->id === $sectionAdviserId ? ' · class adviser' : '' }}
                                    </option>
                                @endforeach
                            @endforeach
                        </select>
                        <div style="color:var(--muted); font-size:0.82rem; margin-top:0.25rem;">
                            Pre-filled with the handler for the reporter's own program, or
                            their college where no one covers the program. Change it to send
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
                        var soleOnly = document.getElementById('refer-sole-recipient');

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

                            var referring = statusEl.value === 'referred';

                            // One person in that office means there is nothing
                            // to choose: picking them and letting the system
                            // choose reach the same desk. The dropdown is put
                            // away and a line says who it is going to instead.
                            //
                            // Two or more and it comes back by itself -- this
                            // counts the options rather than naming any office,
                            // so adding a second chair or dean is all it takes.
                            var soleRecipient = referring && matches === 1;
                            var show = referring && matches > 1;

                            personGroup.style.display = show ? 'block' : 'none';

                            if (soleOnly) {
                                soleOnly.hidden = !soleRecipient;

                                if (soleRecipient) {
                                    var only = null;

                                    Array.prototype.forEach.call(personEl.options, function (option) {
                                        if (option.value && !option.hidden) {
                                            only = option;
                                        }
                                    });

                                    soleOnly.textContent = only
                                        ? 'This goes to ' + only.textContent.trim() + '.'
                                        : '';
                                }
                            }

                            if (!show) {
                                // Cleared either way. With one recipient the
                                // server picks the same person, and a value
                                // left in a hidden control is a choice nobody
                                // made.
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

                {{-- Both are required on every save. The browser blocks an
                     empty one before the request leaves, and the server
                     checks again -- required is a rule, and a rule enforced
                     only in the page is a suggestion. --}}
                <div class="form-group">
                    {{-- Named for the work the category actually asks for.
                         "Investigation Notes" was the label on all eleven,
                         and most of them are not investigations: a grade
                         query is a record being checked, a dead lab PC is
                         inspected, a counselling case is written up. --}}
                    <label for="investigation_notes">{{ $concern->handlingNoteLabel() }} *</label>
                    <textarea name="investigation_notes" id="investigation_notes" required minlength="3"
                              placeholder="{{ $concern->handlingNoteHint() }}">{{ old('investigation_notes', $concern->investigation_notes) }}</textarea>
                    @error('investigation_notes')
                        <p style="color: var(--danger-ink); font-size: 0.85rem; margin-top: 0.35rem;">{{ $message }}</p>
                    @enderror
                </div>

                <div class="form-group">
                    <label for="resolution_notes">Resolution Notes *</label>
                    <textarea name="resolution_notes" id="resolution_notes" required minlength="3"
                              placeholder="What is being done about this?">{{ old('resolution_notes', $concern->resolution_notes) }}</textarea>
                    @error('resolution_notes')
                        <p style="color: var(--danger-ink); font-size: 0.85rem; margin-top: 0.35rem;">{{ $message }}</p>
                    @enderror
                    <p style="color: var(--muted); font-size: 0.82rem; margin-top: 0.35rem;">
                        The student reads both of these.
                    </p>
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