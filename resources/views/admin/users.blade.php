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
    /* Dropdowns beside the search box. The box alone matched on one string,
       so "every dean" meant typing "dean" and reading past every account whose
       college or advised class happened to contain it. These narrow by one
       field at a time, and combine with whatever is typed. */
    .user-filter{padding:.55rem .7rem; border:1.5px solid var(--line); border-radius:10px;
        font-size:.85rem; background:#fff; color:var(--ink); cursor:pointer; max-width:220px}
    .user-filter:focus{outline:none; border-color:var(--brand); box-shadow:0 0 0 4px var(--brand-50)}
    .user-filter-clear{color:var(--muted); font-size:.82rem; text-decoration:underline; cursor:pointer;
        background:none; border:none; padding:0}
    /* The extra-roles panel: a quiet list of checkboxes under the main
       role form, so the two are visibly different decisions.

       Each row has to undo two rules it sits inside. .field label shouts in
       uppercase at .7rem, which is right for a field caption and wrong for a
       person-readable role name; and .field input stretches every control to
       width:100%, which turned each checkbox into a full-width bar and left
       its name floating beside it. */
    .extra-roles{border-top:1px dashed var(--line); padding-top:.9rem; margin-top:.2rem}
    .extra-role-list{display:grid; grid-template-columns:repeat(auto-fill,minmax(185px,1fr));
        gap:.35rem .9rem; margin-top:.35rem}
    .field .extra-role{display:flex; align-items:center; gap:.5rem; margin:0; cursor:pointer;
        font-size:.85rem; font-weight:400; letter-spacing:normal; text-transform:none;
        color:var(--ink); white-space:nowrap}
    .field .extra-role input{width:auto; flex:0 0 auto; margin:0; padding:0;
        border:0; box-shadow:none}

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
    /* What they said at sign-up, waiting to be confirmed. Amber, like a role
       request: something for the admin to act on, not an error. */
    .advises-suggested{margin:0 0 .6rem; padding:.45rem .65rem; font-size:.84rem;
        background:var(--warn-bg); border:1px solid #f3dca0; border-radius:8px; color:#7a5200}

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
    /* ---- Add an account ---- */
    .add-account{border:1px solid var(--line,#e7ebf1); border-radius:14px;
        background:#fff; margin-bottom:1.25rem; padding:0 1.1rem}
    .add-account summary{cursor:pointer; padding:.9rem 0; font-weight:700; color:var(--navy,#0D1B3E);
        list-style:none; display:flex; align-items:center; gap:.5rem}
    .add-account summary::-webkit-details-marker{display:none}
    .add-account summary::before{content:"+"; display:inline-grid; place-items:center;
        width:20px; height:20px; border-radius:6px; background:#eef2ff; color:#2f5bea;
        font-weight:800; line-height:1}
    .add-account[open] summary::before{content:"\2212"}
    .add-account-note{color:var(--muted,#64748b); font-size:.84rem; line-height:1.5;
        margin:0 0 .9rem; max-width:70ch}
    .add-account-errors{background:#fde7ea; border:1px solid #f6c9cf; color:#a31726;
        border-radius:10px; padding:.75rem .9rem; margin-bottom:.9rem; font-size:.86rem}
    .add-account-errors ul{margin:.3rem 0 0; padding-left:1.1rem}
    .add-account-form{display:flex; flex-wrap:wrap; gap:.75rem; padding-bottom:1.1rem}
    .add-account-form .field{flex:1 1 220px; display:flex; flex-direction:column; gap:.3rem}
    .add-account-form label{font-size:.76rem; text-transform:uppercase; letter-spacing:.04em;
        color:var(--muted,#64748b); font-weight:700}
    .add-account-form input, .add-account-form select{width:100%; padding:.55rem .65rem;
        border:1.5px solid var(--line,#e7ebf1); border-radius:9px; font-family:inherit;
        font-size:.88rem; background:#fcfdff; color:inherit}
    .add-account-form input:focus, .add-account-form select:focus{outline:none;
        border-color:#2f5bea; box-shadow:0 0 0 4px rgba(47,91,234,.12)}
    .field-hint{font-size:.74rem; color:var(--muted,#64748b); font-weight:400; text-transform:none;
        letter-spacing:0}
    .add-account-submit{flex:1 1 100%; align-items:flex-start; justify-content:flex-end}
    @media(max-width:640px){.add-account-form .field{flex:1 1 100%}}
</style>

<div class="card">
    <div style="margin-bottom:1.25rem;">
        <h1>Manage Users</h1>
        <p style="color:var(--muted); margin-top:.5rem;">
            Every registered account, who's currently online, and when everyone else was last active.
        </p>
    </div>

    {{-- The layout only shows flashed success and error messages, not
         validation errors, so a refused update used to reload the page with
         nothing changed and nothing said. --}}
    @if ($errors->any())
        <div class="alert alert-error" role="alert">{{ implode(' ', $errors->all()) }}</div>
    @endif

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

    {{-- An empty page with a filter on it is not an empty system. Saying "no
         accounts registered yet" to an admin who just searched for a misspelt
         name would read as the roster having been wiped, so the toolbar stays
         on screen and the message below the list explains itself. --}}
    {{-- Adding an account, which until now could only be done from a terminal
         with `php artisan user:add`. Folded away by default: the page is for
         the roster, and this is the occasional job.

         Open on its own when the last attempt failed, so an administrator is
         never shown an error about a form they cannot see. --}}
    @php
        $addFailed = $errors->hasAny(['name', 'email', 'role_id', 'department', 'course', 'year', 'section_letter', 'student_id', 'employee_id']);
    @endphp
    <details class="add-account" @if ($addFailed) open @endif>
        <summary>Add an account</summary>

        <p class="add-account-note">
            The account is created dormant. It comes alive the first time that person signs in
            with CSPC Mail, keeping everything set here &mdash; no password is made, because there
            is no password sign-in.
        </p>

        @if ($addFailed)
            <div class="add-account-errors">
                <strong>That could not be saved.</strong>
                <ul>
                    @foreach ($errors->all() as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('admin.users.store') }}" class="add-account-form">
            @csrf

            <div class="field">
                <label for="new-name">Full name</label>
                <input type="text" id="new-name" name="name" value="{{ old('name') }}" required
                       placeholder="Juan Dela Cruz">
            </div>

            <div class="field">
                <label for="new-email">CSPC address</label>
                <input type="email" id="new-email" name="email" value="{{ old('email') }}" required
                       placeholder="juan@my.cspc.edu.ph">
                <span class="field-hint">@my.cspc.edu.ph for a student, @cspc.edu.ph for staff.</span>
            </div>

            <div class="field">
                <label for="new-role">Role</label>
                <select id="new-role" name="role_id" required>
                    <option value="">— choose —</option>
                    @foreach ($roles as $role)
                        <option value="{{ $role->id }}" {{ (string) old('role_id') === (string) $role->id ? 'selected' : '' }}>{{ $role->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="field">
                <label for="new-college">College or unit</label>
                <select id="new-college" name="department">
                    <option value="">— none —</option>
                    <optgroup label="Colleges">
                        @foreach ($colleges as $college)
                            <option value="{{ $college }}" {{ old('department') === $college ? 'selected' : '' }}>{{ $college }}</option>
                        @endforeach
                    </optgroup>
                    @if (! empty($otherUnits))
                        <optgroup label="Offices and units">
                            @foreach ($otherUnits as $unit)
                                <option value="{{ $unit }}" {{ old('department') === $unit ? 'selected' : '' }}>{{ $unit }}</option>
                            @endforeach
                        </optgroup>
                    @endif
                </select>
            </div>

            <div class="field">
                <label for="new-course">Program</label>
                {{-- Grouped by college and filtered by the choice above, so a
                     Computer Studies student cannot be filed under BS Nursing
                     -- a row whose programme and college disagree is invisible
                     to both colleges' staff. --}}
                <select id="new-course" name="course">
                    <option value="">— none —</option>
                    @foreach ($courses as $college => $collegeCourses)
                        <optgroup label="{{ $college }}" data-college="{{ $college }}">
                            @foreach ($collegeCourses as $course)
                                <option value="{{ $course }}" {{ old('course') === $course ? 'selected' : '' }}>{{ $course }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
            </div>

            <div class="field" style="flex:0 0 90px;">
                <label for="new-year">Year</label>
                {{-- Rebuilt from the programme by the script at the foot of
                     this page, which every year picker here shares. --}}
                <select id="new-year" name="year" data-course-select="new-course">
                    <option value="">—</option>
                    @foreach (\App\Models\User::yearLevelsFor(old('course')) as $year)
                        <option value="{{ $year }}" {{ (string) old('year') === (string) $year ? 'selected' : '' }}>{{ $year }}</option>
                    @endforeach
                </select>
            </div>

            <div class="field" style="flex:0 0 90px;">
                <label for="new-class">Class</label>
                <select id="new-class" name="section_letter">
                    <option value="">—</option>
                    @foreach (range('A', 'H') as $letter)
                        <option value="{{ $letter }}" {{ old('section_letter') === $letter ? 'selected' : '' }}>{{ $letter }}</option>
                    @endforeach
                </select>
            </div>

            <div class="field">
                <label for="new-student-id">Student number</label>
                <input type="text" id="new-student-id" name="student_id" value="{{ old('student_id') }}"
                       placeholder="231001234">
            </div>

            <div class="field">
                <label for="new-employee-id">Staff number</label>
                <input type="text" id="new-employee-id" name="employee_id" value="{{ old('employee_id') }}">
            </div>

            <div class="field add-account-submit">
                <button type="submit" class="btn btn-primary">Add account</button>
            </div>
        </form>
    </details>
    @if ($users->isEmpty() && ! array_filter($filters))
        <p style="color: var(--muted);">No accounts registered yet.</p>
    @else
        {{-- A GET form, so a filtered roster can be linked and bookmarked, and
             so the paginator carries the filters with it. --}}
        <form method="GET" action="{{ route('admin.users') }}" class="user-toolbar">
            <input type="search" id="user-search" name="q" value="{{ $filters['q'] }}" class="user-search"
                   autocomplete="off"
                   placeholder="Search by name or student ID — also email, college, programme, section"
                   aria-label="Search accounts by name or student ID">
            {{-- The options come from the whole table, not this page: a role
                 held only by somebody on page four still has to be selectable.
                 The college list includes the offices -- Guidance, ICT,
                 Records -- which are not in COURSES_BY_COLLEGE at all. --}}
            <select name="role" class="user-filter" aria-label="Filter by role" onchange="this.form.submit()">
                <option value="">All roles</option>
                @foreach ($roleOptions as $option)
                    <option value="{{ $option }}" @selected($filters['role'] === $option)>{{ $option }}</option>
                @endforeach
            </select>

            <select name="college" class="user-filter" aria-label="Filter by college or office" onchange="this.form.submit()">
                <option value="">All colleges &amp; offices</option>
                @foreach ($collegeOptions as $option)
                    <option value="{{ $option }}" @selected($filters['college'] === $option)>{{ $option }}</option>
                @endforeach
            </select>

            <select name="status" class="user-filter" aria-label="Filter by status" onchange="this.form.submit()">
                <option value="">Any status</option>
                @foreach ($statusOptions as $option)
                    <option value="{{ $option }}" @selected($filters['status'] === $option)>{{ ucfirst($option) }}</option>
                @endforeach
                {{-- Not a value of the status column: deleted accounts are
                     absent from normal results altogether. It belongs in this
                     dropdown anyway, because this is where an administrator
                     looks for an account that is not where they left it. --}}
                <option value="deleted" @selected($filters['status'] === 'deleted')>Deleted</option>
            </select>

            <button type="submit" class="btn btn-muted" style="padding:.5rem 1rem; font-size:.85rem;">Search</button>

            @if (array_filter($filters))
                <a href="{{ route('admin.users') }}" class="user-filter-clear">Clear</a>
            @endif

            <span class="user-count" id="user-count">
                {{ $users->total() }} {{ Str::plural('account', $users->total()) }}
                @if ($users->hasPages())
                    &middot; page {{ $users->currentPage() }} of {{ $users->lastPage() }}
                @endif
            </span>
        </form>

        <div class="user-list" id="user-list">
            @foreach ($users as $user)
                @php
                    $roleName = optional($user->role)->name ?? 'No role';
                    $isSelf = $user->id === auth()->id();
                @endphp
                <div class="user-card {{ $isSelf ? 'is-self' : '' }}"
                     data-role="{{ Str::lower($roleName) }}"
                     data-college="{{ Str::lower($user->department ?? '') }}"
                     data-status="{{ Str::lower($user->status ?? '') }}"
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
                         // The class advisers. Nearly all of them hold another
                         // role -- Instructor, Program Chair, Dean -- so a search
                         // for "adviser" found nobody, and neither did the class
                         // they advise: the person to replace could not be found
                         // from the one thing the admin knew about them.
                         $user->advisedSections->isNotEmpty()
                             ? 'adviser class adviser advises '.$user->advisedSections
                                 ->map(fn ($s) => $s->course.' '.$s->section)
                                 ->implode(' ')
                             : null,
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
                                <label for="course-{{ $user->id }}">Program</label>
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
                                {{-- As many years as their programme runs to:
                                     four, or five for Architecture. It used
                                     to offer six to everybody, two of which
                                     no programme here has. --}}
                                <select name="year" id="year-{{ $user->id }}"
                                        data-course-select="course-{{ $user->id }}">
                                    <option value="">—</option>
                                    @foreach (\App\Models\User::yearLevelsFor($user->course) as $year)
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

                        {{-- A second hat.
                             The role above is what the account IS -- what it is
                             listed as, and what routing matches on when choosing
                             a handler. These add what another role can READ and
                             the pages it can open, for somebody who covers two
                             jobs. They do not start sending that role's work to
                             them, which is why they are a separate form with a
                             separate sentence. --}}
                        @php
                            $heldExtra = $user->additionalRoles->pluck('id')->all();
                        @endphp
                        <form action="{{ route('admin.users.additionalRoles', $user) }}" method="POST" class="user-form extra-roles"
                              onsubmit="return confirm('Update the extra roles {{ $user->name }} holds?')">
                            @csrf

                            <div class="field" style="grid-column:1 / -1;">
                                <label>Also holds</label>
                                <p style="color:var(--muted); font-size:.8rem; margin:0 0 .4rem;">
                                    Extra roles let them read and open what that role can. Their main role above is unchanged.
                                </p>
                                <div class="extra-role-list">
                                    @foreach ($roles as $role)
                                        @continue($role->id === $user->role_id)
                                        <label class="extra-role">
                                            <input type="checkbox" name="role_ids[]" value="{{ $role->id }}"
                                                   {{ in_array($role->id, $heldExtra, true) ? 'checked' : '' }}>
                                            <span>{{ $role->name }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </div>

                            {{-- Its own line. Sharing the row clipped the last
                                 column, so "Vice President for Academic
                                 Affairs" read as "Vice President for
                                 Academi…" -- the one role whose name has to be
                                 read in full to be told from the others. --}}
                            <div style="flex:1 0 100%; display:flex; justify-content:flex-end;">
                                <button type="submit" class="btn btn-secondary">Save extra roles</button>
                            </div>
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

                                {{-- The class they named when they signed up. It routes
                                     nothing until confirmed, and Add class below comes
                                     pre-filled with it, so confirming is one press. --}}
                                @php
                                    [$suggestedCourse, $suggestedSection] = array_pad(explode('|', (string) $user->advises_request, 2), 2, null);
                                    $suggestedYear = $suggestedSection ? substr($suggestedSection, 0, 1) : null;
                                    $suggestedLetter = $suggestedSection ? substr($suggestedSection, 1, 1) : null;
                                @endphp
                                @if ($user->advises_request)
                                    <p class="advises-suggested">Says they advise <strong>{{ $suggestedCourse }} {{ $suggestedSection }}</strong> — check it, then press <strong>Add class</strong> to confirm.</p>
                                @endif

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
                                        <label for="adv-course-{{ $user->id }}">Program</label>
                                        <select name="course" id="adv-course-{{ $user->id }}" required>
                                            <option value="">— choose —</option>
                                            @foreach ($courses as $college => $collegeCourses)
                                                <optgroup label="{{ $college }}">
                                                    @foreach ($collegeCourses as $course)
                                                        <option value="{{ $course }}" {{ $suggestedCourse === $course ? 'selected' : '' }}>{{ $course }}</option>
                                                    @endforeach
                                                </optgroup>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="field" style="flex:0 0 88px;">
                                        <label for="adv-year-{{ $user->id }}">Year</label>
                                        <select name="year" id="adv-year-{{ $user->id }}" required
                                                data-course-select="adv-course-{{ $user->id }}">
                                            @foreach (\App\Models\User::yearLevelsFor($suggestedCourse) as $year)
                                                <option value="{{ $year }}" {{ (string) $suggestedYear === (string) $year ? 'selected' : '' }}>{{ $year }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="field" style="flex:0 0 88px;">
                                        <label for="adv-class-{{ $user->id }}">Class</label>
                                        <select name="section_letter" id="adv-class-{{ $user->id }}" required>
                                            @foreach (range('A', 'H') as $letter)
                                                <option value="{{ $letter }}" {{ $suggestedLetter === $letter ? 'selected' : '' }}>{{ $letter }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <button type="submit" class="btn btn-muted">Add class</button>
                                </form>
                            </div>
                        @endif

                        <div class="user-actions">
                            @if ($user->trashed())
                                {{-- Everything this account owns is still
                                     here; restoring brings it all back. The
                                     person can also do it themselves simply
                                     by signing in. --}}
                                <form action="{{ route('admin.users.restore', $user) }}" method="POST"
                                      onsubmit="return confirm('Restore {{ $user->name }}\'s account? Everything they filed comes back with it.')">
                                    @csrf
                                    <button type="submit" class="btn btn-success">Restore account</button>
                                </form>

                                <span class="spacer"></span>

                                <form action="{{ route('admin.users.erase', $user) }}" method="POST"
                                      onsubmit="return confirm('PERMANENTLY erase {{ $user->name }}\'s account, every concern they submitted and every file they uploaded? This cannot be undone.')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-ghost-danger">Erase permanently</button>
                                </form>
                            @else
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
                                  onsubmit="return confirm('Delete {{ $user->name }}\'s account? They will not be able to sign in. Everything they filed is kept and comes back if the account is restored.')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-ghost-danger">Delete account</button>
                            </form>
                            @endif
                        </div>
                    @endif
                </div>
            @endforeach
        </div>

        @if ($users->hasPages())
            <div style="margin-top:1.5rem; display:flex; justify-content:center;">
                {{ $users->onEachSide(1)->links('pagination::bootstrap-4') }}
            </div>
        @endif

        {{-- An empty result now comes from the server, so it is rendered
             rather than revealed: with no rows there is nothing to hide. --}}
        @if ($users->isEmpty())
            <p class="no-match">No account matches that. <a href="{{ route('admin.users') }}">Clear the filters</a>.</p>
        @endif
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

        // Searching and filtering happen on the server now, so there is
        // nothing to hide here. What is left is the convenience of not having
        // to reach for the button: the dropdowns submit on change (inline, in
        // the markup) and Enter submits the text box on its own.
        if (!search) return;
    })();
</script>

{{-- Year levels follow the programme chosen beside them.
     Each year picker names its partner course select, so this works for
     both the student's own year and the class an adviser is being given,
     on every row of the page.

     Options are REBUILT rather than hidden: hiding an <option> is not
     honoured in every browser, and a list that silently stays whole is
     worse than not filtering at all. --}}
<script>
    (function () {
        var yearsByCourse = @json(\App\Models\User::YEARS_BY_COURSE);

        function rebuild(yearSelect, courseSelect) {
            var max = yearsByCourse[courseSelect.value] || 4;
            var chosen = yearSelect.value;
            var optional = yearSelect.querySelector('option[value=""]') !== null;

            yearSelect.textContent = '';

            if (optional) {
                var blank = document.createElement('option');
                blank.value = '';
                blank.textContent = '—';
                yearSelect.appendChild(blank);
            }

            for (var level = 1; level <= max; level++) {
                var option = document.createElement('option');
                option.value = String(level);
                option.textContent = String(level);
                yearSelect.appendChild(option);
            }

            // Keep their choice unless the new programme is too short for it.
            yearSelect.value = chosen !== '' && Number(chosen) <= max ? chosen : (optional ? '' : '1');
        }

        Array.prototype.forEach.call(
            document.querySelectorAll('select[data-course-select]'),
            function (yearSelect) {
                var courseSelect = document.getElementById(yearSelect.dataset.courseSelect);
                if (!courseSelect) return;

                courseSelect.addEventListener('change', function () {
                    rebuild(yearSelect, courseSelect);
                });

                rebuild(yearSelect, courseSelect);
            }
        );
    })();
</script>
@endsection
