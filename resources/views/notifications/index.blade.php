@extends('layout')

@section('title', 'Notifications')

@section('content')

<div class="card">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:1rem; flex-wrap:wrap; margin-bottom:1.5rem;">
        <div>
            <h1>Notifications</h1>
            <p style="color:var(--muted); font-size:.88rem; margin-top:.35rem;">
                @if ($unreadTotal > 0)
                    {{ number_format($unreadTotal) }} unread, and everything before them.
                @else
                    Everything you have been told about your concerns.
                @endif
            </p>
        </div>

        @if ($unreadTotal > 0)
            <form action="{{ route('notifications.readAll') }}" method="POST">
                @csrf
                <button type="submit" class="btn btn-muted">Mark all read</button>
            </form>
        @endif
    </div>

    @forelse ($notifications as $notice)
        {{-- A form, not a link. Opening one marks it read, and a GET that
             changes something is the kind of thing a browser's prefetch
             does on its own. --}}
        <form action="{{ route('notifications.read', $notice->id) }}" method="POST"
              class="notice-row {{ $notice->is_read ? 'is-read' : 'is-unread' }}">
            @csrf
            <button type="submit" class="notice-open">
                <span class="notice-body">
                    <span class="notice-title">{{ $notice->title }}</span>
                    <span class="notice-text">{{ $notice->message }}</span>
                    <span class="notice-meta">
                        {{ $notice->created_at->local()->format('M j, Y g:i A') }}
                        <span style="color:#94a3b8;">· {{ $notice->created_at->diffForHumans() }}</span>
                        @if ($notice->concern_id)
                            <span style="color:#94a3b8;">· Concern #{{ $notice->concern_id }}</span>
                        @endif
                    </span>
                </span>

                @unless ($notice->is_read)
                    <span class="notice-dot" aria-label="Unread"></span>
                @endunless
            </button>
        </form>
    @empty
        <p style="color:var(--muted);">Nothing yet. Notices about your concerns will appear here.</p>
    @endforelse

    @if ($notifications->hasPages())
        <div style="margin-top:1.5rem;">
            {{ $notifications->links() }}
        </div>
    @endif
</div>

@endsection
