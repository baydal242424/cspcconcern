@extends('layout')

@section('title', 'Trending Dashboard')

@section('content')
<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
        <div>
            <h1>Trending Dashboard</h1>
            <p style="color: #666; margin-top: 0.5rem;">Welcome back, {{ Auth::user()->name }}. This view highlights the most active concern trends in your area.</p>
        </div>
        <a href="{{ route('concerns.index') }}" class="btn btn-primary">View Concerns</a>
    </div>

    <div class="grid-2" style="gap: 1.5rem;">
        <div class="card" style="padding: 1.5rem;">
            <h3 style="margin-bottom: 1rem;">Total Concerns (All Time)</h3>
            <div style="font-size: 2.4rem; font-weight: 700;">{{ $totalConcerns }}</div>
            <p style="color: #666; margin-top: 0.5rem;">Every concern ever recorded &mdash; institution-wide for staff, your own submissions for students.</p>
        </div>
        <div class="card" style="padding: 1.5rem;">
            <h3 style="margin-bottom: 1rem;">Trending in Last 30 Days</h3>
            <div style="font-size: 2.4rem; font-weight: 700;">{{ $recentTrendCount }}</div>
            <p style="color: #666; margin-top: 0.5rem;">Concerns created in the last 30 days.</p>
        </div>
    </div>

    <div class="grid-2" style="gap: 1.5rem; margin-top: 2rem;">
        <div class="card" style="padding: 1.5rem;">
            <h3 style="margin-bottom: 0.25rem;">Trending Categories</h3>
            <p style="color:#94a3b8; font-size:0.8rem; margin-bottom: 1rem;">Last 30 days</p>
            @if(count($trendingCategories) > 0)
                <ol style="padding-left: 1.2rem; color: #333;">
                    @foreach ($trendingCategories as $category => $count)
                        <li style="margin-bottom: 0.75rem; display: flex; justify-content: space-between;">
                            <span>{{ $category }}</span>
                            <strong>{{ $count }}</strong>
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
                        <span>{{ $category }}</span>
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
                @foreach (['submitted', 'in_progress', 'resolved', 'referred'] as $status)
                    <li style="margin-bottom: 0.75rem; display: flex; justify-content: space-between;">
                        <span>{{ ucfirst(str_replace('_', ' ', $status)) }}</span>
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
                            <td>{{ $concern->category }}</td>
                            <td>{{ $concern->urgency }}</td>
                            <td>{{ ucfirst(str_replace('_', ' ', $concern->status)) }}</td>
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
                            <td>{{ $concern->created_at->format('M d, Y') }}</td>
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