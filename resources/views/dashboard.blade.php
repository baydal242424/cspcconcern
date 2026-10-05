@extends('layout')

@section('title', 'Trending Dashboard')

@section('content')
<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
        <div>
            <h1>Trending Dashboard</h1>
            <p style="color: #666; margin-top: 0.5rem;">Welcome back, {{ Auth::user()->name }}. Institution-wide analytics &mdash; visible to Admin only.</p>
        </div>
        <a href="{{ route('concerns.index') }}" class="btn btn-primary">View Concerns</a>
    </div>

    <div class="grid-2" style="gap: 1.5rem;">
        <div class="card" style="padding: 1.5rem;">
            <h3 style="margin-bottom: 1rem;">Total Concerns (All Time)</h3>
            <div style="font-size: 2.4rem; font-weight: 700;">{{ $totalConcerns }}</div>
            <p style="color: #666; margin-top: 0.5rem;">Every concern ever recorded, institution-wide.</p>
        </div>
        <div class="card" style="padding: 1.5rem;">
            <h3 style="margin-bottom: 1rem;">Trending in Last 30 Days</h3>
            <div style="font-size: 2.4rem; font-weight: 700; display:flex; align-items:baseline; gap:0.6rem;">
                {{ $recentTrendCount }}
                @if ($trendChangePercent !== null && $trendChangePercent != 0)
                    <span style="font-size: 1rem; font-weight: 700; color: {{ $trendChangePercent > 0 ? '#b42318' : '#0f6b34' }};">
                        {{ $trendChangePercent > 0 ? '▲' : '▼' }} {{ abs($trendChangePercent) }}%
                    </span>
                @else
                    <span style="font-size: 1rem; font-weight: 600; color: #94a3b8;">no change</span>
                @endif
            </div>
            <p style="color: #666; margin-top: 0.5rem;">
                @if ($trendChangePercent === null)
                    No concerns in the prior 30 days, so there is nothing to compare against yet.
                @else
                    vs. {{ $previousTrendCount }} in the prior 30 days.
                @endif
            </p>
        </div>
    </div>

    <div class="grid-2" style="gap: 1.5rem; margin-top: 2rem;">
        <div class="card" style="padding: 1.5rem;">
            <h3 style="margin-bottom: 0.25rem;">Trending Categories</h3>
            <p style="color:#94a3b8; font-size:0.8rem; margin-bottom: 1rem;">Last 30 days vs. the 30 days before that</p>
            @if(count($trendingCategories) > 0)
                <ol style="padding-left: 1.2rem; color: #333;">
                    @foreach ($trendingCategories as $category => $data)
                        <li style="margin-bottom: 0.75rem; display: flex; justify-content: space-between; align-items:center;">
                            <span>{{ \App\Models\Concern::categoryLabel($category) }}</span>
                            <span style="display:flex; align-items:center; gap:0.4rem;">
                                @if ($data['direction'] > 0)
                                    <span title="Rising vs. previous 30 days" style="color:#b42318;">▲</span>
                                @elseif ($data['direction'] < 0)
                                    <span title="Falling vs. previous 30 days" style="color:#0f6b34;">▼</span>
                                @else
                                    <span title="No change vs. previous 30 days" style="color:#94a3b8;">—</span>
                                @endif
                                <strong>{{ $data['count'] }}</strong>
                            </span>
                        </li>
                    @endforeach
                </ol>
            @else
                <p style="color: #666;">No category trends in the last 30 days.</p>
            @endif
        </div>
        <div class="card" style="padding: 1.5rem;">
            <h3 style="margin-bottom: 0.25rem;">Trending Departments</h3>
            <p style="color:#94a3b8; font-size:0.8rem; margin-bottom: 1rem;">Last 30 days</p>
            @if(count($trendingDepartments) > 0)
                <ol style="padding-left: 1.2rem; color: #333;">
                    @foreach ($trendingDepartments as $department => $count)
                        <li style="margin-bottom: 0.75rem; display: flex; justify-content: space-between;">
                            <span>{{ $department }}</span>
                            <strong>{{ $count }}</strong>
                        </li>
                    @endforeach
                </ol>
            @else
                <p style="color: #666;">No department trends in the last 30 days.</p>
            @endif
        </div>
    </div>

    {{-- What is stuck, rather than what was filed. Each row is a thing an
         administrator can act on today, and each says what happens if nobody
         does. --}}
    <div class="card" style="padding: 1.5rem; margin-top: 2rem;">
        <h3 style="margin-bottom: 0.25rem;">Needs Attention</h3>
        <p style="color:#94a3b8; font-size:0.8rem; margin-bottom: 1rem;">Open work waiting on somebody</p>

        <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(210px,1fr)); gap:1rem;">
            <div>
                <div style="font-size:1.8rem; font-weight:700; color:{{ $unassignedOpen > 0 ? '#b42318' : 'inherit' }};">{{ $unassignedOpen }}</div>
                <div style="font-size:0.85rem; font-weight:600;">Unassigned concerns</div>
                {{-- This used to say "nobody can see these but the student who
                     filed them", which is not true: every office has a standing
                     view of its own categories, so an unassigned concern is
                     still on somebody's screen. What it has not got is an
                     owner -- routing found nobody to hand it to, which happens
                     when the role that should take it has no holder, or the
                     only holder is the person the concern is about. --}}
                <p style="color:#94a3b8; font-size:0.78rem; margin:0;">Routing found nobody to hand these to. Usually a role with no holder — fix it in Manage Users.</p>
            </div>
            <div>
                <div style="font-size:1.8rem; font-weight:700; color:{{ $pendingRoleRequests > 0 ? '#8a5a00' : 'inherit' }};">{{ $pendingRoleRequests }}</div>
                <div style="font-size:0.85rem; font-weight:600;">Staff waiting for a role</div>
                <p style="color:#94a3b8; font-size:0.78rem; margin:0;">They receive nothing until granted, in Manage Users.</p>
            </div>
            <div>
                <div style="font-size:1.8rem; font-weight:700; color:{{ $classesWithoutAdviser > 0 ? '#8a5a00' : 'inherit' }};">{{ $classesWithoutAdviser }}</div>
                <div style="font-size:0.85rem; font-weight:600;">Classes with no adviser</div>
                <p style="color:#94a3b8; font-size:0.78rem; margin:0;">Of {{ $classesThisTerm }} this term. Their concerns drop to a college instructor.</p>
            </div>

        </div>
    </div>

    {{-- Where concerns go after their first handler: handed to another office,
         or lifted above the adviser because of who they are about. --}}
    <div class="card" style="padding: 1.5rem; margin-top: 2rem;">
        <h3 style="margin-bottom: 0.25rem;">Referrals &amp; Escalations</h3>
        <p style="color:#94a3b8; font-size:0.8rem; margin-bottom: 1rem;">Concerns that moved on from the office they first reached</p>

        <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(210px,1fr)); gap:1rem; margin-bottom:1.25rem;">
            {{-- Only the first of these is something a handler DID. The other
                 two describe how the student filed, and reading them as
                 actions is what made "Name a staff member: 2" look like two
                 referrals to staff that nobody had made. --}}
            <div>
                <div style="font-size:1.8rem; font-weight:700;">{{ $referredOpen }}</div>
                <div style="font-size:0.85rem; font-weight:600;">Waiting with another office</div>
                <p style="color:#94a3b8; font-size:0.78rem; margin:0;">Handed on, and not finished yet.</p>
            </div>
            {{-- Split in two, because "staff" was wrong for both halves at
                 once. In this system staff means the deans, chairs,
                 counsellors and offices in the second picker -- while the
                 person a student most often names is the teacher who advises
                 their class. One number for both read as a complaint about a
                 stranger when it was usually about their own adviser. --}}
            <div>
                <div style="font-size:1.8rem; font-weight:700;">{{ $aboutTeacherCount }}</div>
                <div style="font-size:0.85rem; font-weight:600;">Filed about a teacher</div>
                <p style="color:#94a3b8; font-size:0.78rem; margin:0;">An instructor or class adviser. Routed above them, to the Program Chair.</p>
            </div>
            <div>
                <div style="font-size:1.8rem; font-weight:700;">{{ $aboutOfficerCount }}</div>
                <div style="font-size:0.85rem; font-weight:600;">Filed about an officer</div>
                <p style="color:#94a3b8; font-size:0.78rem; margin:0;">A chair, dean, counsellor or office. Routed above whoever was named.</p>
            </div>
            <div>
                <div style="font-size:1.8rem; font-weight:700;">{{ $adviserBypassed }}</div>
                <div style="font-size:0.85rem; font-weight:600;">Filed past the class adviser</div>
                <p style="color:#94a3b8; font-size:0.78rem; margin:0;">The student asked to skip them, so it went to the Program Chair.</p>
            </div>
        </div>

        @if (count($referralsByOffice) > 0)
            {{-- Every concern ever referred, not only those in flight: the
                 question this answers is "where do our referrals go", which is
                 about the year rather than about this morning. --}}
            <p style="font-size:0.8rem; font-weight:600; color:#64748b; margin-bottom:0.5rem;">
                Where referrals have gone <span style="font-weight:400;">&mdash; all time, including finished ones</span>
            </p>
            @foreach ($referralsByOffice as $office => $count)
                <div style="display:flex; justify-content:space-between; padding:0.4rem 0; border-bottom:1px solid #eef1f6;">
                    <span>{{ $office }}</span>
                    <strong>{{ $count }}</strong>
                </div>
            @endforeach
        @else
            <p style="color:#666; margin:0;">No concern has been referred to another office yet.</p>
        @endif
    </div>

    <div class="card" style="padding: 1.5rem; margin-top: 2rem;">
        <h3 style="margin-bottom: 0.25rem;">Reporter Satisfaction</h3>
        <p style="color:#94a3b8; font-size:0.8rem; margin-bottom: 1rem;">Average rating left on resolved concerns</p>
        @if ($feedbackCount > 0)
            <div style="font-size: 2.4rem; font-weight: 700;">{{ $averageRating }} <span style="font-size:1.1rem; color:#94a3b8; font-weight:600;">/ 5</span></div>
            <p style="color: #666; margin-top: 0.5rem;">Based on {{ $feedbackCount }} {{ Str::plural('rating', $feedbackCount) }}.</p>
        @else
            <p style="color: #666;">No feedback submitted yet.</p>
        @endif
    </div>

    {{-- All-time totals for annual analysis. This NEVER shrinks: resolved
         concerns stay counted so the institution can see the most common
         concerns over the whole year. --}}
    <div class="card" style="padding: 1.5rem; margin-top: 2rem;">
        <h3 style="margin-bottom: 0.25rem;">Most Common Concerns (All Time)</h3>
        <p style="color:#94a3b8; font-size:0.8rem; margin-bottom: 1rem;">Includes resolved concerns · for annual analysis</p>
        @if(count($categoryTotals) > 0)
            @php $maxTotal = max($categoryTotals); @endphp
            @foreach ($categoryTotals as $category => $count)
                <div style="margin-bottom: 0.9rem;">
                    <div style="display:flex; justify-content:space-between; font-size:0.9rem; margin-bottom:0.25rem;">
                        <span>{{ \App\Models\Concern::categoryLabel($category) }}</span>
                        <strong>{{ $count }}</strong>
                    </div>
                    <div style="background:#eef1f6; border-radius:999px; height:8px; overflow:hidden;">
                        <div style="background:linear-gradient(90deg,#2f5bea,#2347c4); height:100%; width:{{ $maxTotal > 0 ? round(($count / $maxTotal) * 100) : 0 }}%;"></div>
                    </div>
                </div>
            @endforeach
        @else
            <p style="color: #666;">No concern data yet.</p>
        @endif
    </div>


    <div class="grid-2" style="gap: 1.5rem; margin-top: 2rem;">
        <div class="card" style="padding: 1.5rem;">
            <h3 style="margin-bottom: 1rem;">Status Breakdown</h3>
            <ul style="list-style: none; padding-left: 0;">
                @foreach (['submitted', 'in_progress', 'referred', 'resolved', 'closed_no_action'] as $status)
                    <li style="margin-bottom: 0.75rem; display: flex; justify-content: space-between;">
                        <span>{{ \App\Models\Concern::label($status) }}</span>
                        <strong>{{ $statusCounts[$status] ?? 0 }}</strong>
                    </li>
                @endforeach
            </ul>
        </div>
        <div class="card" style="padding: 1.5rem;">
            <h3 style="margin-bottom: 1rem;">Urgency Breakdown</h3>
            <ul style="list-style: none; padding-left: 0;">
                @foreach (['Low', 'Medium', 'High', 'Critical'] as $urgency)
                    <li style="margin-bottom: 0.75rem; display: flex; justify-content: space-between;">
                        <span>{{ $urgency }}</span>
                        <strong>{{ $urgencyCounts[$urgency] ?? 0 }}</strong>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>


{{-- ---------------------------------------------------------------------
     CONCERN TIMELINE

     A fortnight of the queue as a Gantt chart. The tiles above say how many
     and what kind; this says how LONG, which no count can show: a bar that
     reaches today from the far left is a case nobody has closed, and it
     looks like one at a glance.

     The grid is two columns -- a fixed label column and a track. The track
     is itself a grid of one cell per day, so a bar is placed by column
     number and span, and the day cells underneath keep the stripes and the
     today line visible through it.

     Built on the page's own tokens rather than a framework, so it sits in
     the same visual language as every other card here.
     --------------------------------------------------------------------- --}}
@if (!empty($timelineDays) && $timelineRows->isNotEmpty())
    <div class="tl-card">
        <div class="tl-head">
            <div>
                <h2 class="tl-title">Concern Timeline</h2>
                <p class="tl-range">
                    {{ $timelineDays[0]->format('M d') }} &mdash;
                    {{ $timelineDays[count($timelineDays) - 1]->format('M d, Y') }}
                </p>
            </div>

            <ul class="tl-legend">
                <li><span class="tl-dot tl-c-submitted"></span>Submitted</li>
                <li><span class="tl-dot tl-c-in_progress"></span>In&nbsp;progress</li>
                <li><span class="tl-dot tl-c-referred"></span>Referred</li>
                <li><span class="tl-dot tl-c-resolved"></span>Resolved</li>
                <li><span class="tl-dot tl-c-today"></span>Today</li>
            </ul>
        </div>

        {{-- Scrolls sideways on a narrow screen rather than crushing the
             columns into illegibility. --}}
        <div class="tl-scroll">
            <div class="tl-grid" style="--tl-days: {{ count($timelineDays) }};">

                <div class="tl-corner">Concerns</div>

                <div class="tl-days">
                    @foreach ($timelineDays as $day)
                        <div class="tl-day {{ $day->isToday() ? 'is-today' : '' }}">
                            <span class="tl-dow">{{ strtoupper($day->format('D')) }}</span>
                            <span class="tl-dom">{{ $day->format('d') }}</span>
                        </div>
                    @endforeach
                </div>

                @foreach ($timelineRows as $row)
                    @php
                        $concern = $row['concern'];
                        $settled = $concern->resolved_at;
                    @endphp

                    <div class="tl-label">
                        {{-- Linked only where this administrator can actually open
                             it. A link that answers 403 is worse than plain text. --}}
                        @if ($row['canOpen'])
                            <a href="{{ route('concerns.show', $concern) }}" class="tl-name">
                                #{{ $concern->id }} &middot; {{ $concern->category_label ?? $concern->category }}
                            </a>
                        @else
                            <span class="tl-name tl-name-plain">
                                #{{ $concern->id }} &middot; {{ $concern->category_label ?? $concern->category }}
                            </span>
                        @endif
                        <span class="tl-when">
                            {{ $concern->created_at->local()->format('M d, g:i A') }}
                            @if ($settled)
                                &mdash; {{ $settled->local()->format('M d') }}
                            @endif
                        </span>
                    </div>

                    <div class="tl-track">
                        @foreach ($timelineDays as $day)
                            <span class="tl-cell {{ $day->isToday() ? 'is-today' : '' }}"></span>
                        @endforeach

                        <{{ $row['canOpen'] ? 'a' : 'span' }}
                           @if ($row['canOpen']) href="{{ route('concerns.show', $concern) }}" @endif
                           class="tl-bar tl-c-{{ $concern->status }}"
                           style="grid-column: {{ $row['column'] }} / span {{ $row['span'] }};"
                           title="{{ $concern->status_label }} — filed {{ $concern->created_at->local()->format('M d, Y') }}">
                            {{-- A one- or two-day bar is narrower than any
                                 word that would fit it, so the label is left
                                 off and the colour carries the meaning --
                                 which is what the legend is for. --}}
                            @if ($row['span'] >= 3)
                                <span class="tl-bar-text">{{ $concern->status_label }}</span>
                            @endif
                        </{{ $row['canOpen'] ? 'a' : 'span' }}>
                    </div>
                @endforeach
            </div>
        </div>

        <p class="tl-month">{{ strtoupper($timelineDays[0]->format('M')) }}</p>
    </div>
@endif

    <div style="margin-top: 2rem;">
        <h2 style="margin-bottom: 1rem;">Recent Concerns</h2>
        @if ($recentConcerns->count() > 0)
            <div class="table-wrap">
                <table class="table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Category</th>
                        <th>Urgency</th>
                        <th>Status</th>
                        <th>Submitted By</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($recentConcerns as $concern)
                        <tr>
                            <td>#{{ $concern->id }}</td>
                            <td>{{ $concern->category_label }}</td>
                            <td>{{ $concern->urgency }}</td>
                            <td>{{ $concern->status_label }}</td>
                            <td>
                                @if ($concern->is_anonymous)
                                    @if (Auth::id() === $concern->user_id)
                                        {{ $concern->user->name ?? 'N/A' }} <span style="color:#94a3b8; font-size:0.8em;">(you, anonymous)</span>
                                    @else
                                        Anonymous
                                    @endif
                                @else
                                    {{ $concern->user->name ?? 'N/A' }}
                                @endif
                            </td>
                            <td>{{ $concern->created_at->local()->format('M d, Y') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        @else
            <p style="color: #666;">No recent concerns available in your dashboard view.</p>
        @endif
    </div>
</div>
@endsection