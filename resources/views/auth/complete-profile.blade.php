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
            --ink:#1f2733;--muted:#64748b;--line:#e7ebf1;--danger-bg:#fde7ea;--danger-ink:#a31726}
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Inter',-apple-system,'Segoe UI',sans-serif;color:var(--ink);min-height:100vh;
            display:flex;align-items:center;justify-content:center;padding:2rem;
            background:linear-gradient(180deg,#eef2f9,#f6f8fc);-webkit-font-smoothing:antialiased}
        .card{background:#fff;border:1px solid var(--line);border-radius:18px;
            box-shadow:0 24px 60px -18px rgba(13,27,62,.28);padding:2.4rem;width:100%;max-width:460px}
        h1{font-size:1.4rem;font-weight:800;color:var(--navy);letter-spacing:-.02em;text-align:center}
        .sub{text-align:center;color:var(--muted);font-size:.92rem;margin-top:.4rem;margin-bottom:1.6rem}
        label{display:block;font-weight:600;font-size:.88rem;margin-bottom:.4rem}
        .fg{margin-bottom:1.15rem}
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
    </style>
</head>
<body>
    <div class="card">
        <h1>Almost there</h1>
        <p class="sub">You signed in with CSPC Mail, so we still need a few student details before you can file a concern.</p>

        @if ($errors->any())
            <div class="alert-error">
                <strong>Please fix the following</strong>
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form action="{{ route('profile.complete.post') }}" method="POST">
            @csrf
            <div class="fg">
                <label for="student_id">Student ID</label>
                <input type="text" id="student_id" name="student_id"
                       value="{{ old('student_id', Auth::user()->student_id) }}" placeholder="e.g. 2023-00123" required autofocus>
            </div>
            <div class="fg">
                <label for="department">College / Department</label>
                <select id="department" name="department" required>
                    <option value="">Select your college</option>
                    @foreach ($collegeCourses as $college => $courses)
                        <option value="{{ $college }}" {{ old('department', Auth::user()->department) === $college ? 'selected' : '' }}>{{ $college }}</option>
                    @endforeach
                </select>
                <div class="hint">Concerns you file are routed to this college.</div>
            </div>
            <div class="fg">
                <label for="course">Course</label>
                <select id="course" name="course" required>
                    <option value="">Select your college first</option>
                    @foreach ($collegeCourses as $college => $courses)
                        <optgroup label="{{ $college }}" data-college="{{ $college }}">
                            @foreach ($courses as $courseName)
                                <option value="{{ $courseName }}" {{ old('course', Auth::user()->course) === $courseName ? 'selected' : '' }}>{{ $courseName }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
            </div>
            {{-- Section is asked again, reversing an earlier decision to drop
                 it. The objection was that it goes stale and nothing routed on
                 it; the second half stopped being true when academic concerns
                 started reaching a student's class adviser, who advises a
                 SECTION rather than a college.

                 Optional, and stale is survivable: with no section, or one
                 nobody advises any more, a concern falls back to an adviser in
                 the college, then an instructor, then up the chain. It reaches
                 somebody either way -- the section decides whether it reaches
                 the person who actually knows them. --}}
            <div class="form-group">
                <label for="section">Year and section <span style="font-weight:400; color:#666;">(optional)</span></label>
                <input type="text" id="section" name="section" maxlength="12"
                       value="{{ old('section', Auth::user()->section) }}"
                       placeholder="e.g. 3A">
                <p style="font-size:.82rem; color:#666; margin-top:.35rem;">
                    Your year level and section letter together, like <strong>3A</strong>.
                    We use it to send academic concerns to your own class adviser.
                </p>
                @error('section')
                    <div style="color:#dc3545; font-size:.85rem; margin-top:.25rem;">{{ $message }}</div>
                @enderror
            </div>

            <button type="submit" class="btn">Save and continue</button>
        </form>
    </div>

    <script>
        // Show only the courses belonging to the selected college -- same
        // behaviour as the registration form.
        (function () {
            const college = document.getElementById('department');
            const course = document.getElementById('course');
            const placeholder = course.querySelector('option[value=""]');
            const groups = course.querySelectorAll('optgroup');

            function syncCourses(clearSelection) {
                const picked = college.value;
                groups.forEach(function (group) {
                    const matches = picked !== '' && group.dataset.college === picked;
                    group.hidden = !matches;
                    group.disabled = !matches;
                });
                placeholder.textContent = picked === '' ? 'Select your college first' : 'Select course';
                if (clearSelection || (course.selectedOptions[0] && course.selectedOptions[0].parentElement.disabled)) {
                    course.value = '';
                }
            }

            college.addEventListener('change', function () { syncCourses(true); });
            syncCourses(false);
        })();
    </script>
</body>
</html>
