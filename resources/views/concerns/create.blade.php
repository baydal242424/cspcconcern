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
                {{-- Looped from Concern::CATEGORIES rather than written out,
                     so the form cannot offer a category the server rejects or
                     miss one that was added. The VALUE is the stored contract
                     that routing matches on; the label is what a student
                     reads, and the two differ where CATEGORY_LABELS says so. --}}
                @foreach (\App\Models\Concern::CATEGORIES as $category)
                    <option value="{{ $category }}" {{ old('category') === $category ? 'selected' : '' }}>
                        {{ \App\Models\Concern::categoryLabel($category) }}
                    </option>
                @endforeach
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

        {{-- Three ways to name the people a concern is about, and they combine:
             an instructor, the class adviser and a dean can all be named on
             one concern. They were mutually exclusive while about_staff_id
             held a single id, so a complaint about two people could only name
             one -- and the other stayed eligible to receive it, read it, and
             resolve a complaint about themselves.

             Each control is disabled while its row is closed, so the browser
             never submits it, and nothing is submitted at all with JavaScript
             off. --}}
        {{-- Hidden until a category is chosen, and only for the categories
             where naming somebody makes sense. A counselling or safeguarding
             case is not filed against a colleague from a picker -- Mental
             Health, Personal, Bullying, Harassment, Physical and Safety go to
             the Guidance Office or the adviser on their own -- and offering
             the list there invited a student to name a person the office
             would then be walled off from handling. --}}
        @php $namedSubjects = collect(old('about_staff_id', []))->map(fn ($id) => (int) $id); @endphp
        <div class="form-group" id="about-person-group" style="display:none;">
            <label>
                <input type="checkbox" id="about_instructor_toggle" class="about-toggle" data-target="about_instructor_wrap" data-select="about_instructor_id">
                <span style="font-weight: normal; margin-left: 0.5rem;">This concern is about a specific instructor</span>
            </label>
            <div id="about_instructor_wrap" style="display:none; margin-top:0.6rem;">
                <label style="font-size:0.9rem;">Which instructor is this concern about? You can pick more than one.</label>
                {{-- Checkboxes, not a multi-select. Choosing several from a
                     <select multiple> means Ctrl-clicking, and a phone has no
                     Ctrl key -- most students file from a phone, so the
                     multi-select made the second name unreachable for exactly
                     the people most likely to need it. A checkbox is one tap
                     on every device. --}}
                <input type="search" class="people-filter" data-list="about_instructor_id" placeholder="Type a name to narrow the list" aria-label="Search instructors" style="width:100%; margin:0.4rem 0;">
                {{-- Opens on the student's own college and folds the other
                     five away. Every college at once is 368 names, and the
                     order was whatever the name sort produced -- a Computer
                     Studies student scrolled past 170 Health Sciences
                     instructors to reach their own. Searching still looks
                     everywhere, since general-education subjects are taught
                     across colleges. --}}
                <div id="about_instructor_id" class="people-picker" data-name="about_staff_id[]">
                    @foreach ($instructorsByCollege as $college => $members)
                        <p class="people-group" data-own="{{ $college === $ownCollege ? '1' : '0' }}">{{ $college }}@if ($college === $ownCollege) <span style="font-weight:400; color:#64748b;">· your college</span>@endif</p>
                        @foreach ($members as $member)
                            <label class="person" data-own="{{ $college === $ownCollege ? '1' : '0' }}">
                                <input type="checkbox" name="about_staff_id[]" value="{{ $member->id }}" {{ $namedSubjects->contains($member->id) ? 'checked' : '' }} disabled>
                                <span>{{ $member->name }}</span>
                            </label>
                        @endforeach
                    @endforeach
                </div>
                @php $elsewhere = $instructorsByCollege->reject(fn ($m, $c) => $c === $ownCollege)->flatten()->count(); @endphp
                @if ($elsewhere)
                    <button type="button" class="show-all-people" data-list="about_instructor_id" data-count="{{ $elsewhere }}" style="margin-top:.4rem;">Show instructors from other colleges ({{ $elsewhere }})</button>
                @endif
                <p style="font-size: 0.82rem; color: #666; margin-top: 0.4rem;">To avoid a conflict of interest, this concern will <strong>not</strong> be assigned to anyone named here. It will be routed to a higher authority instead.</p>
            </div>

            {{-- The class adviser is not named here any more. "This concern is
                 about my class adviser" and "I do not want this to go to my
                 class adviser" read as one question asked twice, one box above
                 the other -- and every student who reports their adviser wants
                 it routed away from them anyway. They are a single control
                 further down the form now, the narrower case nested inside the
                 broader one. --}}
            @if ($adviserUnknown)
                {{-- Say why the row is absent. It used to just not be there,
                     which reads as a fault in the form -- a student comparing
                     theirs with a classmate's could not tell whether they had
                     filled something in wrongly or their college simply had
                     not published that section's adviser. Most sections are in
                     this state today. --}}
                <p style="font-size:0.82rem; color:#666; margin-top:0.7rem;">
                    No class adviser is recorded for {{ auth()->user()->course }} section {{ auth()->user()->section }} yet, so this form cannot offer them by name. If your concern is about your adviser, find them under <strong>a specific instructor</strong> above.
                </p>
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
                <label style="font-size:0.9rem;">Who is this concern about? You can pick more than one.</label>
                <input type="search" class="people-filter" data-list="about_staff_id" placeholder="Type a name to narrow the list" aria-label="Search staff" style="width:100%; margin:0.4rem 0;">
                <div id="about_staff_id" class="people-picker" data-name="about_staff_id[]">
                    {{-- Grouped by office: "Faculty/Staff" alone named no
                         department, and one person appears twice under two
                         accounts for the two offices they head. --}}
                    @foreach ($otherStaffByOffice as $office => $members)
                        <p class="people-group">{{ $office }}</p>
                        @foreach ($members as $member)
                            <label class="person">
                                <input type="checkbox" name="about_staff_id[]" value="{{ $member->id }}" {{ $namedSubjects->contains($member->id) ? 'checked' : '' }} disabled>
                                {{-- The programme for a chair, who heads exactly one:
                                     "Program Chair" alone does not say which of the
                                     four in a college the student means. --}}
                                {{-- Role and programme, minus anything the name already says.
                                     An office account is named for its post -- "BS Civil
                                     Engineering Program Chair" -- so spelling it out again gave
                                     "BS Civil Engineering Program Chair — Program Chair, BS Civil
                                     Engineering", and five such lines read as one programme
                                     repeated rather than five different ones.

                                     A chair with no programme at all still says so: with four in
                                     one college, "which one" is the question the line has to
                                     answer. --}}
                                @php
                                    $memberRole = optional($member->role)->name;
                                    $bits = [];

                                    if ($memberRole && ! Str::contains($member->name, $memberRole)) {
                                        $bits[] = $memberRole;
                                    }

                                    if ($member->course && ! Str::contains($member->name, $member->course)) {
                                        $bits[] = $member->course;
                                    } elseif (! $member->course && $memberRole === 'Program Chair') {
                                        $bits[] = 'programme not recorded';
                                    }
                                @endphp
                                <span>{{ $member->name }}{{ $bits ? ' — '.implode(', ', $bits) : '' }}</span>
                            </label>
                        @endforeach
                    @endforeach
                </div>
                <p style="font-size: 0.82rem; color: #666; margin-top: 0.4rem;">To avoid a conflict of interest, this concern will <strong>not</strong> be assigned to anyone named here. It will be routed to a higher authority instead.</p>
            </div>

            @error('about_staff_id')
                <div style="color: #dc3545; font-size: 0.85rem; margin-top: 0.25rem;">{{ $message }}</div>
            @enderror
            @error('about_staff_id.*')
                <div style="color: #dc3545; font-size: 0.85rem; margin-top: 0.25rem;">{{ $message }}</div>
            @enderror
        </div>

        {{-- Not an accusation, a preference. Academic, Physical, Safety and
             Others reach the class adviser first, which is right until the
             student does not want that particular person reading it -- and
             the only way to say so was to report them, which is a far bigger
             thing to write down than "send this to somebody else".

             Hidden for the categories that never reach an adviser (Facilities
             goes to General Services), and the server clears the flag for
             those too, so a stale tick cannot travel with the form. --}}
        <div class="form-group" id="skip-adviser-group">
            <label style="display:block;">
                {{-- Ticked on a failed submit when the adviser is still named
                     below: the nested box cannot be the only thing holding that
                     answer, or the form would reopen showing an accusation with
                     nothing on screen saying where it routes. --}}
                <input type="checkbox" name="skip_adviser" id="skip_adviser" value="1" {{ old('skip_adviser') || ($adviser && $namedSubjects->contains($adviser->id)) ? 'checked' : '' }}>
                <span style="font-weight: normal; margin-left: 0.5rem;">I do not want this to go to my class adviser</span>
            </label>
            <p style="font-size: 0.82rem; color: #666; margin-top: 0.4rem; padding-left: 1.6rem;">
                It goes to the <strong>Program Chair</strong> of your program instead &mdash; and to the <strong>Dean</strong> if your concern is about a Program Chair. You do not have to say why.
            </p>

            @if ($adviser)
                {{-- Reporting the adviser is the narrower case, so it sits
                     inside the broader one. Ticking it also records them as a
                     subject, which is the part routing alone does not do: it
                     walls them off from reading the concern and stops anybody
                     referring it back to them. --}}
                <div id="adviser-subject-wrap" style="display:none; margin-top:0.7rem; padding-left:1.6rem;">
                    <p style="margin:0; font-weight:600;">{{ $adviser->name }}</p>
                    {{-- The student's section, not the adviser's own column,
                         which is a student field and empty on staff. --}}
                    <p style="margin:0.1rem 0 0; font-size:0.85rem; color:#555;">{{ $adviser->department }}@if (auth()->user()->section) · your adviser for section {{ auth()->user()->section }}@endif</p>

                    <label style="display:block; margin-top:0.55rem;">
                        <input type="checkbox" id="about_adviser_toggle" class="about-toggle" data-target="about_adviser_wrap" data-select="about_adviser_id">
                        <span style="font-weight: normal; margin-left: 0.5rem;">This concern is about them</span>
                    </label>
                    <div id="about_adviser_wrap" style="display:none; padding-left:1.6rem;">
                        {{-- No list to choose from: one adviser, held in a hidden
                             field the shared toggle script fills from data-value
                             while this row is open. --}}
                        <input type="hidden" name="about_staff_id[]" id="about_adviser_id" data-value="{{ $adviser->id }}" value="{{ $namedSubjects->contains($adviser->id) ? $adviser->id : '' }}" disabled>
                        <p style="font-size: 0.82rem; color: #666; margin-top: 0.4rem;">Recorded as a concern about {{ $adviser->name }}. They will not be able to read it, and nobody can refer it back to them.</p>
                    </div>
                </div>
            @endif
        </div>

        <script>
            // The nested row follows the box it lives in, and unticks itself on
            // the way closed -- a student who changed their mind about the
            // routing would otherwise still have reported their adviser, with
            // the row that said so hidden from them.
            (function () {
                const skip = document.getElementById('skip_adviser');
                const wrap = document.getElementById('adviser-subject-wrap');
                const about = document.getElementById('about_adviser_toggle');
                if (!skip || !wrap) return;

                function sync() {
                    wrap.style.display = skip.checked ? 'block' : 'none';

                    if (!skip.checked && about && about.checked) {
                        about.checked = false;
                        // Handed to the shared row script, which is what
                        // disables the hidden field so it is not submitted.
                        about.dispatchEvent(new Event('change'));
                    }
                }

                skip.addEventListener('change', sync);
                // Changing category can untick the box from updateHelpers(),
                // and a property set by script fires no event of its own.
                const category = document.getElementById('category');
                if (category) category.addEventListener('change', sync);
                sync();
            })();
        </script>

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
            // The three "this concern is about..." rows. They are independent,
            // not alternatives: a concern can name an instructor, the class
            // adviser and a dean at once, and all of them post into the same
            // about_staff_id[] list.
            //
            // They used to untick each other, because the column held one id.
            // That quietly capped a complaint at one subject, and everybody
            // the student could not name stayed eligible to receive it.
            //
            // A closed row is disabled so the browser leaves it out of the
            // submission entirely.
            (function () {
                const toggles = Array.from(document.querySelectorAll('.about-toggle'));

                // A row is either a list of checkboxes (instructors, staff) or
                // a single hidden field (the class adviser, who is named
                // rather than chosen).
                function boxesIn(field) {
                    return Array.from(field.querySelectorAll('input[type="checkbox"]'));
                }

                function apply() {
                    toggles.forEach(function (toggle) {
                        const wrap = document.getElementById(toggle.dataset.target);
                        const field = document.getElementById(toggle.dataset.select);
                        const boxes = boxesIn(field);

                        wrap.style.display = toggle.checked ? 'block' : 'none';

                        if (boxes.length) {
                            boxes.forEach(function (box) {
                                box.disabled = !toggle.checked;
                                // Clear on close, so reopening the row does not
                                // silently re-add somebody the student had
                                // unticked.
                                if (!toggle.checked) box.checked = false;
                            });
                            return;
                        }

                        field.disabled = !toggle.checked;

                        if (!toggle.checked) {
                            field.value = '';
                        } else if (field.dataset.value) {
                            // The adviser's id is carried only while the row is
                            // open. If the field held it at all times, the
                            // restore below would tick the box on every load.
                            field.value = field.dataset.value;
                        }
                    });
                }

                toggles.forEach(function (toggle) {
                    toggle.addEventListener('change', apply);
                });

                // Reopen every row that had somebody in it after a failed
                // submit repopulates old(). More than one may.
                toggles.forEach(function (toggle) {
                    const field = document.getElementById(toggle.dataset.select);
                    const boxes = boxesIn(field);
                    const chosen = boxes.length
                        ? boxes.some(function (box) { return box.checked; })
                        : field.value !== '';

                    if (chosen) toggle.checked = true;
                });
                apply();
            })();

            // Narrowing a list of several hundred names. Hides rows that do
            // not match, and any college heading left with nothing under it.
            // A ticked person is never hidden -- a name that disappears while
            // still submitted is how somebody gets reported without the
            // student realising they had left them ticked.
            (function () {
                Array.from(document.querySelectorAll('.people-picker')).forEach(function (list) {
                    const search = document.querySelector('.people-filter[data-list="' + list.id + '"]');
                    const expand = document.querySelector('.show-all-people[data-list="' + list.id + '"]');

                    // A list with no own/other split (the staff picker) shows
                    // everything from the start.
                    let showAll = !list.querySelector('[data-own="0"]');

                    function render() {
                        const term = search ? search.value.trim().toLowerCase() : '';

                        Array.from(list.children).forEach(function (row) {
                            if (row.classList.contains('people-group')) return;

                            const box = row.querySelector('input[type="checkbox"]');
                            const own = row.dataset.own !== '0';

                            // Searching looks everywhere, folded colleges
                            // included -- a name half-remembered is exactly
                            // when the student cannot say which college it is
                            // in, and general-education subjects are taught
                            // across colleges anyway.
                            const show = term
                                ? row.textContent.toLowerCase().includes(term)
                                : (showAll || own);

                            // A ticked person is never hidden: a name that
                            // disappears while still submitted is how somebody
                            // gets reported without the student realising.
                            row.hidden = !(show || (box && box.checked));
                        });

                        // A heading survives only if something under it did.
                        let heading = null;
                        Array.from(list.children).forEach(function (row) {
                            if (row.classList.contains('people-group')) {
                                heading = row;
                                heading.hidden = true;
                            } else if (heading && !row.hidden) {
                                heading.hidden = false;
                            }
                        });

                        if (expand) expand.hidden = showAll || term !== '';
                    }

                    if (search) search.addEventListener('input', render);

                    if (expand) {
                        expand.addEventListener('click', function () {
                            showAll = true;
                            render();
                        });
                    }

                    render();
                });
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

                    // The bypass only exists where there is an adviser tier to
                    // bypass. Unticked on the way out, so switching to
                    // Facilities cannot submit a preference that no longer
                    // applies -- the server clears it as well, but a box
                    // ticked out of sight is its own bug report.
                    // Naming a person: for the categories that can be about
                    // one. The adviser bypass below follows its own rule --
                    // Physical and Safety still reach the class adviser, so
                    // the student can still ask for somebody else.
                    const NAMEABLE = ['Academic', 'Administrative', 'Facilities', 'Equipment', 'Others'];
                    const aboutGroup = document.getElementById('about-person-group');

                    if (aboutGroup) {
                        const canName = NAMEABLE.indexOf(cat) !== -1;
                        aboutGroup.style.display = canName ? 'block' : 'none';

                        // Closing it takes the names with it. A student who
                        // picked an instructor and then switched to Mental
                        // Health would otherwise still be reporting them, from
                        // a row no longer on screen.
                        if (!canName) {
                            Array.prototype.forEach.call(
                                aboutGroup.querySelectorAll('.about-toggle'),
                                function (toggle) {
                                    if (!toggle.checked) return;
                                    toggle.checked = false;
                                    toggle.dispatchEvent(new Event('change'));
                                }
                            );
                        }
                    }

                    const skipGroup = document.getElementById('skip-adviser-group');
                    if (skipGroup) {
                        const adviserCategory = !cat || target === 'your class adviser';
                        skipGroup.style.display = adviserCategory ? 'block' : 'none';
                        if (!adviserCategory) {
                            document.getElementById('skip_adviser').checked = false;
                        }
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