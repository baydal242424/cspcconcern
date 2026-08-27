{{-- July 2026 UI cleanup: quieter delete button so View stays the one
     primary action per row, single date format, role-aware empty state,
     and the bootstrap-4 paginator view (the default Tailwind one renders
     unstyled here). Queries and routes untouched. --}}
@extends('layout')

@section('title', 'My Concerns')

@section('content')
<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; gap: 1rem; flex-wrap: wrap;">
        <h1>Student Concerns</h1>
        <div style="display: flex; gap: 0.6rem; align-items: center;">
            @if ($showResolved)
                <a href="{{ route('concerns.index') }}" class="btn btn-muted">Hide resolved</a>
            @else
                <a href="{{ route('concerns.index', ['show_resolved' => 1]) }}" class="btn btn-muted">Show resolved</a>
            @endif
            @if (optional(Auth::user()->role)->name === 'Student')
                <a href="{{ route('concerns.create') }}" class="btn btn-primary">+ New Concern</a>
            @endif
        </div>
    </div>

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
                        <td>{{ $concern->category }}</td>
                        <td>
                            <span class="urgency-badge urgency-{{ strtolower($concern->urgency ?? 'pending') }}">
                                {{ $concern->urgency ?? 'Pending' }}
                            </span>
                        </td>
                        <td>
                            <span class="status-badge status-{{ str_replace(' ', '_', $concern->status) }}">
                                {{ $concern->status_label }}
                            </span>
                            @if ($concern->status === 'referred' && $concern->referred_to)
                                <div style="font-size:0.72rem; color:#64748b; margin-top:0.25rem;">→ {{ $concern->referred_to }}</div>
                            @endif
                        </td>
                        <td>{{ $concern->created_at->format('M d, Y · g:i A') }}</td>
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
            @if (optional(Auth::user()->role)->name === 'Student')
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
@endsection