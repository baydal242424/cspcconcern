{{-- One batch of bell rows.

     Rendered twice: once inside the panel when a page loads, and once
     per request when "See previous notifications" fetches the next page.
     Written here rather than in both places so the row that arrives by
     fetch cannot drift from the row that was there already.

     ROWS ONLY -- no headings. @include inherits the including view's
     variables, so a $heading printed here appeared twice inside the bell:
     once from the panel's own grouping and once from this partial, which
     had no way to know whether the panel wanted one. The panel decides
     its own headings; this decides what a row looks like.

     $rows is a collection of notifications. --}}

@foreach ($rows as $n)
    {{-- POST, not a link: marking read is a state change, and a GET would
         let a prefetcher clear the badge silently. --}}
    <form action="{{ route('notifications.read', $n) }}" method="POST"
          class="bell-row {{ $n->is_read ? 'is-read' : 'is-unread' }}">
        @csrf
        <button type="submit" class="bell-item {{ $n->is_read ? '' : 'unread' }}">
            <span class="bell-item-body">
                <span class="bell-title">{{ $n->title }}</span>
                <span class="bell-msg">{{ $n->message }}</span>
                <span class="bell-time" title="{{ $n->created_at->local()->format('M j, Y g:i A') }}">{{ $n->shortAge() }}</span>
            </span>
            {{-- On the right, like a social app: the eye lands on the text
                 first and the dot answers "is there anything new" without
                 being read. --}}
            <span class="bell-dot" aria-hidden="true"></span>
        </button>
    </form>
@endforeach
