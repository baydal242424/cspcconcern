{{-- Navbar bell: unread count plus the 10 most recent notifications.
     $notifications and $unreadCount are supplied by the view composer in
     AppServiceProvider, so no querying happens in here. --}}
<div class="bell-wrap">
    <button type="button" id="bell-btn" class="bell-btn"
            aria-haspopup="true" aria-expanded="false" aria-controls="bell-panel"
            aria-label="Notifications{{ $unreadCount ? " ($unreadCount unread)" : '' }}">
        {{-- Inline SVG rather than an icon font: the rest of the app pulls no
             icon library, and this keeps it to one HTTP request (none). --}}
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
            <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
        </svg>
        @if ($unreadCount > 0)
            {{-- Capped display: a three-digit badge breaks the navbar layout. --}}
            <span class="bell-badge">{{ $unreadCount > 9 ? '9+' : $unreadCount }}</span>
        @endif
    </button>

    <div id="bell-panel" class="bell-panel" hidden>
        <div class="bell-head">
            <strong>Notifications</strong>
            @if ($unreadCount > 0)
                <form action="{{ route('notifications.readAll') }}" method="POST">
                    @csrf
                    <button type="submit" class="bell-linkbtn">Mark all read</button>
                </form>
            @endif
        </div>

        <div class="bell-list">
            @forelse ($notifications as $n)
                {{-- POST, not a link: marking read is a state change, and a
                     GET would let a prefetcher clear the badge silently. --}}
                <form action="{{ route('notifications.read', $n) }}" method="POST">
                    @csrf
                    <button type="submit" class="bell-item {{ $n->is_read ? '' : 'unread' }}">
                        <span class="bell-dot" aria-hidden="true"></span>
                        <span class="bell-item-body">
                            <span class="bell-title">{{ $n->title }}</span>
                            <span class="bell-msg">{{ $n->message }}</span>
                            <span class="bell-time">{{ $n->created_at->diffForHumans() }}</span>
                        </span>
                    </button>
                </form>
            @empty
                <p class="bell-empty">No notifications yet.<br>You'll see updates about your concerns here.</p>
            @endforelse
        </div>
    </div>
</div>

<script>
    // Dropdown open/close. Vanilla and inline to match the rest of the app --
    // this project ships no JS bundle.
    (function () {
        var btn = document.getElementById('bell-btn');
        var panel = document.getElementById('bell-panel');
        if (!btn || !panel) return;

        function close() {
            panel.hidden = true;
            btn.setAttribute('aria-expanded', 'false');
        }

        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            var willOpen = panel.hidden;
            panel.hidden = !willOpen;
            btn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
        });

        // Clicking anywhere else closes it, but clicks INSIDE the panel must
        // not -- otherwise the submit buttons never fire.
        document.addEventListener('click', function (e) {
            if (!panel.hidden && !panel.contains(e.target)) close();
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') close();
        });
    })();
</script>
