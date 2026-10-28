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

        {{-- All / Unread. Filtered in the browser rather than by reloading:
             the panel holds ten rows at most, they are already here, and a
             round trip would close the dropdown to answer a question the
             page can answer itself. --}}
        @if ($notifications->isNotEmpty())
            <div class="bell-tabs" role="tablist">
                <button type="button" class="bell-tab is-on" data-filter="all" role="tab" aria-selected="true">All</button>
                <button type="button" class="bell-tab" data-filter="unread" role="tab" aria-selected="false">
                    Unread @if ($unreadCount > 0)<span class="bell-tab-n">{{ $unreadCount }}</span>@endif
                </button>
            </div>
        @endif

        <div class="bell-list">
            @php
                // Two groups, split at a day old. Headings are only worth
                // printing where both sides have something in them.
                $groups = $notifications->groupBy(fn ($n) => $n->isRecent() ? 'New' : 'Earlier');
                $showHeadings = $groups->count() > 1;
            @endphp

            @forelse ($groups as $heading => $rows)
                @if ($showHeadings)
                    <p class="bell-group" data-group="{{ $heading }}">{{ $heading }}</p>
                @endif

                @foreach ($rows as $n)
                    {{-- POST, not a link: marking read is a state change, and a
                         GET would let a prefetcher clear the badge silently. --}}
                    <form action="{{ route('notifications.read', $n) }}" method="POST"
                          class="bell-row {{ $n->is_read ? 'is-read' : 'is-unread' }}">
                        @csrf
                        <button type="submit" class="bell-item {{ $n->is_read ? '' : 'unread' }}">
                            {{-- The round mark stands in for the profile photo
                                 a social app would show. There are none here,
                                 and ten identical grey rows cannot be read at
                                 a glance. --}}
                            <span class="bell-avatar" style="background:{{ $n->tint() }}" aria-hidden="true">{{ $n->glyph() }}</span>
                            <span class="bell-item-body">
                                <span class="bell-title">{{ $n->title }}</span>
                                <span class="bell-msg">{{ $n->message }}</span>
                                <span class="bell-time" title="{{ $n->created_at->local()->format('M j, Y g:i A') }}">{{ $n->shortAge() }}</span>
                            </span>
                            {{-- On the right, like a social app: the eye lands
                                 on the text first and the dot answers "is
                                 there anything new" without being read. --}}
                            <span class="bell-dot" aria-hidden="true"></span>
                        </button>
                    </form>
                @endforeach
            @empty
                <p class="bell-empty">No notifications yet.<br>You'll see updates about your concerns here.</p>
            @endforelse

            {{-- Shown by the filter when Unread is chosen and there is
                 nothing unread, so the list never just empties. --}}
            <p class="bell-empty" id="bell-none-unread" hidden>You have read everything.</p>
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

        // ---- All / Unread ------------------------------------------------
        var tabs = panel.querySelectorAll('.bell-tab');
        var rows = panel.querySelectorAll('.bell-row');
        var groups = panel.querySelectorAll('.bell-group');
        var noneUnread = document.getElementById('bell-none-unread');

        function apply(filter) {
            var shown = 0;

            Array.prototype.forEach.call(rows, function (row) {
                var keep = filter === 'all' || row.classList.contains('is-unread');
                row.hidden = !keep;
                if (keep) shown++;
            });

            // A heading with nothing under it reads as a group that has
            // lost its contents, so each one follows its own rows.
            Array.prototype.forEach.call(groups, function (heading) {
                var any = false;
                var node = heading.nextElementSibling;

                while (node && !node.classList.contains('bell-group')) {
                    if (node.classList.contains('bell-row') && !node.hidden) any = true;
                    node = node.nextElementSibling;
                }

                heading.hidden = !any;
            });

            if (noneUnread) noneUnread.hidden = shown !== 0;
        }

        Array.prototype.forEach.call(tabs, function (tab) {
            tab.addEventListener('click', function () {
                Array.prototype.forEach.call(tabs, function (other) {
                    var on = other === tab;
                    other.classList.toggle('is-on', on);
                    other.setAttribute('aria-selected', on ? 'true' : 'false');
                });

                apply(tab.getAttribute('data-filter'));
            });
        });
    })();
</script>
