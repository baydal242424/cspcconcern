@extends('layout')

@section('title', 'Manage Users')

@section('content')
{{-- A table with seven columns and three dropdowns per row cannot survive a
     phone: the selects collapse to bare arrows and the name column scrolls off
     the side. Each account is a card instead, so the layout reflows down
     rather than sideways and every control keeps a usable width at any size. --}}
<style>
    .user-toolbar{display:flex; flex-wrap:wrap; gap:.75rem; align-items:center;
        justify-content:space-between; margin-bottom:1.25rem}
    .user-search{flex:1 1 240px; min-width:0; padding:.6rem .8rem; border:1.5px solid var(--line);
        border-radius:10px; font-family:inherit; font-size:.9rem; background:#fcfdff; color:var(--ink)}
    .user-search:focus{outline:none; border-color:var(--brand); box-shadow:0 0 0 4px var(--brand-50)}
    .user-count{color:var(--muted); font-size:.85rem; white-space:nowrap}

    .user-list{display:flex; flex-direction:column; gap:.85rem}

    .user-card{border:1px solid var(--line); border-radius:12px; background:var(--surface);
        padding:1rem 1.1rem; display:flex; flex-direction:column; gap:.85rem}
    /* The search hides a card with card.hidden = true, and [hidden] only gets
       display:none from the user-agent stylesheet -- which the display:flex
       above outranks. Without this the filter counted correctly and hid
       nothing: searching a name left every account on screen beside a count
       saying "1 account". Same collision as .field[hidden] below. */
    .user-card[hidden]{display:none}
    .user-card.is-self{border-color:var(--brand); background:var(--brand-50)}

    .user-head{display:flex; flex-wrap:wrap; gap:.4rem .75rem; align-items:baseline}
    .user-name{font-weight:650; color:var(--navy-900); font-size:.98rem}
    .user-email{color:var(--muted); font-size:.85rem; overflow-wrap:anywhere}
    .user-meta{display:flex; flex-wrap:wrap; gap:.4rem; align-items:center; margin-left:auto}
    .user-note{color:var(--muted); font-size:.8rem; width:100%}

    /* The three pickers. flex-wrap plus a real flex-basis is what stops them
       collapsing into arrows when the row runs out of room -- they drop onto
       their own line instead of shrinking to nothing. */
    .user-form{display:flex; flex-wrap:wrap; gap:.6rem; align-items:flex-end;
        padding-top:.85rem; border-top:1px dashed var(--line)}
    .field{display:flex; flex-direction:column; gap:.25rem; flex:1 1 210px; min-width:0}
    /* display:flex above outranks the [hidden] attribute, which only gets
       display:none from the user-agent stylesheet -- so hiding the programme
       picker had no visible effect at all. Author rules beat UA rules. */
    .field[hidden]{display:none}
    .field label{font-size:.7rem; font-weight:600; letter-spacing:.05em;
        text-transform:uppercase; color:var(--muted)}
    .field select{width:100%; padding:.55rem .6rem; border:1.5px solid var(--line);
        border-radius:9px; font-family:inherit; font-size:.86rem; background:#fcfdff; color:var(--ink)}
    .field select:focus{outline:none; border-color:var(--brand); box-shadow:0 0 0 3px var(--brand-50)}
    .user-form .btn{flex:0 0 auto}

    .user-actions{display:flex; flex-wrap:wrap; gap:.5rem; align-items:center;
        padding-top:.75rem; border-top:1px dashed var(--line)}
    .user-actions form{display:flex; flex-wrap:wrap; gap:.5rem; align-items:center; margin:0}
    .ban-reason{flex:1 1 160px; min-width:0; padding:.5rem .6rem; border:1.5px solid var(--line);
        border-radius:9px; font-family:inherit; font-size:.84rem; background:#fcfdff; color:var(--ink)}
    .spacer{flex:1 1 auto}

    .no-match{color:var(--muted); font-size:.9rem; padding:1rem 0}

    @media (max-width:560px){
        .user-card{padding:.9rem}
        .user-meta{margin-left:0; width:100%}
        .user-form .btn, .user-actions .btn{width:100%; justify-content:center}
        .field{flex:1 1 100%}
        .user-actions form{width:100%}
        .ban-reason{flex:1 1 100%}
    }

    /* Start-of-year promotion. Set apart from the account list because it acts
       on every student at once rather than on one row. */
    .promote-panel{
        display:flex; gap:1.5rem; align-items:flex-start; flex-wrap:wrap;
        justify-content:space-between;
        border:1px solid var(--line); border-left:3px solid var(--brand);
        border-radius:10px; padding:1.1rem 1.25rem; margin-bottom:1.5rem;
        background:var(--brand-50);
    }
    .promote-copy{flex:1 1 420px; min-width:0}
    .promote-panel h2{font-size:1.02rem; margin:0 0 .3rem}
    .promote-panel p{margin:0; color:var(--muted); font-size:.88rem}
    .promote-counts{margin:.6rem 0 0; padding-left:1.1rem; font-size:.88rem; color:var(--ink)}
    .promote-counts li{margin:.15rem 0}
    .promote-last{margin-top:.7rem !important; font-size:.82rem !important; color:var(--muted)}
    .promote-actions{display:flex; flex-direction:column; gap:.5rem; flex:0 0 auto}
    .promote-actions .btn{width:100%; justify-content:center}
    .promote-actions button[disabled]{opacity:.5; cursor:not-allowed}

    @media (max-width:768px){
        .promote-actions{width:100%}
    }
</style>

<div class="card">
    <div style="margin-bottom:1.25rem;">
        <h1>Manage Users</h1>
        <p style="color:var(--muted); margin-top:.5rem;">
            Every registered account, who's currently online, and when everyone else was last active.
        </p>
    </div>

    {{-- Start of the school year. Editing a digit on 500-odd accounts by hand
         is not something anybody actually does, so sections went stale -- and
         a stale section is worse than none, because it routes a student's
         academic concerns to the adviser of the class they left last year.

         The counts are shown BEFORE the button, so nobody has to press it to
         find out what it touches. --}}
    <div class="promote-panel">
        <div class="promote-copy">
            <h2>Start of school year</h2>
            <p>
                Moves every student up one year level at once — 1A becomes 2A, 2A becomes 3A.
                Their class letter stays the same.
            </p>
            <ul class="promote-counts">
                <li><strong>{{ $promotion['moving'] }}</strong> students will move up</li>
                @if ($promotion['graduating'] > 0)
                    <li><strong>{{ $promotion['graduating'] }}</strong> are in their final year — their accounts close as graduated, and they can no longer sign in</li>
                @endif
                @if ($promotion['noSection'] > 0)
                    <li><strong>{{ $promotion['noSection'] }}</strong> have no section recorded yet</li>
                @endif
                @if ($promotion['unreadable'] > 0)
                    <li><strong>{{ $promotion['unreadable'] }}</strong> have a section that cannot be read and will be skipped</li>
                @endif
                @if ($promotion['closed'] > 0)
                    <li><strong>{{ $promotion['closed'] }}</strong> accounts are already closed as graduated — search <em>graduated</em> below to reactivate one</li>
                @endif
            </ul>
            @if ($promotion['graduating'] > 0)
                <p class="promote-note">
                    An irregular student still finishing subjects looks the same as a graduate here.
                    If one needs to file a concern, they will be told to ask you, and you can reactivate
                    their account from their card below.
                </p>
            @endif
            @if ($lastPromotion)
                <p class="promote-last">
                    Last run {{ $lastPromotion->created_at->timezone('Asia/Manila')->format('M j, Y · g:i A') }}
                    by {{ optional($lastPromotion->user)->name ?? 'a removed account' }} —
                    {{ $lastPromotion->description }}
                </p>
            @endif
        </div>

        <div class="promote-actions">
            <form action="{{ route('admin.students.promote') }}" method="POST"
                  onsubmit="return confirm('Move {{ $promotion['moving'] }} students up one year level?\n\nThis changes every one of their records at once. You can undo it straight afterwards.');">
                @csrf
                <button type="submit" class="btn btn-primary" {{ $promotion['moving'] === 0 ? 'disabled' : '' }}>
                    Move all students up a year
                </button>
            </form>

            {{-- Only offered while the last thing that happened WAS a
                 promotion. Once it has been undone, there is nothing to undo. --}}
            @if ($lastPromotion && $lastPromotion->action === 'students_promoted')
                <form action="{{ route('admin.students.promote.undo') }}" method="POST"
                      onsubmit="return confirm('Put every student back a year level, exactly as they were before the last run?');">
                    @csrf
                    <button type="submit" class="btn btn-muted">Undo the last run</button>
                </form>
            @endif
        </div>
    </div>

    @if ($users->isEmpty())
        <p style="color: var(--muted);">No accounts registered yet.</p>
    @else
        <div class="user-toolbar">
            <input type="search" id="user-search" class="user-search" autocomplete="off"
                   placeholder="Search by name or student ID — also email, role, college, section, status"
                   aria-label="Search accounts by name or student ID">
            <span class="user-count" id="user-count">{{ $users->count() }} accounts</span>
        </div>

        <div class="user-list" id="user-list">
            @foreach ($users as $user)
                @php
                    $roleName = optional($user->role)->name ?? 'No role';
                    $isSelf = $user->id === auth()->id();
                @endphp
                <div class="user-card {{ $isSelf ? 'is-self' : '' }}"
                     {{-- Status is in here so "graduated" and "banned" are
                          searchable. Without it the promotion panel's advice
                          to search for graduated accounts matched nothing,
                          and the only way to find one was to scroll. --}}
                     data-search="{{ Str::lower(implode(' ', array_filter([
                         $user->name,
                         // The student number, so an admin holding a class list
                         // can type the id straight off it and land on the same
                         // person a name search would find. It is what the
                         // college's own records key on, and it is unambiguous
                         // where two students share a name.
                         $user->student_id,
                         $user->email,
                         $roleName,
                         $user->department,
                         $user->course,
                         $user->section,
                         $user->status,
                     ]))) }}">

                    <div class="user-head">
                        <span class="user-name">{{ $user->name }}</span>
                        <span class="user-email">{{ $user->email }}</span>
                        <span class="user-meta">
                            <span class="badge-role status-badge status-approved">{{ $roleName }}</span>
                            <span class="status-badge status-{{ $user->status }}">{{ ucfirst($user->status) }}</span>
                            @if ($user->is_online)
                                <span class="status-badge status-approved">● Online</span>
                            @else
                                <span style="color:var(--muted); font-size:.8rem;">
                                    {{ $user->last_seen_at ? 'Active '.$user->last_seen_at->diffForHumans() : 'Never active' }}
                                </span>
                            @endif
                        </span>

                        @if ($user->department || $user->course || optional($user->role)->name === 'Student')
                            <span class="user-note">
                                {{ $user->department ?: 'No college set' }}
                                @if ($user->course) · {{ $user->course }} @endif
                                @if ($user->section) · Section {{ $user->section }} @endif
                                @if (optional($user->role)->name === 'Student' && $user->student_id) · ID {{ $user->student_id }} @endif
                            </span>
                        @endif

                        @if ($user->status === 'banned' && $user->ban_reason)
                            <span class="user-note">Banned: {{ $user->ban_reason }}</span>
                        @endif
                    </div>

                    @if ($isSelf)
                        <div class="user-actions">
                            <span style="color:var(--muted); font-size:.85rem;">This is your own account.</span>
                        </div>
                    @else
                        <form action="{{ route('admin.users.role', $user) }}" method="POST" class="user-form"
                              onsubmit="return confirm('Update {{ $user->name }}\'s role and college?')">
                            @csrf

                            <div class="field">
                                <label for="role-{{ $user->id }}">Role</label>
                                <select name="role_id" id="role-{{ $user->id }}" class="js-role">
                                    @foreach ($roles as $role)
                                        <option value="{{ $role->id }}" data-role-name="{{ $role->name }}"
                                                {{ $user->role_id === $role->id ? 'selected' : '' }}>
                                            {{ $role->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            {{-- The college is not decoration. findHandler() prefers a handler
                                 from the reporter's own college, so an instructor left unset is
                                 skipped by routing and their college's concerns land on whoever
                                 happens to sort first, with nothing on screen to show it. --}}
                            <div class="field">
                                <label for="dept-{{ $user->id }}">College / unit</label>
                                <select name="department" id="dept-{{ $user->id }}">
                                    <option value="">— not set —</option>
                                    <optgroup label="Colleges">
                                        @foreach ($colleges as $college)
                                            <option value="{{ $college }}" {{ $user->department === $college ? 'selected' : '' }}>
                                                {{ $college }}
                                            </option>
                                        @endforeach
                                    </optgroup>
                                    @if (count($otherUnits))
                                        <optgroup label="Units &amp; offices">
                                            @foreach ($otherUnits as $unit)
                                                <option value="{{ $unit }}" {{ $user->department === $unit ? 'selected' : '' }}>
                                                    {{ $unit }}
                                                </option>
                                            @endforeach
                                        </optgroup>
                                    @endif
                                </select>
                            </div>

                            {{-- Only two roles carry a programme: a Program Chair, for the one
                                 they cover, and a Student, for the one they are enrolled in. On
                                 anybody else it is worse than clutter -- findHandler() would
                                 start preferring them for that programme's concerns. Hidden
                                 rather than omitted, so promoting somebody TO Program Chair
                                 reveals it before they are saved. --}}
                            <div class="field js-course-wrap"
                                 @unless (in_array($roleName, ['Program Chair', 'Student'], true)) hidden @endunless>
                                <label for="course-{{ $user->id }}">Programme</label>
                                <select name="course" id="course-{{ $user->id }}">
                                    <option value="">— not set —</option>
                                    @foreach ($courses as $college => $collegeCourses)
                                        <optgroup label="{{ $college }}">
                                            @foreach ($collegeCourses as $course)
                                                <option value="{{ $course }}" {{ $user->course === $course ? 'selected' : '' }}>
                                                    {{ $course }}
                                                </option>
                                            @endforeach
                                        </optgroup>
                                    @endforeach
                                </select>
                            </div>

                            <button type="submit" class="btn btn-secondary">Update</button>
                        </form>

                        <div class="user-actions">
                            {{-- The irregular student's way back in. Their year
                                 was closed by the start-of-year promotion, and
                                 nothing in the data tells them apart from a
                                 graduate -- so a person decides. --}}
                            @if ($user->status === 'graduated')
                                <form action="{{ route('admin.users.reactivate', $user) }}" method="POST"
                                      onsubmit="return confirm('Reactivate {{ $user->name }}? They will be able to sign in and file concerns again.')">
                                    @csrf
                                    <button type="submit" class="btn btn-success">Reactivate</button>
                                </form>
                            @elseif ($user->status === 'banned')
                                <form action="{{ route('admin.users.unban', $user) }}" method="POST"
                                      onsubmit="return confirm('Unban {{ $user->name }}?')">
                                    @csrf
                                    <button type="submit" class="btn btn-success">Unban</button>
                                </form>
                            @else
                                <form action="{{ route('admin.users.ban', $user) }}" method="POST"
                                      onsubmit="return confirm('Ban {{ $user->name }}? They will be signed out immediately and blocked from logging back in.')">
                                    @csrf
                                    <input type="text" name="reason" class="ban-reason" placeholder="Reason (optional)">
                                    <button type="submit" class="btn btn-danger">Ban</button>
                                </form>
                            @endif

                            <span class="spacer"></span>

                            <form action="{{ route('admin.users.destroy', $user) }}" method="POST"
                                  onsubmit="return confirm('PERMANENTLY delete {{ $user->name }}\'s account and every concern they submitted? This cannot be undone.')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-ghost-danger">Delete account</button>
                            </form>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>

        <p class="no-match" id="no-match" hidden>No account matches that.</p>
    @endif
</div>

<script>
    (function () {
        // Show the programme picker only for the roles that carry one, and key
        // it to the dropdown rather than to what is stored: an admin promoting
        // somebody to Program Chair has to set their programme in the same
        // action, not find out afterwards that it is missing.
        var PROGRAMME_ROLES = ['Program Chair', 'Student'];

        document.querySelectorAll('.js-role').forEach(function (roleEl) {
            var form = roleEl.closest('form');
            var wrap = form && form.querySelector('.js-course-wrap');
            if (!wrap) return;

            function sync() {
                var name = roleEl.options[roleEl.selectedIndex].getAttribute('data-role-name');
                wrap.hidden = PROGRAMME_ROLES.indexOf(name) === -1;
            }

            roleEl.addEventListener('change', sync);
            sync();
        });

        // Filtering beats scrolling once there are more than a screenful of
        // accounts, which is already true here.
        var search = document.getElementById('user-search');
        var count = document.getElementById('user-count');
        var empty = document.getElementById('no-match');
        var cards = Array.prototype.slice.call(document.querySelectorAll('.user-card'));

        if (!search) return;

        search.addEventListener('input', function () {
            var q = this.value.trim().toLowerCase();
            var shown = 0;

            cards.forEach(function (card) {
                var hit = !q || card.getAttribute('data-search').indexOf(q) !== -1;
                card.hidden = !hit;
                if (hit) shown++;
            });

            count.textContent = shown + (shown === 1 ? ' account' : ' accounts');
            if (empty) empty.hidden = shown !== 0;
        });
    })();
</script>
@endsection
