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
            Your college and programme are saved as you enter them. <strong>The role is a request</strong> — an administrator has to approve it, because a role decides which concerns you can read.
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
                <label for="course">Programme you chair</label>
                <select name="course" id="course">
                    <option value="">— select a programme —</option>
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

            <div class="fg" id="section-group" hidden>
                <label for="section">Section you advise <span style="font-weight:400;color:var(--muted);">(optional)</span></label>
                <input type="text" name="section" id="section" maxlength="12"
                       value="{{ old('section') }}" placeholder="e.g. 3A">
                <p class="hint">Leave blank if you do not advise a class. Academic concerns from a section reach its adviser first.</p>
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
            var sectionGroup = document.getElementById('section-group');
            var course = document.getElementById('course');

            function chosenRole() {
                var opt = role.options[role.selectedIndex];
                return opt ? (opt.getAttribute('data-name') || '') : '';
            }

            function apply() {
                var name = chosenRole();

                courseGroup.hidden = name !== 'Program Chair';
                sectionGroup.hidden = !(name === 'Instructor' || name === 'Program Chair');

                // A hidden field still posts its value, so clear what is no
                // longer being asked for. Otherwise switching from Program
                // Chair to Instructor carries a programme along and quietly
                // makes them the preferred handler for it.
                if (courseGroup.hidden) course.value = '';
                if (sectionGroup.hidden) document.getElementById('section').value = '';

                Array.prototype.forEach.call(course.options, function (option) {
                    var belongs = !option.value || option.getAttribute('data-college') === dept.value;
                    option.hidden = !belongs;
                    if (!belongs && option.selected) course.value = '';
                });
            }

            role.addEventListener('change', apply);
            dept.addEventListener('change', apply);
            apply();
        })();
    </script>
</body>
</html>
