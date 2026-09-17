<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Complete your details - CSPC Report Concern</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root{--navy:#0D1B3E;--brand:#2f5bea;--brand6:#2347c4;--brand50:#eef2ff;
            --ink:#1f2733;--muted:#64748b;--line:#e7ebf1;--danger-bg:#fde7ea;--danger-ink:#a31726;
            --warn-bg:#fff4d6;--warn-ink:#7a5200}
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Inter',-apple-system,'Segoe UI',sans-serif;color:var(--ink);min-height:100vh;
            display:flex;align-items:center;justify-content:center;padding:2rem;
            background:linear-gradient(180deg,#eef2f9,#f6f8fc);-webkit-font-smoothing:antialiased}
        .card{background:#fff;border:1px solid var(--line);border-radius:18px;
            box-shadow:0 24px 60px -18px rgba(13,27,62,.28);padding:2.4rem;width:100%;max-width:480px}
        h1{font-size:1.4rem;font-weight:800;color:var(--navy);letter-spacing:-.02em;text-align:center}
        .sub{text-align:center;color:var(--muted);font-size:.92rem;margin-top:.4rem;margin-bottom:1.6rem}
        label{display:block;font-weight:600;font-size:.88rem;margin-bottom:.4rem}
        .fg{margin-bottom:1.15rem}
        .fg[hidden]{display:none}
        input,select{width:100%;padding:.82rem .9rem;border:1.5px solid var(--line);border-radius:11px;
            font-size:.95rem;font-family:inherit;background:#fcfdff;transition:.18s}
        input:focus,select:focus{outline:none;border-color:var(--brand);box-shadow:0 0 0 4px var(--brand50);background:#fff}
        .hint{font-size:.76rem;color:var(--muted);margin-top:.35rem}
        .btn{width:100%;padding:.9rem;background:linear-gradient(180deg,var(--brand),var(--brand6));color:#fff;
            border:none;border-radius:11px;font-size:1rem;font-weight:700;cursor:pointer;
            box-shadow:0 8px 20px -6px rgba(47,91,234,.5)}
        .btn:hover{transform:translateY(-1px)}
        .alert-error{background:var(--danger-bg);color:var(--danger-ink);border:1px solid #f6c9cf;
            padding:.85rem 1rem;border-radius:11px;margin-bottom:1.3rem;font-size:.9rem}
        .alert-error strong{display:block;margin-bottom:.2rem}
        .alert-error ul{margin:0;padding-left:1.1rem}
        .note{background:var(--warn-bg);border:1px solid #f3dca0;color:var(--warn-ink);
            padding:.8rem .95rem;border-radius:11px;font-size:.83rem;line-height:1.5;margin-bottom:1.3rem}
        .who{text-align:center;color:var(--muted);font-size:.8rem;margin-top:1.1rem}
    </style>
</head>
<body>
    <div class="card">
        <h1>Tell us where you work</h1>
        <p class="sub">You signed in with CSPC Mail. A staff address proves you work here, but not what you do — so please fill this in once.</p>

        @if ($errors->any())
            <div class="alert-error">
                <strong>Please check the form</strong>
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- Said plainly and up front. The role is the one field here that is
             a request rather than a fact, and somebody who is not told will
             assume they are a dean the moment they press the button. --}}
        <div class="note">
            Your college and program are saved as you enter them. <strong>The role is a request</strong> — an administrator has to approve it, because a role decides which concerns you can read.
        </div>

        <form method="POST" action="{{ route('profile.complete.post') }}">
            @csrf

            <div class="fg">
                <label for="requested_role_id">What is your role?</label>
                <select name="requested_role_id" id="requested_role_id" required>
                    <option value="">— select your role —</option>
                    @foreach ($roles as $role)
                        <option value="{{ $role->id }}" data-name="{{ $role->name }}"
                            {{ (string) old('requested_role_id') === (string) $role->id ? 'selected' : '' }}>
                            {{ $role->name }}
                        </option>
                    @endforeach
                </select>
                <p class="hint">Pending approval. Until then you can sign in, but you will not receive concerns as this role.</p>
            </div>

            {{-- Their staff number. Asked for here because the person filling
                 this in is the one who knows it, and CSPC's own records are
                 keyed on it -- two people in this database already share a
                 name, so it is what tells an admin which account is which. --}}
            <div class="fg">
                <label for="employee_id">Employee ID <span style="font-weight:400;color:var(--muted);">(optional)</span></label>
                <input type="text" name="employee_id" id="employee_id" maxlength="50"
                       value="{{ old('employee_id') }}" placeholder="Your staff number">
            </div>

            <div class="fg">
                <label for="department">College or office</label>
                <select name="department" id="department" required>
                    <option value="">— select where you work —</option>
                    <optgroup label="Colleges">
                        @foreach (array_keys($collegeCourses) as $college)
                            <option value="{{ $college }}" {{ old('department') === $college ? 'selected' : '' }}>{{ $college }}</option>
                        @endforeach
                    </optgroup>
                    <optgroup label="Offices and units">
                        @foreach ($units as $unit)
                            <option value="{{ $unit }}" {{ old('department') === $unit ? 'selected' : '' }}>{{ $unit }}</option>
                        @endforeach
                    </optgroup>
                </select>
                <p class="hint">Concerns from this college reach you first.</p>
            </div>

            {{-- Only a Program Chair covers one programme. On anybody else it
                 is worse than clutter: findHandler() would start preferring
                 them for that programme's concerns. --}}
            <div class="fg" id="course-group" hidden>
                <label for="course">Program you chair</label>
                <select name="course" id="course">
                    <option value="">— select a program —</option>
                    @foreach ($collegeCourses as $college => $courses)
                        <optgroup label="{{ $college }}">
                            @foreach ($courses as $course)
                                <option value="{{ $course }}" data-college="{{ $college }}"
                                    {{ old('course') === $course ? 'selected' : '' }}>{{ $course }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
            </div>

            {{-- Any role can advise a class -- instructors mostly, but chairs and
                 deans do too -- so this is offered to everybody. It is a
                 suggestion for an administrator, not an assignment: nothing is
                 routed to them until Add class confirms it on Manage Users.
                 Three dropdowns rather than a typed "3A", which invited "3-A"
                 and "III-A" that nothing could match. --}}
            <div class="fg" id="advises-group">
                <label for="advises_course">Class you advise <span style="font-weight:400;color:var(--muted);">(optional)</span></label>
                <select name="advises_course" id="advises_course">
                    <option value="">— program —</option>
                    @foreach ($collegeCourses as $college => $courses)
                        <optgroup label="{{ $college }}">
                            @foreach ($courses as $course)
                                <option value="{{ $course }}" data-college="{{ $college }}"
                                    {{ old('advises_course') === $course ? 'selected' : '' }}>{{ $course }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
                <div style="display:flex; gap:.6rem; margin-top:.6rem;">
                    <select name="advises_year" id="advises_year" aria-label="Year">
                        <option value="">— year —</option>
                        @foreach (range(1, 5) as $yearOption)
                            <option value="{{ $yearOption }}" {{ (string) old('advises_year') === (string) $yearOption ? 'selected' : '' }}>Year {{ $yearOption }}</option>
                        @endforeach
                    </select>
                    <select name="advises_letter" id="advises_letter" aria-label="Class">
                        <option value="">— class —</option>
                        @foreach (range('A', 'H') as $letterOption)
                            <option value="{{ $letterOption }}" {{ old('advises_letter') === $letterOption ? 'selected' : '' }}>Class {{ $letterOption }}</option>
                        @endforeach
                    </select>
                </div>
                <p class="hint">Leave all three empty if you do not advise a class. An administrator confirms it before that class's concerns reach you.</p>
            </div>

            <button type="submit" class="btn">Save and continue</button>
        </form>

        <p class="who">Signing in as {{ Auth::user()->email }}</p>
    </div>

    <script>
        // Show only the fields the chosen role actually uses, and narrow the
        // programme list to the chosen college -- the same pairing rule the
        // server enforces, so the form cannot offer a combination the POST
        // would reject.
        (function () {
            var role = document.getElementById('requested_role_id');
            var dept = document.getElementById('department');
            var courseGroup = document.getElementById('course-group');
            var course = document.getElementById('course');

            function chosenRole() {
                var opt = role.options[role.selectedIndex];
                return opt ? (opt.getAttribute('data-name') || '') : '';
            }

            function apply() {
                var name = chosenRole();

                courseGroup.hidden = name !== 'Program Chair';

                // A hidden field still posts its value, so clear what is no
                // longer being asked for. Otherwise switching from Program
                // Chair to Instructor carries a programme along and quietly
                // makes them the preferred handler for it.
                if (courseGroup.hidden) course.value = '';

                Array.prototype.forEach.call(course.options, function (option) {
                    var belongs = !option.value || option.getAttribute('data-college') === dept.value;
                    option.hidden = !belongs;
                    if (!belongs && option.selected) course.value = '';
                });

                // The class they advise is in their own college. Somebody who
                // picked an office rather than a college keeps every program on
                // offer, since the office is not where the class is.
                var advises = document.getElementById('advises_course');
                var picksCollege = Array.prototype.some.call(advises.options, function (option) {
                    return option.getAttribute('data-college') === dept.value;
                });

                Array.prototype.forEach.call(advises.options, function (option) {
                    var belongs = !option.value || !picksCollege || option.getAttribute('data-college') === dept.value;
                    option.hidden = !belongs;
                    if (!belongs && option.selected) advises.value = '';
                });
            }

            role.addEventListener('change', apply);
            dept.addEventListener('change', apply);
            apply();
        })();
    </script>
</body>
</html>
