{{-- July 2026 UI cleanup: quieter delete button so View stays the one
     primary action per row, single date format, role-aware empty state,
     and the bootstrap-4 paginator view (the default Tailwind one renders
     unstyled here). Queries and routes untouched. --}}
@extends('layout')

@section('title', 'My Concerns')

@section('content')

    @php
        // Staff read this list as handlers; a student reads their own
        // reports. The difference decides whether a row may say which
        // office is holding the case.
        $viewerIsStaff = Auth::user()->isEmployee();
    @endphp
<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; gap: 1rem; flex-wrap: wrap;">
        {{-- A student is reading their own file, not a queue of other
             people's. Staff keep "Student Concerns", which is what theirs is. --}}
        <div>
            <h1>{{ optional(Auth::user()->role)->name === 'Student' ? 'My Concerns' : 'Student Concerns' }}</h1>
            {{-- Which pile is on screen. The two buttons swap the list rather
                 than adding to it, so without this line a page showing only
                 finished cases looks like a page that has lost the rest. --}}
            <p style="color:#64748b; font-size:0.85rem; margin-top:0.25rem;">
                {{ $showResolved ? 'Finished — resolved and closed' : 'Active — still being handled' }}
            </p>
        </div>
        <div style="display: flex; gap: 0.6rem; align-items: center;">
            @if ($showResolved)
                <a href="{{ route('concerns.index') }}" class="btn btn-muted">Show active</a>
            @else
                <a href="{{ route('concerns.index', ['show_resolved' => 1]) }}" class="btn btn-muted">Show resolved</a>
            @endif
            {{-- Only once there is a list to sit above. With nothing filed,
                 the empty state below has its own button, and two identical
                 buttons on one screen read as two different things -- students
                 in the survey kept asking which one to press. --}}
            @if (optional(Auth::user()->role)->name === 'Student' && $concerns->count() > 0)
                <a href="{{ route('concerns.create') }}" class="btn btn-primary">+ New Concern</a>
            @endif
        </div>
    </div>

    {{-- Filter bar. A plain GET form: every filter ends up in the URL, so a
         staff member can bookmark "my open Harassment cases" and a student can
         send themselves a link to last term's concerns. The controller
         whitelists each value and applies them after visibleTo(), so nothing
         here can widen what the role may read.

         Search and the two most-reached-for filters sit on the line; urgency,
         dates and order live behind "More". All seven in a row made a wall of
         boxes that wrapped badly and upstaged the table it was filtering. --}}
    @php
        $currentFilters = array_filter([
            'show_resolved' => $showResolved ? 1 : null,
            'q' => $filters['q'],
            'category' => $filters['category'],
            'status' => $filters['status'],
            'urgency' => $filters['urgency'],
            'from' => optional($filters['from'])->format('Y-m-d'),
            'to' => optional($filters['to'])->format('Y-m-d'),
            'sort' => $filters['sort'] === 'oldest' ? 'oldest' : null,
        ]);

        // A chip drops its own filter and keeps the rest, so narrowing can be
        // undone one step at a time instead of only all at once.
        $withoutFilter = fn (string $key) => route('concerns.index', array_diff_key($currentFilters, [$key => true]));

        // What is hidden behind "More" right now, so the button can say so
        // rather than leaving an active filter out of sight.
        $extraFilterCount = count(array_filter([$filters['urgency'], $filters['from'], $filters['to']]));
    @endphp

    <form method="GET" action="{{ route('concerns.index') }}" class="filters">
        @if ($showResolved)
            <input type="hidden" name="show_resolved" value="1">
        @endif

        <input type="text" name="q" value="{{ $filters['q'] }}" class="search"
               aria-label="Search concerns" placeholder="Search a number or a word">

        <button type="button" class="btn btn-muted filter-btn" data-open-filters
                aria-haspopup="dialog" style="padding:.52rem 1rem; font-size:.86rem;">
            {{-- Sliders, so the button reads as "filters" before the word does. --}}
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" aria-hidden="true">
                <path d="M4 7h10M18 7h2M4 17h4M12 17h8"/>
                <circle cx="16" cy="7" r="2"/><circle cx="10" cy="17" r="2"/>
            </svg>
            Filters{{ $activeFilterCount > 0 ? " ({$activeFilterCount})" : '' }}
        </button>

        <button type="submit" class="btn btn-muted" style="padding:.52rem 1rem; font-size:.86rem;">Search</button>

        {{-- Inside the form on purpose: the dialog's controls submit with the
             search box above, so Done applies everything in one request. --}}
        <dialog class="filter-dialog" id="filter-dialog" aria-labelledby="filter-dialog-title">
            <div class="fd-head">
                <div>
                    <h3 id="filter-dialog-title">Filter concerns</h3>
                    <p>This only narrows your own list. It changes nothing for anybody else.</p>
                </div>
                <button type="button" class="fd-close" data-close-filters aria-label="Close">&times;</button>
            </div>

            <div class="fd-body">
                <div class="fd-row">
                    <label for="filter-category">Category</label>
                    <select id="filter-category" name="category">
                        <option value="">All categories</option>
                        @foreach (\App\Models\Concern::CATEGORIES as $category)
                            <option value="{{ $category }}" @selected($filters['category'] === $category)>{{ \App\Models\Concern::categoryLabel($category) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="fd-row">
                    <label for="filter-status">Status</label>
                    <select id="filter-status" name="status">
                        <option value="">Any status</option>
                        @foreach (\App\Models\Concern::STATUS_LABELS as $value => $label)
                            <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="fd-row">
                    <label for="filter-urgency">Urgency</label>
                    <select id="filter-urgency" name="urgency">
                        <option value="">Any urgency</option>
                        @foreach (['Critical', 'High', 'Medium', 'Low'] as $level)
                            <option value="{{ $level }}" @selected($filters['urgency'] === $level)>{{ $level }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="fd-row">
                    <label for="filter-from">Filed from</label>
                    <input type="date" id="filter-from" name="from" value="{{ optional($filters['from'])->format('Y-m-d') }}">
                </div>
                <div class="fd-row">
                    <label for="filter-to">Filed until</label>
                    <input type="date" id="filter-to" name="to" value="{{ optional($filters['to'])->format('Y-m-d') }}">
                </div>
                <div class="fd-row">
                    <label for="filter-sort">Order</label>
                    <select id="filter-sort" name="sort">
                        <option value="newest" @selected($filters['sort'] === 'newest')>Newest first</option>
                        <option value="oldest" @selected($filters['sort'] === 'oldest')>Oldest first</option>
                    </select>
                </div>
            </div>

            <div class="fd-foot">
                <a href="{{ route('concerns.index', $showResolved ? ['show_resolved' => 1] : []) }}">Clear</a>
                <button type="submit" class="btn btn-primary" style="padding:.55rem 1.5rem; font-size:.9rem;">Done</button>
            </div>
        </dialog>
    </form>

    @if ($activeFilterCount > 0)
        <div class="filter-chips">
            <span>{{ $concerns->total() }} {{ Str::plural('concern', $concerns->total()) }} &middot;</span>
            @foreach (['q' => 'search', 'category' => 'category', 'status' => 'status', 'urgency' => 'urgency'] as $key => $label)
                @if ($filters[$key])
                    <span class="filter-chip">
                        {{ $key === 'status' ? \App\Models\Concern::label($filters[$key]) : $filters[$key] }}
                        <a href="{{ $withoutFilter($key) }}" title="Remove this {{ $label }} filter" aria-label="Remove this {{ $label }} filter">&times;</a>
                    </span>
                @endif
            @endforeach
            @if ($filters['from'])
                <span class="filter-chip">From {{ $filters['from']->format('M j, Y') }}
                    <a href="{{ $withoutFilter('from') }}" title="Remove this date" aria-label="Remove the start date">&times;</a></span>
            @endif
            @if ($filters['to'])
                <span class="filter-chip">Until {{ $filters['to']->format('M j, Y') }}
                    <a href="{{ $withoutFilter('to') }}" title="Remove this date" aria-label="Remove the end date">&times;</a></span>
            @endif
            <a href="{{ route('concerns.index', $showResolved ? ['show_resolved' => 1] : []) }}" style="color:var(--muted); text-decoration:underline;">Clear all</a>
        </div>
    @endif

    @if ($concerns->count() > 0)
        <div class="table-wrap">
            <table class="table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Category</th>
                    <th>Urgency</th>
                    <th>Status</th>
                    <th>Submitted</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($concerns as $concern)
                    <tr>
                        <td>#{{ $concern->id }}</td>
                        <td>{{ $concern->category_label }}</td>
                        <td>
                            <span class="urgency-badge urgency-{{ strtolower($concern->urgency ?? 'pending') }}">
                                {{ $concern->urgency ?? 'Pending' }}
                            </span>
                        </td>
                        <td>
                            <span class="status-badge status-{{ str_replace(' ', '_', $concern->status) }}">
                                {{ $concern->status_label }}
                            </span>
                            {{-- Staff only: which office is holding a case is
                                 how they work the queue, and is not the
                                 reporter's to read. --}}
                            @if ($viewerIsStaff && $concern->status === 'referred' && $concern->referred_to)
                                <div style="font-size:0.72rem; color:#64748b; margin-top:0.25rem;">→ {{ $concern->referred_to }}</div>
                            @endif
                        </td>
                        <td>{{ $concern->created_at->local()->format('M d, Y · g:i A') }}</td>
                        <td style="display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center;">
                            <a href="{{ route('concerns.show', $concern) }}" class="btn btn-primary" style="padding: 0.5rem 1rem; font-size: 0.85rem;">View</a>
                            @if (Auth::user()->id === $concern->user_id && $concern->status === 'submitted')
                                <form action="{{ route('concerns.destroy', $concern) }}" method="POST" style="display:inline-block; margin:0;">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-ghost-danger" style="font-size: 0.85rem;" onclick="return confirm('Are you sure you want to delete this concern?');">Delete</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </div>

        <div style="margin-top: 2rem; display: flex; justify-content: center;">
            {{ $concerns->onEachSide(1)->links('pagination::bootstrap-4') }}
        </div>
    @else
        <div style="text-align: center; padding: 3rem 1rem;">
            {{-- An empty list because of a filter is not an empty list. Saying
                 "you haven't submitted any concerns yet" to a student who has
                 filed six and just searched for the wrong word would read as
                 data loss. --}}
            @if ($activeFilterCount > 0)
                <p style="font-weight: 600; color: var(--navy-900); margin-bottom: 0.35rem;">No concerns match those filters.</p>
                <p style="color: #64748b; font-size: 0.9rem; margin-bottom: 1.25rem;">
                    Try a wider date range, or clear the filters to see everything you can read.
                </p>
                <a href="{{ route('concerns.index', $showResolved ? ['show_resolved' => 1] : []) }}" class="btn btn-primary">Clear filters</a>
            {{-- Nothing finished yet is not the same as nothing filed. The two
                 buttons swap the list, so an empty finished pile must say so
                 and offer the way back. --}}
            @elseif ($showResolved)
                <p style="font-weight: 600; color: var(--navy-900); margin-bottom: 0.35rem;">Nothing has been finished yet.</p>
                <p style="color: #64748b; font-size: 0.9rem; margin-bottom: 1.25rem;">
                    Resolved and closed concerns collect here once they are settled.
                </p>
                <a href="{{ route('concerns.index') }}" class="btn btn-primary">Show active concerns</a>
            @elseif (optional(Auth::user()->role)->name === 'Student')
                <p style="font-weight: 600; color: var(--navy-900); margin-bottom: 0.35rem;">You haven't submitted any concerns yet.</p>
                <p style="color: #64748b; font-size: 0.9rem; margin-bottom: 1.25rem;">
                    When you do, you'll be able to track their status and updates here.
                </p>
                <a href="{{ route('concerns.create') }}" class="btn btn-primary">+ New Concern</a>
            @else
                <p style="font-weight: 600; color: var(--navy-900); margin-bottom: 0.35rem;">No concerns to show yet.</p>
                <p style="color: #64748b; font-size: 0.9rem;">
                    Concerns assigned or referred to you will appear here.
                </p>
            @endif
        </div>
    @endif
</div>

<script>
    // Open and close the filter dialog. Esc and the backdrop close it natively;
    // these wire up the button and the X. Nothing here applies a filter -- that
    // is Done, a plain submit, so several choices travel in one request.
    (function () {
        var dialog = document.getElementById('filter-dialog');

        if (! dialog || typeof dialog.showModal !== 'function') {
            return;
        }

        document.querySelectorAll('[data-open-filters]').forEach(function (button) {
            button.addEventListener('click', function () {
                dialog.showModal();
            });
        });

        document.querySelectorAll('[data-close-filters]').forEach(function (button) {
            button.addEventListener('click', function () {
                dialog.close();
            });
        });

        // Clicking the dark area outside the panel closes it, the way the X
        // does. The dialog element itself fills the screen when modal, so the
        // target is only the dialog when the click missed its contents.
        dialog.addEventListener('click', function (event) {
            if (event.target === dialog) {
                dialog.close();
            }
        });
    })();
</script>
@endsection