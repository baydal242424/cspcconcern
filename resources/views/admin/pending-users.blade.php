@extends('layout')

@section('title', 'Pending Accounts')

@section('content')
<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
        <div>
            <h1>Pending Accounts</h1>
            <p style="color: #666; margin-top: 0.5rem;">Registration requests awaiting your approval.</p>
        </div>
    </div>

    @if ($pendingUsers->isEmpty())
        <p style="color: var(--muted);">No pending registrations right now.</p>
    @else
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Requested Role</th>
                        <th>Department</th>
                        <th>Requested</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($pendingUsers as $user)
                        <tr>
                            <td>{{ $user->name }}</td>
                            <td>{{ $user->email }}</td>
                            <td>{{ optional($user->role)->name ?? 'N/A' }}</td>
                            <td>{{ $user->department ?? '—' }}</td>
                            <td>{{ $user->created_at->diffForHumans() }}</td>
                            <td style="display:flex; gap:.5rem;">
                                <form action="{{ route('admin.pending-users.approve', $user) }}" method="POST">
                                    @csrf
                                    <button type="submit" class="btn btn-success">Approve</button>
                                </form>
                                <form action="{{ route('admin.pending-users.reject', $user) }}" method="POST"
                                      onsubmit="return confirm('Reject this registration request?')">
                                    @csrf
                                    <button type="submit" class="btn btn-danger">Reject</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
@endsection
