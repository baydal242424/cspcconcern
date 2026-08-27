@extends('layout')

@section('title', 'Manage Users')

@section('content')
<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
        <div>
            <h1>Manage Users</h1>
            <p style="color: #666; margin-top: 0.5rem;">Every registered account, who's currently online, and when everyone else was last active.</p>
        </div>
    </div>

    @if ($users->isEmpty())
        <p style="color: var(--muted);">No accounts registered yet.</p>
    @else
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Student info</th>
                        <th>Status</th>
                        <th>Activity</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($users as $user)
                        <tr>
                            <td>{{ $user->name }}</td>
                            <td>{{ $user->email }}</td>
                            <td>
                                @if ($user->id === auth()->id())
                                    {{ optional($user->role)->name ?? 'N/A' }}
                                @else
                                    <form action="{{ route('admin.users.role', $user) }}" method="POST"
                                          onsubmit="return confirm('Change {{ $user->name }}\'s role?')"
                                          style="display:flex; gap:.4rem; align-items:center;">
                                        @csrf
                                        <select name="role_id" style="padding:.5rem .6rem; font-size:.82rem;">
                                            @foreach ($roles as $role)
                                                <option value="{{ $role->id }}" {{ $user->role_id === $role->id ? 'selected' : '' }}>
                                                    {{ $role->name }}
                                                </option>
                                            @endforeach
                                        </select>
                                        <button type="submit" class="btn btn-secondary">Update</button>
                                    </form>
                                @endif
                            </td>
                            <td>
                                @if (optional($user->role)->name === 'Student')
                                    {{ $user->student_id ?? '—' }}
                                    {{-- Course, not year/section: those were removed
                                         because they go stale each school year. --}}
                                    <div style="color:var(--muted); font-size:.82rem;">{{ $user->course ?? '—' }}</div>
                                @else
                                    —
                                @endif
                            </td>
                            <td>
                                <span class="status-badge status-{{ $user->status }}">{{ ucfirst($user->status) }}</span>
                                @if ($user->status === 'banned' && $user->ban_reason)
                                    <div style="color:var(--muted); font-size:.78rem; margin-top:.3rem;">{{ $user->ban_reason }}</div>
                                @endif
                            </td>
                            <td>
                                @if ($user->is_online)
                                    <span class="status-badge status-approved">● Online</span>
                                @else
                                    <span style="color:var(--muted); font-size:.85rem;">
                                        {{ $user->last_seen_at ? 'Active '.$user->last_seen_at->diffForHumans() : 'Never active' }}
                                    </span>
                                @endif
                            </td>
                            <td style="display:flex; flex-direction:column; gap:.5rem; align-items:flex-start;">
                                @if ($user->id === auth()->id())
                                    <span style="color:var(--muted); font-size:.85rem;">You</span>
                                @else
                                    @if ($user->status === 'banned')
                                        <form action="{{ route('admin.users.unban', $user) }}" method="POST"
                                              onsubmit="return confirm('Unban {{ $user->name }}?')">
                                            @csrf
                                            <button type="submit" class="btn btn-success">Unban</button>
                                        </form>
                                    @else
                                        <form action="{{ route('admin.users.ban', $user) }}" method="POST"
                                              onsubmit="return confirm('Ban {{ $user->name }}? They will be signed out immediately and blocked from logging back in.')"
                                              style="display:flex; gap:.4rem; align-items:center;">
                                            @csrf
                                            <input type="text" name="reason" placeholder="Reason (optional)"
                                                   style="max-width:150px; padding:.5rem .6rem; font-size:.82rem;">
                                            <button type="submit" class="btn btn-danger">Ban</button>
                                        </form>
                                    @endif
                                    <form action="{{ route('admin.users.destroy', $user) }}" method="POST"
                                          onsubmit="return confirm('PERMANENTLY delete {{ $user->name }}\'s account and every concern they submitted? This cannot be undone.')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-ghost-danger">Delete account</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
@endsection
