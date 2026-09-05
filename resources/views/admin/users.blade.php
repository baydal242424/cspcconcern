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
    .field select, .field input{width:100%; padding:.55rem .6rem; border:1.5px solid var(--line);
        border-radius:9px; font-family:inherit; font-size:.86rem; background:#fcfdff; color:var(--ink)}
    .field select:focus, .field input:focus{outline:none; border-color:var(--brand); box-shadow:0 0 0 3px var(--brand-50)}
    .user-form .btn{flex:0 0 auto}

    .user-actions{display:flex; flex-wrap:wrap; gap:.5rem; align-items:center;
        padding-top:.75rem; border-top:1px dashed var(--line)}
    .user-actions form{display:flex; flex-wrap:wrap; gap:.5rem; align-items:center; margin:0}
    .ban-reason{flex:1 1 160px; min-width:0; padding:.5rem .6rem; border:1.5px solid var(--line);
        border-radius:9px; font-family:inherit; font-size:.84rem; background:#fcfdff; color:var(--ink)}
    .spacer{flex:1 1 auto}

    .no-match{color:var(--muted); font-size:.9rem; padding:1rem 0}

    /* Which classes a staff member advises. A list rather than a field,
       because one instructor advises several. */
    .advises{padding-top:.75rem; border-top:1px dashed var(--line)}
    .advises-head{display:flex; flex-wrap:wrap; gap:.5rem; align-items:baseline; margin-bottom:.45rem}
    .advises-title{font-size:.7rem; font-weight:600; letter-spacing:.05em;
        text-transform:uppercase; color:var(--muted)}
    .advises-none{font-size:.82rem; color:var(--muted)}
    .advises-list{list-style:none; margin:0 0 .6rem; padding:0; display:flex;
        flex-direction:column; gap:.3rem}
    .advises-list li{display:flex; gap:.6rem; align-items:center; justify-content:space-between;
        background:#f7f9fd; border:1px solid var(--line); border-radius:8px;
        padding:.4rem .6rem; font-size:.86rem}
    .advises-list form{margin:0}
    .advises-term{color:var(--muted); font-size:.78rem; margin-left:.35rem}
    .advises-remove{background:none; border:none; padding:0; cursor:pointer; font-family:inherit;
        font-size:.8rem; font-weight:600; color:var(--danger-ink)}
    .advises-remove:hover{text-decoration:underline}
    .advises-add{display:flex; flex-wrap:wrap; gap:.6rem; align-items:flex-end; margin:0}
    .advises-add .btn{flex:0 0 auto}

    /* A pending role request. Amber rather than red: somebody asking to be an
       instructor is the system working, not a problem to clear. */
    .role-request{width:100%; display:flex; flex-wrap:wrap; gap:.6rem; align-items:center;
        justify-content:space-between; margin-top:.5rem;
        background:var(--warn-bg); border:1px solid #f3dca0; color:#7a5200;
        border-radius:9px; padding:.55rem .75rem; font-size:.85rem}
    .role-request-actions{display:flex; gap:.45rem; flex:0 0 auto}
    .role-request-actions form{margin:0}

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
                         $user->employee_id,
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
                                @if ($user->employee_id) · Employee ID {{ $user->employee_id }} @endif
                            </span>
                        @endif

                        @if ($user->status === 'banned' && $user->ban_reason)
                            <span class="user-note">Banned: {{ $user->ban_reason }}</span>
                        @endif

                        {{-- A staff member filled in their own details on first
                             sign-in. College, programme and section were saved
                             as given; the role waited here, because role IS
                             permission -- a self-granted Guidance Counselor
                             would read every mental-health report in the
                             college. One press either way. --}}
                        @if ($user->requested_role_id)
                            <div class="role-request">
                                <span>
                                    Asked to be <strong>{{ optional($user->requestedRole)->name }}</strong>
                                    @if ($user->role_requested_at)
                                        · {{ $user->role_requested_at->diffForHumans() }}
                                    @endif
                                </span>
                                <span class="role-request-actions">
                                    <form action="{{ route('admin.users.roleRequest', $user) }}" method="POST"
                                          onsubmit="return confirm('Make {{ $user->name }} a {{ optional($user->requestedRole)->name }}? This decides which concerns they can read.')">
                                        @csrf
                                        <input type="hidden" name="decision" value="grant">
                                        <button type="submit" class="btn btn-success">Grant</button>
                                    </form>
                                    <form action="{{ route('admin.users.roleRequest', $user) }}" method="POST">
                                        @csrf
                                        <input type="hidden" name="decision" value="refuse">
                                        <button type="submit" class="btn btn-muted">Refuse</button>
                                    </form>
                                </span>
                            </div>
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

                            {{-- Year, class and student number: the three
                                 things only a student has.

                                 The year and the class letter are stored as
                                 one value -- "4A" -- because that is what the
                                 adviser lookup matches and what the
                                 start-of-year promotion increments. They are
                                 edited as two dropdowns because that is how a
                                 person thinks of them, and because a text box
                                 invites "4-A", "IV-A" and "4a", none of which
                                 the lookup would match. --}}
                            @php
                                $studentYear = $user->section ? (int) substr($user->section, 0, 1) : null;
                                $studentClass = $user->section ? strtoupper(substr($user->section, 1, 1)) : null;
                            @endphp
                            <div class="field js-student-wrap" style="flex:0 0 96px;"
                                 @unless ($roleName === 'Student') hidden @endunless>
                                <label for="year-{{ $user->id }}">Year</label>
                                <select name="year" id="year-{{ $user->id }}">
                                    <option value="">—</option>
                                    @foreach (range(1, 6) as $year)
                                        <option value="{{ $year }}" {{ $studentYear === $year ? 'selected' : '' }}>{{ $year }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="field js-student-wrap" style="flex:0 0 96px;"
                                 @unless ($roleName === 'Student') hidden @endunless>
                                <label for="section-{{ $user->id }}">Class</label>
                                <select name="section_letter" id="section-{{ $user->id }}">
                                    <option value="">—</option>
                                    @foreach (range('A', 'H') as $letter)
                                        <option value="{{ $letter }}" {{ $studentClass === $letter ? 'selected' : '' }}>{{ $letter }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="field js-student-wrap"
                                 @unless ($roleName === 'Student') hidden @endunless>
                                <label for="studentid-{{ $user->id }}">Student ID</label>
                                <input type="text" name="student_id" id="studentid-{{ $user->id }}"
                                       value="{{ $user->student_id }}" maxlength="50" placeholder="e.g. 231002370">
                            </div>

                            {{-- The staff equivalent. A separate field, not a
                                 shared one: the two numbers come from
                                 different offices, and one column would return
                                 a staff member when an admin searches for a
                                 student's id. Staff enter their own at sign-up;
                                 this is where it gets corrected. --}}
                            <div class="field js-staff-wrap"
                                 @if ($roleName === 'Student' || $roleName === 'No role') hidden @endif>
                                <label for="employeeid-{{ $user->id }}">Employee ID</label>
                                <input type="text" name="employee_id" id="employeeid-{{ $user->id }}"
                                       value="{{ $user->employee_id }}" maxlength="50" placeholder="Staff number">
                            </div>

                            <button type="submit" class="btn btn-secondary">Update</button>
                        </form>

                        {{-- Which classes this person advises.

                             A list, not a field. One instructor advises several
                             sections -- three each is normal here -- so it
                             could never live on the account, which holds a
                             single string. It lives in the sections table, one
                             row per class per term, which is also what
                             Section::adviserFor() reads when an academic
                             concern needs a destination.

                             Students are excluded: they are IN a class, and
                             that is the Year and Class pair above. --}}
                        @if ($roleName !== 'Student' && $roleName !== 'No role')
                            <div class="advises">
                                <div class="advises-head">
                                    <span class="advises-title">Advises</span>
                                    @if ($user->advisedSections->isEmpty())
                                        <span class="advises-none">No classes yet — academic concerns reach them only as a college-level fallback.</span>
                                    @endif
                                </div>

                                @if ($user->advisedSections->isNotEmpty())
                                    <ul class="advises-list">
                                        @foreach ($user->advisedSections as $advised)
                                            <li>
                                                <span>{{ $advised->course }} <strong>{{ $advised->section }}</strong>
                                                    <span class="advises-term">{{ $advised->school_year }} · {{ $advised->semester }}</span>
                                                </span>
                                                <form action="{{ route('admin.users.sections.unassign', [$user, $advised]) }}" method="POST"
                                                      onsubmit="return confirm('Take {{ $user->name }} off {{ $advised->course }} {{ $advised->section }}?\n\nThat class will have no adviser, so its concerns fall back to the college.')">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="advises-remove" aria-label="Remove {{ $advised->course }} {{ $advised->section }}">Remove</button>
                                                </form>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif

                                <form action="{{ route('admin.users.sections.assign', $user) }}" method="POST" class="advises-add">
                                    @csrf
                                    <div class="field">
                                        <label for="adv-course-{{ $user->id }}">Programme</label>
                                        <select name="course" id="adv-course-{{ $user->id }}" required>
                                            <option value="">— choose —</option>
                                            @foreach ($courses as $college => $collegeCourses)
                                                <optgroup label="{{ $college }}">
                                                    @foreach ($collegeCourses as $course)
                                                        <option value="{{ $course }}">{{ $course }}</option>
                                                    @endforeach
                                                </optgroup>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="field" style="flex:0 0 88px;">
                                        <label for="adv-year-{{ $user->id }}">Year</label>
                                        <select name="year" id="adv-year-{{ $user->id }}" required>
                                            @foreach (range(1, 6) as $year)
                                                <option value="{{ $year }}">{{ $year }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="field" style="flex:0 0 88px;">
                                        <label for="adv-class-{{ $user->id }}">Class</label>
                                        <select name="section_letter" id="adv-class-{{ $user->id }}" required>
                                            @foreach (range('A', 'H') as $letter)
                                                <option value="{{ $letter }}">{{ $letter }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <button type="submit" class="btn btn-muted">Add class</button>
                                </form>
                            </div>
                        @endif

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
            if (!form) return;

            var wrap = form.querySelector('.js-course-wrap');
            // Year, class and student number: only a student has them, and on
            // anybody else a student number would put a staff account in the
            // search results for a student id.
            var studentOnly = Array.prototype.slice.call(form.querySelectorAll('.js-student-wrap'));
            var staffOnly = Array.prototype.slice.call(form.querySelectorAll('.js-staff-wrap'));

            function sync() {
                var name = roleEl.options[roleEl.selectedIndex].getAttribute('data-role-name');
                if (wrap) wrap.hidden = PROGRAMME_ROLES.indexOf(name) === -1;
                studentOnly.forEach(function (field) { field.hidden = name !== 'Student'; });
                staffOnly.forEach(function (field) { field.hidden = name === 'Student' || name === 'No role'; });
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
