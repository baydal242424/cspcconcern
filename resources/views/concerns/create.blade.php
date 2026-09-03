@extends('layout')

@section('title', 'Submit a Concern')

@section('content')
<div class="card">
    <h1>Submit a Student Concern</h1>
    <p style="color: #666; margin-top: 0.5rem; margin-bottom: 2rem;">Help us address your concern. Your input is valuable and will be handled confidentially.</p>

    <form action="{{ route('concerns.store') }}" method="POST" enctype="multipart/form-data">
        @csrf

        <div class="form-group">
            <label for="category">Concern Category *</label>
            <select name="category" id="category" required>
                <option value="">-- Select a category --</option>
                <option value="Academic">Academic</option>
                <option value="Mental Health">Mental Health</option>
                <option value="Personal">Personal</option>
                <option value="Bullying">Bullying</option>
                <option value="Harassment">Harassment</option>
                <option value="Administrative">Administrative</option>
                <option value="Facilities">Facilities</option>
                <option value="Equipment">Equipment</option>
                <option value="Physical">Physical</option>
                <option value="Safety">Safety</option>
                <option value="Others">Others</option>
            </select>
            @error('category')
                <div style="color: #dc3545; font-size: 0.85rem; margin-top: 0.25rem;">{{ $message }}</div>
            @enderror
        </div>

        {{-- "Others" is the one category that does not say what it is. Without
             this the handler opens a concern labelled Others and has to read
             the whole description before knowing what kind of thing it is --
             and the dashboard counts every unlike thing as one bucket. --}}
        <div class="form-group" id="other-category-group"
             @unless (old('category') === 'Others') style="display:none;" @endunless>
            <label for="other_category">What is this about? *</label>
            <input type="text" name="other_category" id="other_category" maxlength="120"
                   value="{{ old('other_category') }}"
                   placeholder="A few words, e.g. lost locker key, lost ID at the gym">
            <div style="color:var(--muted); font-size:0.82rem; margin-top:0.25rem;">
                A short label so the right person can pick this up. Describe the full
                situation in the box below.
            </div>
            @error('other_category')
                <div style="color: #dc3545; font-size: 0.85rem; margin-top: 0.25rem;">{{ $message }}</div>
            @enderror
        </div>

        {{-- The department is no longer asked for: it is the reporter's own
             college, already on their account from registration. --}}
        <div class="form-group">
            @if (Auth::user()->department)
                <p style="font-size:0.85rem; color:#60708a; margin:0;">
                    Filed under <strong>{{ Auth::user()->department }}</strong>.
                </p>
            @endif
            <p id="department-helper" style="font-size: 0.9rem; color: #60708a; font-style: italic; margin-top: 0.5rem; display:none;"></p>
            <div id="confidentiality-box" style="display:none; background:#fff3cd; border:1px solid #ffe69c; color:#856404; padding:0.9rem; border-radius:6px; margin-top:1rem; font-size:0.9rem;">
                Your concern will be handled confidentially.
            </div>
        </div>

        <div class="form-group">
            <p style="font-size: 0.85rem; color: #60708a; font-style: italic;">
                The severity of your concern will be assessed by the assigned staff after submission.
            </p>
        </div>

        <div class="form-group">
            <label for="description">Detailed Description *</label>
            <p style="font-size:0.82rem; color:#64748b; margin:0.15rem 0 0.5rem;">
                Please describe <strong>what happened</strong>, <strong>when</strong> and <strong>where</strong> it happened, and <strong>who was involved</strong>. The more specific you are, the better the assigned staff can help.
            </p>
            <details style="margin:0 0 0.7rem; border:1px solid #e2e8f0; border-radius:10px; background:#f8fafc;">
                <summary style="cursor:pointer; padding:0.6rem 0.9rem; font-size:0.85rem; font-weight:600; color:#2f5bea; list-style:none;">
                    💡 Not sure what to write? Tips for a good report
                </summary>
                <div style="padding:0 0.9rem 0.9rem; font-size:0.85rem; color:#475569; line-height:1.55;">
                    <p style="margin-bottom:0.5rem;">You don't have to answer all of these &mdash; just include what you can, in your own words:</p>
                    <ul style="margin:0 0 0 1.1rem; padding:0;">
                        <li><strong>What happened?</strong> Describe the situation.</li>
                        <li><strong>When?</strong> The date and time, if you remember.</li>
                        <li><strong>Where?</strong> The room, building, or online platform.</li>
                        <li><strong>Who was involved?</strong> Names or descriptions (only if you're comfortable).</li>
                        <li><strong>How did it affect you?</strong> Optional, but it helps staff understand the urgency.</li>
                        <li><strong>What would help?</strong> The outcome you're hoping for.</li>
                    </ul>
                    <p style="margin-top:0.5rem; color:#64748b;">Take your time. If a concern is sensitive, share only what you feel safe sharing. Your report is submitted under your name and is visible only to the staff member handling it.</p>
                </div>
            </details>
            <textarea name="description" id="description" rows="6"
                minlength="20" maxlength="2000"
                placeholder="Example: On March 3rd during our 9:00 AM class in Room 204, ... (describe the situation, dates, and people involved)."
                style="overflow-wrap:anywhere;" required>{{ old('description') }}</textarea>
            <div style="display:flex; justify-content:space-between; margin-top:0.5rem; font-size:0.85rem;">
                <span id="char-hint" style="color:#dc3545;">Please write at least 20 characters.</span>
                <span id="char-count" style="color:#60708a;">0 / 2000</span>
            </div>
            @error('description')
                <div style="color: #dc3545; font-size: 0.85rem; margin-top: 0.25rem;">{{ $message }}</div>
            @enderror
        </div>

        {{-- Two ways to name the person a concern is about. Both write to the
             same about_staff_id field, and only one can be active at a time:
             the inactive select is disabled, so the browser never submits it
             (and nothing is submitted at all when JavaScript is off). --}}
        <div class="form-group">
            <label>
                <input type="checkbox" id="about_instructor_toggle" class="about-toggle" data-target="about_instructor_wrap" data-select="about_instructor_id">
                <span style="font-weight: normal; margin-left: 0.5rem;">This concern is about a specific instructor</span>
            </label>
            <div id="about_instructor_wrap" style="display:none; margin-top:0.6rem;">
                <label for="about_instructor_id" style="font-size:0.9rem;">Which instructor is this concern about?</label>
                <select name="about_staff_id" id="about_instructor_id" disabled>
                    <option value="">-- Select the instructor --</option>
                    @foreach ($instructorsByCollege as $college => $members)
                        <optgroup label="{{ $college }}">
                            @foreach ($members as $member)
                                <option value="{{ $member->id }}" {{ old('about_staff_id') == $member->id ? 'selected' : '' }}>{{ $member->name }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
                <p style="font-size: 0.82rem; color: #666; margin-top: 0.4rem;">To avoid a conflict of interest, this concern will <strong>not</strong> be assigned to the person named here. It will be routed to a higher authority instead.</p>
            </div>

            {{-- The class adviser gets a row of their own, naming them.
                 Advising is not a role, so the adviser is whoever holds the
                 section: 14 of the 105 assignments belong to a Program Chair,
                 a Dean or Faculty/Staff, who appear in no picker built from
                 the Instructor role. Those students could not name their own
                 adviser at all.

                 It matters most here of anywhere on this form. Academic,
                 Physical, Safety and Others route to the class adviser FIRST,
                 so a concern about the adviser that fails to name them is
                 handed straight to the person it is about. routeConcern()
                 steps past an adviser who is the named subject -- but only if
                 the student could name them.

                 Named rather than listed because there is exactly one, and
                 because plenty of students do not know who theirs is. A
                 dropdown would ask them to recognise a name; this tells them
                 one. --}}
            @if ($adviser)
                <label style="display:block; margin-top:0.7rem;">
                    <input type="checkbox" id="about_adviser_toggle" class="about-toggle" data-target="about_adviser_wrap" data-select="about_adviser_id">
                    <span style="font-weight: normal; margin-left: 0.5rem;">This concern is about my class adviser</span>
                </label>
                <div id="about_adviser_wrap" style="display:none; margin-top:0.6rem; padding-left:1.6rem;">
                    {{-- No list to choose from: one adviser, held in a hidden
                         field that the shared toggle script fills in from
                         data-value when this row is the active one. --}}
                    <input type="hidden" name="about_staff_id" id="about_adviser_id" data-value="{{ $adviser->id }}" value="{{ old('about_staff_id') == $adviser->id ? $adviser->id : '' }}" disabled>
                    <p style="margin:0; font-weight:600;">{{ $adviser->name }}</p>
                    {{-- The student's section, not the adviser's own column,
                         which is a student field and empty on staff. --}}
                    <p style="margin:0.1rem 0 0; font-size:0.85rem; color:#555;">{{ $adviser->department }}@if (auth()->user()->section) · your adviser for section {{ auth()->user()->section }}@endif</p>
                    <p style="font-size: 0.82rem; color: #666; margin-top: 0.4rem;">To avoid a conflict of interest, this concern will <strong>not</strong> be assigned to the person named here. It will be routed to a higher authority instead.</p>
                </div>
            @endif

            <label style="display:block; margin-top:0.7rem;">
                <input type="checkbox" id="about_staff_toggle" class="about-toggle" data-target="about_staff_wrap" data-select="about_staff_id">
                {{-- "an office or administrator" undersold this by a long way:
                     the list holds deans, program chairs, counselors, Gender
                     and Development, General Services and the VPAA. A student
                     with a concern about their dean had no reason to open a
                     box that did not mention deans. --}}
                <span style="font-weight: normal; margin-left: 0.5rem;">This concern is about someone else on staff — a dean, program chair, counselor, office or administrator</span>
            </label>
            <div id="about_staff_wrap" style="display:none; margin-top:0.6rem;">
                <label for="about_staff_id" style="font-size:0.9rem;">Who is this concern about?</label>
                <select name="about_staff_id" id="about_staff_id" disabled>
                    <option value="">-- Select the staff member --</option>
                    {{-- Grouped by office: "Faculty/Staff" alone named no
                         department, and one person appears twice under two
                         accounts for the two offices they head. --}}
                    @foreach ($otherStaffByOffice as $office => $members)
                        <optgroup label="{{ $office }}">
                            @foreach ($members as $member)
                                <option value="{{ $member->id }}" {{ old('about_staff_id') == $member->id ? 'selected' : '' }}>{{ $member->name }} — {{ optional($member->role)->name }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
                <p style="font-size: 0.82rem; color: #666; margin-top: 0.4rem;">To avoid a conflict of interest, this concern will <strong>not</strong> be assigned to the person named here. It will be routed to a higher authority instead.</p>
            </div>

            @error('about_staff_id')
                <div style="color: #dc3545; font-size: 0.85rem; margin-top: 0.25rem;">{{ $message }}</div>
            @enderror
        </div>

        <div class="form-group">
            <label for="attachments">Attach evidence <span style="font-weight:normal;color:#666;">(optional)</span></label>
            <input type="file" name="attachments[]" id="attachments" multiple accept=".jpg,.jpeg,.png,.pdf">
            <p style="font-size: 0.82rem; color: #666; margin-top: 0.4rem;">You may attach up to 5 files (JPG, PNG, or PDF), 5&nbsp;MB each. Evidence is optional — you can submit without it. Your files are stored privately and can only be viewed by the staff authorized to handle your concern.</p>
            @error('attachments')
                <div style="color: #dc3545; font-size: 0.85rem; margin-top: 0.25rem;">{{ $message }}</div>
            @enderror
            @foreach ($errors->get('attachments.*') as $fileErrors)
                @foreach ($fileErrors as $msg)
                    <div style="color: #dc3545; font-size: 0.85rem; margin-top: 0.25rem;">{{ $msg }}</div>
                @endforeach
            @endforeach
        </div>

        <div style="display: flex; gap: 1rem;">
            <button type="submit" class="btn btn-primary">Send ✈</button>
            <a href="{{ route('concerns.index') }}" class="btn btn-muted">Cancel</a>
        </div>

        <script>
            // "About a specific instructor" and "about an office or
            // administrator" are two views of the same about_staff_id field,
            // so ticking one unticks the other. The hidden select is disabled
            // so only the visible one is ever submitted.
            (function () {
                const toggles = Array.from(document.querySelectorAll('.about-toggle'));

                function apply(changed) {
                    toggles.forEach(function (toggle) {
                        if (changed && toggle !== changed && changed.checked) {
                            toggle.checked = false;
                        }

                        const wrap = document.getElementById(toggle.dataset.target);
                        const select = document.getElementById(toggle.dataset.select);

                        wrap.style.display = toggle.checked ? 'block' : 'none';
                        select.disabled = !toggle.checked;

                        if (!toggle.checked) {
                            select.value = '';
                        } else if (select.dataset.value) {
                            // The class adviser row: one fixed person, held in
                            // a hidden field rather than a dropdown. Its value
                            // is filled in only while its row is the active
                            // one -- if it carried the id at all times, the
                            // old() restore below would tick this box on every
                            // page load.
                            select.value = select.dataset.value;
                        }
                    });
                }

                toggles.forEach(function (toggle) {
                    toggle.addEventListener('change', function () { apply(this); });
                });

                // Re-open the right one after a failed submit repopulates old().
                toggles.forEach(function (toggle) {
                    const select = document.getElementById(toggle.dataset.select);
                    if (select.value) toggle.checked = true;
                });
                apply(null);
            })();

            (function() {
                const categoryEl = document.getElementById('category');
                const helperEl = document.getElementById('department-helper');
                const confidentialEl = document.getElementById('confidentiality-box');
                const descEl = document.getElementById('description');
                const charCountEl = document.getElementById('char-count');

                // Where each category is routed. This MUST mirror the server-side
                // routing in ConcernController::routeConcern(). Routing is decided
                // by category only; the department field is informational.
                //
                // These strings are a promise to the student about who is
                // going to read what they are about to write, so a stale one
                // is worse than none at all: the four adviser categories went
                // on saying "an instructor in your college" after routing had
                // already moved to the class adviser a tier above them.
                // Change routeConcern() and change this in the same commit.
                const routingByCategory = {
                    'Academic': 'your class adviser',
                    'Mental Health': 'the Guidance Office',
                    'Personal': 'the Guidance Office',
                    'Bullying': 'the Guidance Office',
                    'Harassment': 'the Guidance Office',
                    // Admin triage these and pass them to whichever office
                    // owns the request -- records, cashier, clearance. Saying
                    // "the Administration office" sets the right expectation:
                    // received here, answered elsewhere.
                    'Administrative': 'the Administration office',
                    'Facilities': 'the General Services Unit',
                    'Equipment': 'the General Services Unit',
                    'Physical': 'your class adviser',
                    'Safety': 'your class adviser',
                    'Others': 'your class adviser'
                };

                // One-line plain-English scope for each category. Without this
                // students guessed, and the same broken lab PC arrived as
                // "Administrative", "Academic" or "Others" depending on who
                // filed it -- three different handlers for one problem.
                const scopeByCategory = {
                    'Academic': 'Grades, subjects, class schedules, instructors, teaching concerns.',
                    'Mental Health': 'Stress, anxiety, low mood, or anything affecting how you are coping.',
                    'Personal': 'Family, money, housing, or another situation you need support with.',
                    'Bullying': 'Repeated behaviour aimed at you or someone else -- threats, intimidation, humiliation.',
                    'Harassment': 'Unwanted conduct or discrimination by anyone on campus, including a single incident.',
                    'Administrative': 'Enrollment, records, ID, clearance, fees, and other office processes.',
                    'Facilities': 'The building itself -- no water or electricity, aircon, lights, damaged rooms, blocked exits.',
                    'Equipment': 'Things inside it -- computers, lab equipment, chairs, internet.',
                    'Physical': 'An accident or injury that has already happened to you or someone else.',
                    'Safety': 'A hazard that has not caused harm yet -- a broken stair, exposed wiring, a blocked exit.',
                    'Others': 'Anything that does not fit the categories above.'
                };

                function updateHelpers() {
                    const cat = categoryEl.value;
                    const target = routingByCategory[cat] || null;

                    if (target) {
                        // Scope first, then destination -- the student needs to
                        // confirm they picked the right category before the
                        // routing note means anything to them.
                        helperEl.textContent = scopeByCategory[cat]
                            + ' This will be routed to ' + target + '.';
                        helperEl.style.display = 'block';
                    } else {
                        helperEl.style.display = 'none';
                    }

                    // "Others" has to explain itself before anything else can.
                    const otherGroup = document.getElementById('other-category-group');
                    const otherInput = document.getElementById('other_category');
                    if (otherGroup) {
                        const isOther = (cat === 'Others');
                        otherGroup.style.display = isOther ? 'block' : 'none';
                        // Cleared on the way out, so switching away from Others
                        // cannot submit a label belonging to a category that no
                        // longer applies.
                        if (!isOther && otherInput) { otherInput.value = ''; }
                    }

                    // Show the confidentiality note only for sensitive categories.
                    const sensitive = ['Mental Health', 'Personal', 'Bullying', 'Harassment'].includes(cat);
                    confidentialEl.style.display = sensitive ? 'block' : 'none';
                }

                categoryEl.addEventListener('change', updateHelpers);
                updateHelpers();

                function updateCharCount() {
                    const len = (descEl.value || '').trim().length;
                    charCountEl.textContent = len + ' / 2000';
                    const hintEl = document.getElementById('char-hint');
                    if (hintEl) {
                        if (len === 0) {
                            hintEl.textContent = 'Please write at least 20 characters.';
                            hintEl.style.color = '#94a3b8';
                        } else if (len < 20) {
                            hintEl.textContent = (20 - len) + ' more character' + ((20 - len) === 1 ? '' : 's') + ' needed.';
                            hintEl.style.color = '#dc3545';
                        } else {
                            hintEl.textContent = '✓ Looks good.';
                            hintEl.style.color = '#16a34a';
                        }
                    }
                }
                if (descEl) {
                    descEl.addEventListener('input', updateCharCount);
                    updateCharCount();
                }
            })();
        </script>
    </form>
</div>
@endsection