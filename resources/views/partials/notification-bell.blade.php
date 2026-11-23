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

                @include('partials.bell-rows', ['rows' => $rows])
            @empty
                <p class="bell-empty">No notifications yet.<br>You'll see updates about your concerns here.</p>
            @endforelse

            {{-- Shown by the filter when Unread is chosen and there is
                 nothing unread, so the list never just empties. --}}
            <p class="bell-empty" id="bell-none-unread" hidden>You have read everything.</p>
        </div>

        {{-- The way back to everything older.
             This panel holds the ten most recent, because that query runs
             on every page load and one that grows with the account would
             be paid for on every screen. The cost was that a notice
             scrolled off the bottom and was simply gone -- a student asked
             for a document on Monday had no way back to the request on
             Friday. --}}
        {{-- An anchor, upgraded by the script into a loader.
             Without JavaScript it is a link to the full page, which still
             works. With it, the click fetches the next ten and appends
             them to the list above -- the panel keeps its height and
             scrolls, so nothing on screen moves or resizes. --}}
        <a href="{{ route('notifications.index') }}" class="bell-all" id="bell-more"
           data-url="{{ route('notifications.index') }}" data-next="2">
            See previous notifications
        </a>
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

        // "See previous notifications": the next ten, appended in place.
        //
        // The panel has a fixed maximum height and scrolls, so adding
        // rows lengthens the list inside it and moves nothing on screen.
        // That is the whole point -- a dropdown that grows downward as it
        // loads pushes the page about under the pointer.
        (function () {
            var more = document.getElementById('bell-more');
            var list = document.querySelector('.bell-list');

            if (!more || !list || !window.fetch) return;

            var loading = false;
            var exhausted = false;
            // Only after the first press. Before that the panel is the ten
            // it opened with, and a list that quietly grows the moment
            // somebody scrolls it is a list nobody asked to grow.
            var keepGoing = false;

            function load(onDone) {
                if (loading || exhausted) return;
                loading = true;

                var label = more.textContent;
                more.textContent = 'Loading…';

                var page = more.getAttribute('data-next');

                fetch(more.getAttribute('data-url') + '?partial=1&page=' + page, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin'
                })
                    .then(function (r) {
                        if (!r.ok) throw new Error(r.status);

                        return r.text().then(function (html) {
                            return { html: html, hasMore: r.headers.get('X-Has-More') === '1' };
                        });
                    })
                    .then(function (res) {
                        // Before the "you have read everything" line, so
                        // the filter's own message stays at the bottom.
                        var anchor = document.getElementById('bell-none-unread');
                        var holder = document.createElement('div');
                        holder.innerHTML = res.html;

                        while (holder.firstChild) {
                            list.insertBefore(holder.firstChild, anchor);
                        }

                        more.setAttribute('data-next', String(Number(page) + 1));

                        if (res.hasMore) {
                            more.textContent = label === 'Loading…' ? 'See previous notifications' : label;
                            loading = false;
                        } else {
                            // Nothing after this. Retiring the control is
                            // kinder than leaving a button that answers
                            // every press with nothing.
                            exhausted = true;
                            more.textContent = 'That is everything';
                            more.classList.add('is-done');
                            more.removeAttribute('href');
                        }

                        if (onDone) onDone();
                    })
                    .catch(function () {
                        // Hand it back to the link. A failed fetch should
                        // not leave somebody stuck at ten notifications.
                        more.textContent = label;
                        loading = false;
                        window.location.href = more.getAttribute('data-url');
                    });
            }

            // Once they have asked once, scrolling to the bottom asks
            // again. Pressing a button for every ten is work the scroll
            // was already doing.
            list.addEventListener('scroll', function () {
                if (!keepGoing || loading || exhausted) return;

                // Within a panel's height of the end, so the next batch
                // is there by the time the reader arrives rather than
                // after a pause at the bottom.
                var remaining = list.scrollHeight - list.scrollTop - list.clientHeight;

                if (remaining < list.clientHeight) load();
            });

            more.addEventListener('click', function (e) {
                // Only once the script is doing the work. Before this
                // listener runs -- and if fetch throws -- the anchor still
                // goes to the full page.
                e.preventDefault();

                if (exhausted) return;

                // From here on, scrolling to the bottom loads the next
                // batch without another press.
                keepGoing = true;

                load();
            });
        })();

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
