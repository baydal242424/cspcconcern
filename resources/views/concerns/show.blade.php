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
                {{ ucfirst(str_replace('_', ' ', $concern->status)) }}@if ($concern->status === 'referred' && $concern->referred_to) → {{ $concern->referred_to }}@endif
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
                <dd>{{ $concern->category }}</dd>

                <dt>Urgency</dt>
                <dd>{{ $concern->urgency ?? 'Pending triage' }}</dd>

                <dt>Submitted</dt>
                <dd>{{ $concern->created_at->format('M d, Y · g:i A') }}</dd>

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
                    <dd>{{ $concern->resolved_at->format('M d, Y · g:i A') }}</dd>
                @endif
            </dl>
        </div>

        <div>
            <h2 class="section-title">Statistics</h2>
            <dl class="detail-list">
                <dt>Age</dt>
                <dd>{{ $concern->created_at->diffForHumans() }}</dd>

                <dt>Total updates</dt>
                <dd>{{ $concern->auditLogs->count() }}</dd>
            </dl>
        </div>
    </div>

    <hr style="margin: 2rem 0; border: none; border-top: 1px solid #ddd;">

    <div>
        <h2 class="section-title">Description</h2>
        <p style="line-height: 1.6; color: #555;">{{ $concern->description }}</p>
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
                    on {{ optional($concern->identity_revealed_at)->format('M d, Y \a\t g:i A') }}.
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
                @foreach ($concern->auditLogs->sortByDesc('created_at') as $log)
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
                            by {{ $actor }} · {{ $log->created_at->format('M d, Y \a\t g:i A') }}
                            <span style="color:#94a3b8;">({{ $log->created_at->diffForHumans() }})</span>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @if ($concern->resolution_notes)
        <hr style="margin: 2rem 0; border: none; border-top: 1px solid #ddd;">
        <div>
            <h2 class="section-title">Resolution Notes</h2>
            <p style="line-height: 1.6; color: #555;">{{ $concern->resolution_notes }}</p>
        </div>
    @endif

    {{-- Referral history lives in the Activity Timeline above (audit log
         entries), which is the single faithful record of every hand-off. --}}

    @php $viewerRole = optional(Auth::user()->role)->name; @endphp
    @if ($concern->status !== 'resolved' && (Auth::user()->id === $concern->assigned_to || $viewerRole === 'Admin' || $viewerRole === 'Department Head' || ($concern->referred_to !== null && $viewerRole === $concern->referred_to)))
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
                    </select>
                </div>

                <div class="form-group" id="refer-to-group" style="{{ $concern->status === 'referred' ? '' : 'display:none;' }}">
                    <label for="referred_to">Refer to</label>
                    <select name="referred_to" id="referred_to">
                        <option value="">-- Select destination --</option>
                        <option value="Guidance Counselor" {{ $concern->referred_to === 'Guidance Counselor' ? 'selected' : '' }}>Guidance Counselor</option>
                        <option value="Admin" {{ $concern->referred_to === 'Admin' ? 'selected' : '' }}>Admin</option>
                        <option value="Department Head" {{ $concern->referred_to === 'Department Head' ? 'selected' : '' }}>Department Head</option>
                        <option value="Faculty/Staff" {{ $concern->referred_to === 'Faculty/Staff' ? 'selected' : '' }}>Faculty/Staff</option>
                    </select>
                    @error('referred_to')
                        <div style="color:#dc3545; font-size:0.85rem; margin-top:0.25rem;">{{ $message }}</div>
                    @enderror
                </div>

                <script>
                    (function () {
                        var statusEl = document.getElementById('status');
                        var referGroup = document.getElementById('refer-to-group');
                        if (statusEl && referGroup) {
                            statusEl.addEventListener('change', function () {
                                referGroup.style.display = (this.value === 'referred') ? 'block' : 'none';
                            });
                        }
                    })();
                </script>

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
            <a href="{{ route('concerns.edit', $concern) }}" class="btn btn-secondary">Edit Concern</a>
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