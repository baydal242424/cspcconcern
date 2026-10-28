@extends('layout')

@section('title', 'Submit a Concern')

@section('content')
<div class="card">
    <h1>Submit a Student Concern</h1>
    {{-- "Your input is valuable" told the student nothing they could use,
         and the confidentiality promise is made again further down, next to
         the categories it actually applies to. --}}
    <p style="color: #666; margin-top: 0.5rem; margin-bottom: 2rem;">Handled confidentially.</p>

    {{-- Picked up where they left off. Said plainly, because the form
         filling itself in is otherwise unexplained -- and because the
         draft may be weeks old and no longer what they want to send.

         Outside the form below: discarding is its own POST, and a form
         cannot be nested inside another. --}}
    @if (! empty($resumedDraft))
        <div style="background:var(--info-bg,#e2ecff); border:1px solid #bcd2ff; color:var(--info-ink,#1d4ed8); padding:0.9rem 1.1rem; border-radius:10px; margin-bottom:1.5rem; display:flex; gap:1rem; align-items:center; flex-wrap:wrap;">
            <span style="flex:1; min-width:14rem; font-size:0.9rem;">
                Carried on from the draft you saved
                {{ $resumedDraft->updated_at->diffForHumans() }}.
            </span>
            <form action="{{ route('concerns.draft.discard', $resumedDraft) }}" method="POST" style="margin:0;">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-muted" style="font-size:0.85rem;"
                    onclick="return confirm('Delete this draft? It has not been sent to anybody, and this cannot be undone.');">Delete draft</button>
            </form>
        </div>
    @endif

@php
    // A repeat is the first concern happening again: same category,
    // same people, routed the same way. Everything that decides WHERE
    // it lands is fixed; what is left to write is what has happened
    // since, and whatever evidence there is for it.
    //
    // The sub-category locks only when the first concern has one --
    // older concerns predate it being required, and locking an empty
    // field would leave a required question nobody could answer.
    $repeat = ! empty($followsUpOn);
    $lockSub = $repeat && filled($followsUpOn->subcategory)
        && \App\Models\Concern::subcategoryBelongsTo($followsUpOn->category, $followsUpOn->subcategory);
@endphp

    <form action="{{ route('concerns.store') }}" method="POST" enctype="multipart/form-data">
        @csrf
        {{-- Which draft this was opened from, so Save updates that
             one instead of starting another, and filing clears that
             one instead of the whole folder. --}}
        @if (! empty($resumedDraft))
            <input type="hidden" name="draft_id" value="{{ $resumedDraft->id }}">
        @endif

        @if (!empty($followsUpOn))
            {{-- Re-checked in store(): a hidden field is a suggestion, and
                 the wrong parent would show a handler somebody else's
                 history. --}}
            <input type="hidden" name="follows_up_on_id" value="{{ $followsUpOn->id }}">

            <div class="follows-up">
                <strong>This has happened again.</strong>
                <p>
                    You are reporting concern
                    <a href="{{ route('concerns.show', $followsUpOn) }}">#{{ $followsUpOn->id }}</a>
                    again &mdash; {{ $followsUpOn->category_label ?? $followsUpOn->category }}, settled on
                    {{ optional($followsUpOn->resolved_at ?: $followsUpOn->updated_at)->local()->format('M d, Y') }}.
                    Describe what has happened <em>since</em>. The staff who pick this up can
                    see the first report and what came of it, so you do not need to repeat it.
                </p>
            </div>
        @endif

        <div class="form-group">
            <label for="category">Concern Category *</label>
            {{-- Fixed on a repeat: it is the category of the thing
                 being reported again, and the banner above has just
                 said so. Offering it as a question invites an answer
                 that contradicts the sentence above it.

                 disabled, not readonly -- readonly does nothing to a
                 <select> -- and a disabled control submits nothing,
                 so the hidden field below carries the value. The
                 server takes it from the first concern either way;
                 this is only what the student sees. --}}
            <select name="category" id="category" required @disabled($repeat)>
                <option value="">-- Select a category --</option>
                {{-- Looped from Concern::CATEGORIES rather than written out,
                     so the form cannot offer a category the server rejects or
                     miss one that was added. The VALUE is the stored contract
                     that routing matches on; the label is what a student
                     reads, and the two differ where CATEGORY_LABELS says so. --}}
                @foreach (\App\Models\Concern::CATEGORIES as $category)
                    {{-- The plain label. It used to carry a clarifier after a
                         dash -- "Academic — grades, subjects, schedules,
                         teaching" -- because the category alone did not say
                         what it covered. The sub-category dropdown below says
                         exactly that now, item by item, so the dash was the
                         same list twice and made every row long enough to
                         wrap on a phone.

                         The one-line explanation under the select stays: it
                         is about the ONE category chosen, not all eleven at
                         once. --}}
                    <option value="{{ $category }}" {{ old('category') === $category ? 'selected' : '' }}>
                        {{ \App\Models\Concern::categoryLabel($category) }}
                    </option>
                @endforeach
            </select>
            @if ($repeat)
                <input type="hidden" name="category" value="{{ $followsUpOn->category }}">
                <p style="font-size:.8rem; color:var(--muted,#64748b); margin-top:.3rem;">
                    Same category as the concern this repeats.
                </p>
            @endif
            @error('category')
                <div style="color: #dc3545; font-size: 0.85rem; margin-top: 0.25rem;">{{ $message }}</div>
            @enderror
        </div>

        {{-- The second step: what kind of thing, within the office that will
             read it. Hidden until a category is chosen, because its options
             depend entirely on that choice -- the script below rebuilds them
             rather than hiding the ones that do not apply, since a hidden
             <option> is not honoured in every browser.

             Others is the one category with no list, and it has its own
             question below instead. --}}
        <div class="form-group" id="subcategory-group" style="display:none;">
            <label for="subcategory">What is it about? *</label>
            <select name="subcategory" id="subcategory"
                    data-old="{{ old('subcategory') }}" @disabled($lockSub)>
                <option value="">-- Select --</option>
            </select>
            @if ($lockSub)
                <input type="hidden" name="subcategory" value="{{ $followsUpOn->subcategory }}">
            @endif
            @error('subcategory')
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
            @error('other_category')
                <div style="color: #dc3545; font-size: 0.85rem; margin-top: 0.25rem;">{{ $message }}</div>
            @enderror
        </div>

        {{-- The department is never asked for and no longer shown either.
             It is the reporter's own college, already on their account
             from registration, and the server reads it from there -- see
             store(), where it is set from the account and never from
             input. Printing it back at them said nothing they did not
             know, on a form that had too much to read. --}}

        {{-- "The severity of your concern will be assessed by the assigned
             staff after submission" used to sit here. There is nothing the
             student can do with it: they are not asked for a severity, so
             the sentence answered a question the form never put to them. --}}

        <div class="form-group">
            <label for="description">Detailed Description *</label>
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
                    <p style="margin-top:0.5rem; color:#64748b;">Share only what you feel safe sharing. Only the staff member handling this can read it.</p>
                </div>
            </details>
            {{-- position:relative so the name dropdown below anchors here.
                 mention-field also anchors the mirror that paints the tags. --}}
            <div class="mention-field" style="position:relative;">
            {{-- The mirror. Holds a copy of the description with each chosen
                 name wrapped in a pill, sitting exactly behind the real text
                 so the pill lands under it. aria-hidden because it is the
                 same words twice and a screen reader should hear them once. --}}
            <div id="mention-backdrop" class="mention-backdrop" aria-hidden="true"></div>
            <textarea name="description" id="description" rows="6"
                minlength="20" maxlength="2000"
                placeholder="Example: On March 3rd during our 9:00 AM class in Room 204, ... (describe the situation, dates, and people involved)."
                style="overflow-wrap:anywhere;" required>{{ old('description') }}</textarea>

            {{-- Name completion inside the description.
                 Typing a few letters of somebody's name offers the full one,
                 so "Mr. Panizal" does not go in as "mr panizal" or, worse, as
                 a different Panizal.

                 The list is the SAME set the "about a particular person"
                 picker already offers, and those names are already rendered
                 on this page. An autocomplete over every account would turn
                 the filing form into a directory: type "a" and read back
                 eight hundred students, their programmes and their sections.

                 It inserts text and nothing else. Mentioning somebody in the
                 description is not the same as naming them as the subject of
                 the concern -- that is the checkbox below, and it carries
                 consequences this does not: the person named is walled out of
                 the case and it is routed over their head. --}}
            <div id="mention-box" class="mention-box" hidden role="listbox" aria-label="Matching names"></div>
            </div>

            <div style="display:flex; justify-content:space-between; margin-top:0.5rem; font-size:0.85rem;">
                <span id="char-hint" style="color:#dc3545;">Please write at least 20 characters.</span>
                {{-- Nothing else says the @ is there, and a feature nobody
                     finds is a feature nobody has. --}}
                <span style="color:#60708a;">Type <strong>&#64;</strong> to name a staff member.</span>
                <span id="char-count" style="color:#60708a;">0 / 2000</span>
            </div>
            @error('description')
                <div style="color: #dc3545; font-size: 0.85rem; margin-top: 0.25rem;">{{ $message }}</div>
            @enderror
        </div>

        {{-- Two ways to name the people a concern is about, and they combine:
             the class adviser and a dean can both be named on one concern.
             Instructors are no longer offered here at all -- the picker of
             every teacher has been removed -- so a concern about a teacher who
             is not the student's adviser is described in the text instead.

             The two that remain were mutually exclusive while about_staff_id
             held a single id, so a complaint about two people could only name
             one -- and the other stayed eligible to receive it, read it, and
             resolve a complaint about themselves.

             Each control is disabled while its row is closed, so the browser
             never submits it, and nothing is submitted at all with JavaScript
             off. --}}
        {{-- Hidden until a category is chosen, and only for the categories
             where naming somebody makes sense. A counselling or safeguarding
             case is not filed against a colleague from a picker -- Mental
             Health, Personal, Bullying and Harassment reach the Guidance
             Office on their own, and offering the list there invited a
             student to name a person the office would then be walled off
             from handling.

             Physical and Safety sit with Academic instead: all three reach
             the class adviser, so a student asking for somebody else climbs
             the same rungs -- the chair of their programme, their dean, then
             the VPAA. --}}
        @php $namedSubjects = collect(old('about_staff_id', []))->map(fn ($id) => (int) $id); @endphp
        <div class="form-group" id="about-person-group" style="display:none;">

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
                    No adviser is recorded for {{ auth()->user()->course }} {{ auth()->user()->section }}, so they cannot be named here. If this is about your adviser, tick the box below and it goes to your Program Chair.
                </p>
            @endif

            <label style="display:block; margin-top:0.7rem;">
                {{-- Ticked when somebody is already named. Two cases: a
                     repeat of a settled concern, which arrives with the
                     same people on it, and a submission that bounced --
                     where without this the names they had chosen came
                     back unticked, hidden behind a closed box, and the
                     student resubmitted without them. --}}
                <input type="checkbox" id="about_staff_toggle" class="about-toggle" data-target="about_staff_wrap" data-select="about_staff_id"
                    @checked(! empty(old('about_staff_id'))) @disabled($repeat)>
                {{-- Named for what the box does, not for who happens to be
                     behind it. It listed the roles once -- "a dean, program
                     chair, counselor, office or administrator" -- which was
                     already half wrong: the list now narrows to the people
                     with a part in the chosen category, so the counselor and
                     the administrator are not in it for an Academic concern,
                     and a label naming them promises something the list does
                     not hold. "Someone ELSE" was left over from the instructor
                     picker that used to sit above this one and is now gone. --}}
                <span style="font-weight: normal; margin-left: 0.5rem;">This concern is about a particular person</span>
            </label>
            @if ($repeat)
                {{-- The people, carried rather than picked. The
                     checkboxes below are switched on by the toggle,
                     which is fixed now, so nothing in that list
                     would submit. --}}
                @foreach ($followsUpOn->subjects as $subject)
                    <input type="hidden" name="about_staff_id[]" value="{{ $subject->id }}">
                @endforeach
            @endif
            <div id="about_staff_wrap" style="display:none; margin-top:0.6rem;">
                <label style="font-size:0.9rem;">Who is this concern about? You can pick more than one.</label>
                <input type="search" class="people-filter" data-list="about_staff_id" placeholder="Type a name to narrow the list" aria-label="Search staff" style="width:100%; margin:0.4rem 0;">
                <div id="about_staff_id" class="people-picker" data-name="about_staff_id[]">
                    {{-- Grouped by office: "Faculty/Staff" alone named no
                         department, and one person appears twice under two
                         accounts for the two offices they head. --}}
                    @foreach ($otherStaffByOffice as $office => $members)
                        <p class="people-group" data-office="{{ $office }}">{{ $office }}</p>
                        @foreach ($members as $member)
                            <label class="person" data-role="{{ optional($member->role)->name }}" data-group="{{ $office }}" data-office="{{ $member->department }}">
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

                                    // The office, now that the heading above is
                                    // their ROLE rather than their office. Without
                                    // it two Faculty/Staff read identically -- and
                                    // the real roster has one lawyer heading both
                                    // Human Rights Education and Legal Affairs
                                    // under two accounts, so two consecutive lines
                                    // were the same but for a role name.
                                    if ($member->department
                                        && ! Str::contains($member->name, $member->department)
                                        && ! in_array($member->department, $bits, true)) {
                                        $bits[] = $member->department;
                                    }
                                @endphp
                                <span>{{ $member->name }}{{ $bits ? ' — '.implode(', ', $bits) : '' }}</span>
                            </label>
                        @endforeach
                    @endforeach
                </div>
                <p style="font-size: 0.82rem; color: #666; margin-top: 0.4rem;">Nobody named here will be assigned to it. It goes over their head instead.</p>
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
        {{-- Hidden until a category is chosen, the same way the naming group
             is. Shown by default it flashed onto the page before the script
             could decide, and a control that appears and then vanishes reads
             as the form losing it. --}}
        <div class="form-group" id="skip-adviser-group" style="display:none;">
            <label style="display:block;">
                {{-- Ticked on a failed submit when the adviser is still named
                     below: the nested box cannot be the only thing holding that
                     answer, or the form would reopen showing an accusation with
                     nothing on screen saying where it routes. --}}
                <input type="checkbox" name="skip_adviser" id="skip_adviser" value="1"
                    {{ old('skip_adviser') || ($adviser && $namedSubjects->contains($adviser->id)) ? 'checked' : '' }}
                    @disabled($repeat)>
                @if ($repeat && $followsUpOn->skip_adviser)
                    <input type="hidden" name="skip_adviser" value="1">
                @endif
                <span style="font-weight: normal; margin-left: 0.5rem;">I do not want this to go to my class adviser</span>
            </label>
            <p style="font-size: 0.82rem; color: #666; margin-top: 0.4rem; padding-left: 1.6rem;">
                It goes to your <strong>Program Chair</strong> instead. You do not have to say why.
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

            {{-- A drop zone around the real input rather than a replacement for
                 it. The input still carries the files and still submits them;
                 everything below only decides what is in it.

                 It exists for two reasons. Dragging a photo or a clip straight
                 in is how people expect to attach something. And the native
                 control has no way to remove one file: picking again replaces
                 the whole set, so a student who chose nine by mistake could
                 only start over. --}}
            <div class="dropzone" id="dropzone">
                <input type="file" name="attachments[]" id="attachments" multiple
                       accept="{{ collect(config('concerns.attachment_extensions'))->map(fn ($e) => '.'.$e)->implode(',') }}"
                       class="dropzone-input">

                <p class="dropzone-lead">
                    Drag files here, or <button type="button" class="dropzone-browse" id="attachments-browse">choose files</button>
                </p>
                <p class="dropzone-sub">Pictures, video, sound recordings, documents or a ZIP</p>
            </div>

            <ul class="file-list" id="file-list" hidden></ul>
            <p class="file-note" id="file-note" hidden></p>

            <p style="font-size: 0.82rem; color: #666; margin-top: 0.4rem;">Optional. Up to {{ config('concerns.max_attachments') }} files, {{ config('concerns.max_attachment_mb') }}&nbsp;MB each. Only the staff handling this can open them.</p>
            @error('attachments')
                <div style="color: #dc3545; font-size: 0.85rem; margin-top: 0.25rem;">{{ $message }}</div>
            @enderror
            @foreach ($errors->get('attachments.*') as $fileErrors)
                @foreach ($fileErrors as $msg)
                    <div style="color: #dc3545; font-size: 0.85rem; margin-top: 0.25rem;">{{ $msg }}</div>
                @endforeach
            @endforeach
        </div>

        <script>
            // The chosen files, and the only writer of the input's FileList.
            //
            // A FileList cannot be edited, which is why the native control has
            // no remove button: the browser hands you a frozen list and the
            // only way to change it is to pick again. DataTransfer is the way
            // back -- build one, add the files to keep, and assign its list to
            // the input.
            (function () {
                var zone = document.getElementById('dropzone');
                var input = document.getElementById('attachments');
                var browse = document.getElementById('attachments-browse');
                var list = document.getElementById('file-list');
                var note = document.getElementById('file-note');

                if (!zone || !input || !list) return;

                var MAX_FILES = {{ (int) config('concerns.max_attachments') }};
                var MAX_BYTES = {{ (int) config('concerns.max_attachment_mb') }} * 1024 * 1024;

                var chosen = [];

                function readable(bytes) {
                    if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
                    if (bytes >= 1024) return Math.round(bytes / 1024) + ' KB';
                    return bytes + ' B';
                }

                // Same name AND same size: the browser gives no id, and a
                // student dropping the same photo twice means it once.
                function already(file) {
                    return chosen.some(function (f) {
                        return f.name === file.name && f.size === file.size;
                    });
                }

                function sync() {
                    var transfer = new DataTransfer();
                    chosen.forEach(function (f) { transfer.items.add(f); });
                    input.files = transfer.files;
                }

                function render(message) {
                    list.textContent = '';

                    chosen.forEach(function (file, index) {
                        var row = document.createElement('li');
                        row.className = 'file-row' + (file.size > MAX_BYTES ? ' is-too-big' : '');

                        var name = document.createElement('span');
                        name.className = 'file-name';
                        name.textContent = file.name;

                        var size = document.createElement('span');
                        size.className = 'file-size';
                        size.textContent = readable(file.size)
                            + (file.size > MAX_BYTES ? ' — too large' : '');

                        var remove = document.createElement('button');
                        remove.type = 'button';
                        remove.className = 'file-remove';
                        remove.setAttribute('aria-label', 'Remove ' + file.name);
                        remove.textContent = '×';
                        remove.addEventListener('click', function () {
                            chosen.splice(index, 1);
                            sync();
                            render('');
                        });

                        row.appendChild(name);
                        row.appendChild(size);
                        row.appendChild(remove);
                        list.appendChild(row);
                    });

                    list.hidden = chosen.length === 0;

                    note.textContent = message || '';
                    note.hidden = !message;
                }

                function add(files) {
                    var refused = 0;
                    var full = false;

                    Array.prototype.forEach.call(files, function (file) {
                        if (already(file)) return;

                        if (chosen.length >= MAX_FILES) {
                            full = true;
                            return;
                        }

                        chosen.push(file);
                    });

                    sync();

                    var tooBig = chosen.filter(function (f) { return f.size > MAX_BYTES; }).length;
                    var message = '';

                    if (full) {
                        message = 'Only ' + MAX_FILES + ' files can be attached, so the rest were left out.';
                    } else if (tooBig > 0) {
                        // Said here rather than after submitting, because a file
                        // over the server's own limit never reaches the app --
                        // the request is refused whole and the page comes back
                        // blank with nothing to explain it.
                        message = tooBig === 1
                            ? 'One file is too large to send. Remove it before submitting.'
                            : tooBig + ' files are too large to send. Remove them before submitting.';
                    }

                    render(message);
                }

                browse.addEventListener('click', function () { input.click(); });

                input.addEventListener('change', function () {
                    add(input.files);
                });

                ['dragenter', 'dragover'].forEach(function (name) {
                    zone.addEventListener(name, function (event) {
                        event.preventDefault();
                        zone.classList.add('is-over');
                    });
                });

                ['dragleave', 'drop'].forEach(function (name) {
                    zone.addEventListener(name, function (event) {
                        event.preventDefault();
                        if (name === 'dragleave' && zone.contains(event.relatedTarget)) return;
                        zone.classList.remove('is-over');
                    });
                });

                zone.addEventListener('drop', function (event) {
                    if (event.dataTransfer && event.dataTransfer.files.length) {
                        add(event.dataTransfer.files);
                    }
                });

                // Dropping anywhere else on the page would otherwise make the
                // browser navigate away from a half-filled form.
                ['dragover', 'drop'].forEach(function (name) {
                    window.addEventListener(name, function (event) {
                        if (!zone.contains(event.target)) event.preventDefault();
                    });
                });
            })();
        </script>

        <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
            <button type="submit" class="btn btn-primary">Send ✈</button>
            {{-- Inside the same form, so everything typed so far goes with
                 it, and posted elsewhere via formaction.

                 formnovalidate matters: the form requires a category and
                 twenty characters of description, and a draft is saved
                 precisely when those are not there yet. Without it the
                 browser refuses to submit and the button does nothing,
                 with no message saying why. --}}
            <button type="submit" class="btn btn-muted"
                    formaction="{{ route('concerns.draft.save') }}"
                    formnovalidate>Save as draft</button>
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

                        // A fixed toggle means a fixed row. On a repeat the
                        // people are carried from the first concern and the
                        // toggle cannot move, so the list is shown as a
                        // record and must not become editable underneath
                        // it -- otherwise a student can tick somebody the
                        // server is going to ignore.
                        if (toggle.disabled) {
                            return;
                        }

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
                const descEl = document.getElementById('description');
                const charCountEl = document.getElementById('char-count');

                // The categories that reach the student's class adviser, and
                // therefore the only ones where "do not send this to my
                // adviser" means anything.
                //
                // Derived from CATEGORY_ROUTING rather than written out here.
                // The form used to keep its own copy of the routing table and
                // it drifted twice; this cannot, because there is only one.
                const ADVISER_CATEGORIES = @json(array_keys(array_filter(
                    \App\Http\Controllers\ConcernController::CATEGORY_ROUTING,
                    fn ($role) => $role === 'Adviser'
                )));

                // Who can be named, per category.
                //
                // An Academic concern climbs one ladder: the chair of the
                // student's programme, their college's dean, then the VPAA.
                // Offering the Legal Affairs Office or Gender and Development
                // there invites a student to name somebody with no part in it
                // -- the concern still goes to the chair, and a name has been
                // attached to a case it has nothing to do with.
                //
                // Others has no fixed handler, so it offers everybody.
                //
                // Facilities and Equipment have no entry because they can no
                // longer name anybody at all -- see NAMEABLE below. They used
                // to offer the General Services staff, the Dean and the Staff
                // Admin, which put the people who FIX the thing on a list
                // headed "this concern is about a particular person".
                // Straight from Concern::SUBCATEGORIES, so the form cannot
                // offer one the server will refuse, or miss one that was added.
                const SUBCATEGORIES = @json(\App\Models\Concern::SUBCATEGORIES);

                const NAMEABLE_ROLES = {
                    'Academic': ['Program Chair', 'Dean', 'Vice President for Academic Affairs'],
                    // Physical and Safety have no entry: they reach Guidance
                    // now, not the academic ladder, and an injury or a
                    // hazard can involve anyone on campus. Offering a chair
                    // and a dean there pointed a student at the two people
                    // least likely to have been present.
                };

                // The student's own college, whose people stay offered
                // whatever their role. A concern about teaching is often
                // about somebody in the college office rather than anybody on
                // the ladder above, and they are the one group a student can
                // be expected to know by name.
                const OWN_COLLEGE = @json(optional(auth()->user())->department);

                // Show only the people who have a part in this category, and
                // untick anybody the change has just hidden -- a name left
                // ticked in a row nobody can see would still be submitted.
                function narrowStaffPicker(cat) {
                    const picker = document.getElementById('about_staff_id');

                    if (!picker) return;

                    const allowed = NAMEABLE_ROLES[cat] || null;
                    const people = Array.prototype.slice.call(picker.querySelectorAll('.person'));

                    people.forEach(function (person) {
                        const keep = !allowed
                            || allowed.indexOf(person.dataset.role) !== -1
                            || (OWN_COLLEGE && person.dataset.office === OWN_COLLEGE);

                        person.dataset.offCategory = keep ? '' : '1';
                        person.hidden = !keep;

                        if (!keep) {
                            const box = person.querySelector('input[type="checkbox"]');
                            if (box) box.checked = false;
                        }
                    });

                    // A heading with nobody under it any more is a label for
                    // an empty space.
                    Array.prototype.forEach.call(picker.querySelectorAll('.people-group'), function (heading) {
                        const group = heading.dataset.office;
                        const anyLeft = people.some(function (person) {
                            return person.dataset.group === group && !person.hidden;
                        });

                        heading.hidden = !anyLeft;
                    });
                }

                function updateHelpers() {
                    const cat = categoryEl.value;
                    // The bypass only exists where there is an adviser tier to
                    // bypass. Unticked on the way out, so switching to
                    // Facilities cannot submit a preference that no longer
                    // applies -- the server clears it as well, but a box
                    // ticked out of sight is its own bug report.
                    // Naming a person: for the categories that can be about
                    // one. The adviser bypass below follows its own rule --
                    // Physical and Safety still reach the class adviser, so
                    // the student can still ask for somebody else.
                    //
                    // Facilities and Equipment are deliberately absent. They
                    // describe a thing that is broken, not somebody's
                    // conduct, and naming a subject is not a small act: it
                    // walls that person out of the case and routes it over
                    // their head. Offered under a broken chair, it invites a
                    // report against whoever last answered about the chair.
                    //
                    // A complaint about how an office TREATED somebody is a
                    // different thing, and still has a home: it is about
                    // conduct, so it goes under a category that still offers
                    // the box rather than under the broken item.
                    // Physical is absent: an injury that has already happened
                    // is reported so somebody can see to the student, not so
                    // somebody can be named for it. Safety stays, because a
                    // hazard is often somebody's to have left there.
                    const NAMEABLE = ['Academic', 'Safety', 'Others'];

                    // Who can be named, per category. An Academic concern
                    // climbs one ladder -- the chair of the student's
                    // programme, their college's dean, then the VPAA -- so
                    // those are the only people worth offering. Naming
                    // somebody outside it does not route the concern away
                    // from anybody who was ever going to handle it; it just
                    // adds a name to a case that still goes to the chair.
                    //
                    const aboutGroup = document.getElementById('about-person-group');

                    if (aboutGroup) {
                        const canName = NAMEABLE.indexOf(cat) !== -1;
                        aboutGroup.style.display = canName ? 'block' : 'none';

                        narrowStaffPicker(cat);

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
                        // Nothing chosen yet means nothing to say about it.
                        // This used to show while the category was still
                        // empty, so the first thing a student read on the
                        // form was an option about a handler they had not
                        // been told about -- and for most categories it then
                        // disappeared, which reads as the form losing it.
                        //
                        // Read from the server's own routing table rather
                        // than from a sentence. This used to compare against
                        // the words shown to the student ("your class
                        // adviser"), so removing that text took the variable
                        // with it and left this throwing a ReferenceError --
                        // which aborted the rest of updateHelpers() and took
                        // the sub-category dropdown and the Others field down
                        // with it. A runtime error, so it parsed cleanly and
                        // said nothing anywhere.
                        const adviserCategory = Boolean(cat) && ADVISER_CATEGORIES.indexOf(cat) !== -1;
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

                    // The second dropdown. Its options belong to the chosen
                    // category, so they are REBUILT on every change rather
                    // than hidden: a hidden <option> is not honoured in every
                    // browser, and a list that silently keeps the wrong
                    // choices is worse than no list.
                    const subGroup = document.getElementById('subcategory-group');
                    const subEl = document.getElementById('subcategory');

                    if (subGroup && subEl) {
                        const options = SUBCATEGORIES[cat] || [];
                        const wanted = subEl.value || subEl.dataset.old || '';

                        subEl.textContent = '';

                        const blank = document.createElement('option');
                        blank.value = '';
                        blank.textContent = '-- Select --';
                        subEl.appendChild(blank);

                        options.forEach(function (label) {
                            const option = document.createElement('option');
                            option.value = label;
                            option.textContent = label;
                            subEl.appendChild(option);
                        });

                        // Keep what they picked only if the new category still
                        // offers it; otherwise it would be submitted against a
                        // category it does not belong to.
                        subEl.value = options.indexOf(wanted) !== -1 ? wanted : '';
                        subEl.dataset.old = '';

                        const hasOptions = options.length > 0;
                        subGroup.style.display = hasOptions ? 'block' : 'none';

                        // Required only while it is on screen. "Others" is
                        // the one category that breaks down into nothing, so
                        // its dropdown is hidden -- and a hidden required
                        // field refuses the form with a message the browser
                        // cannot point at, leaving Submit dead and nothing
                        // on the page explaining why.
                        subEl.required = hasOptions;
                    }

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
        <script>
            // Completing a name inside the description.
            //
            // The caret's current word is matched against the people this
            // form already lists. Choosing one replaces that partial word
            // with the full name -- nothing more: the text is still text, and
            // naming somebody as the SUBJECT of a concern is the checkbox
            // below, which walls them out of the case and routes it over
            // their head.
            (function () {
                var box = document.getElementById('mention-box');
                var area = document.getElementById('description');

                if (!box || !area) return;

                // Looked up on the server, not shipped with the page. The
                // list is every registered account now, and embedding it
                // would put the roster -- names, programmes, sections --
                // into the HTML of a form any student can open, readable
                // in full without typing a character.
                var LOOKUP = @json(route('concerns.mentionable'));
                var lastQuery = null;
                var timer = null;

                var matches = [];
                var active = -1;
                var wordStart = 0;

                // What follows an "@" at the caret, or null.
                //
                // Triggered by the @ rather than by any word being typed.
                // Matching every word meant the list appeared over and over
                // while somebody was writing an ordinary sentence -- the
                // point of the @ is that the person asks for it.
                //
                // The fragment may hold spaces, because people are looked
                // up by surname as often as by first name and "Dela Cruz"
                // is two words. It stops at the second one so a whole
                // paragraph after a stray @ is not treated as a search.
                function currentMention() {
                    var caret = area.selectionStart;
                    var before = area.value.slice(0, caret);
                    // The @ has to start a word. Without that, the address
                    // in "e@mail.com" opened the list mid-sentence and held
                    // it open on "mail.com".
                    var match = before.match(/(?:^|\s)@([^@\n]{0,40})$/);

                    if (!match) return null;

                    // Four words, not two. "Ma. Lovella Brondial" is three
                    // tokens and "Dr. Kenny Nino H. Tagum" is five: capping
                    // at two meant the list vanished halfway through the
                    // name somebody was still typing, and they finished it
                    // by hand -- which is the one thing this exists to stop.
                    if (match[1].split(/\s+/).length > 4) return null;

                    // wordStart covers the @ as well: choosing a name
                    // replaces the marker with it.
                    wordStart = caret - match[1].length - 1;

                    return match[1];
                }

                // Up to two initials: one for a mononym, first and last
                // otherwise. A middle initial already in the name ("Ma.
                // Lovella B. Cruz") is skipped, or every circle in the
                // college reads "MB".
                function initialsOf(name) {
                    var words = name.split(/\s+/).filter(function (w) {
                        return /[A-Za-z]/.test(w) && !/^[A-Za-z]\.$/.test(w);
                    });

                    if (!words.length) return '?';

                    var first = words[0].charAt(0);

                    return (words.length === 1 ? first : first + words[words.length - 1].charAt(0))
                        .toUpperCase();
                }

                // The same name always gets the same colour, so a face in
                // the list is recognisable the second time it is seen.
                // Hashed rather than assigned by position: by position the
                // colours would shuffle on every keystroke.
                var AVATAR_COLOURS = ['#2f5bea', '#7c3aed', '#0f766e', '#b45309', '#be123c', '#0369a1'];

                function colourFor(name) {
                    var sum = 0;

                    for (var i = 0; i < name.length; i++) {
                        sum = (sum * 31 + name.charCodeAt(i)) % 100000;
                    }

                    return AVATAR_COLOURS[sum % AVATAR_COLOURS.length];
                }

                // Put the typed letters in bold wherever they fall in the
                // name, so the list shows why each row is in it.
                function markMatch(into, name, needle) {
                    into.textContent = '';

                    var at = needle ? name.toLowerCase().indexOf(needle.toLowerCase()) : -1;

                    if (at === -1) {
                        into.textContent = name;
                        return;
                    }

                    var hit = document.createElement('b');
                    hit.textContent = name.substr(at, needle.length);

                    into.appendChild(document.createTextNode(name.slice(0, at)));
                    into.appendChild(hit);
                    into.appendChild(document.createTextNode(name.slice(at + needle.length)));
                }

                function hide() {
                    box.hidden = true;
                    box.textContent = '';
                    matches = [];
                    active = -1;
                    // Forgotten, so typing the same name again asks again
                    // rather than sitting on a result that was dismissed.
                    lastQuery = null;
                }

                function choose(name) {
                    var caret = area.selectionStart;

                    // The @ stays. A textarea cannot style part of its own
                    // text, so the marker is the only thing that makes a
                    // mention read as one -- without it the name sat in the
                    // sentence looking like any other words, and there was
                    // nothing to show the person had been picked from a list.
                    var tag = '@' + name + ' ';

                    area.value = area.value.slice(0, wordStart) + tag + area.value.slice(caret);

                    var at = wordStart + tag.length;
                    area.setSelectionRange(at, at);
                    area.focus();

                    // The character counter and the minimum-length hint both
                    // listen for this; setting .value fires nothing.
                    area.dispatchEvent(new Event('input', { bubbles: true }));

                    hide();
                }

                function render(note) {
                    box.textContent = '';

                    matches.forEach(function (person, index) {
                        var row = document.createElement('button');
                        row.type = 'button';
                        row.className = 'mention-row' + (index === active ? ' is-active' : '');
                        row.setAttribute('role', 'option');

                        var avatar = document.createElement('span');
                        avatar.className = 'mention-avatar';
                        avatar.textContent = initialsOf(person.name);
                        avatar.style.background = colourFor(person.name);

                        var name = document.createElement('span');
                        name.className = 'mention-name';
                        // Built from nodes, never innerHTML: these are names
                        // off the database, and the matched run is wrapped in
                        // a tag. Pasting them into markup would be an opening
                        // for anything somebody managed to register under.
                        markMatch(name, person.name, lastQuery);

                        var who = document.createElement('span');
                        who.className = 'mention-who';
                        who.textContent = person.who;

                        var text = document.createElement('span');
                        text.className = 'mention-text';
                        text.appendChild(name);
                        text.appendChild(who);

                        row.appendChild(avatar);
                        row.appendChild(text);

                        // mousedown, not click: the textarea loses focus
                        // first otherwise and the caret position is gone.
                        row.addEventListener('mousedown', function (event) {
                            event.preventDefault();
                            choose(person.name);
                        });

                        box.appendChild(row);
                    });

                    // A word when there is nothing to list: "keep typing"
                    // and "nobody by that name" are different answers, and
                    // a box that just vanishes gives neither.
                    if (!matches.length && note) {
                        var empty = document.createElement('p');
                        empty.className = 'mention-empty';
                        empty.textContent = note;
                        box.appendChild(empty);
                    }

                    box.hidden = matches.length === 0 && !note;
                }

                // ---- The tag, painted behind the text -----------------
                //
                // A textarea has one colour for all of its text, so a chosen
                // name cannot be styled where it sits. The pill is drawn on a
                // div underneath holding the same words at the same metrics,
                // with its own glyphs invisible; the textarea's real text
                // lands on top of it. Both have to agree about every property
                // that decides where a character goes, so the mirror is given
                // the textarea's own computed values rather than a guess.
                var backdrop = document.getElementById('mention-backdrop');

                var MIRRORED = [
                    'fontFamily', 'fontSize', 'fontWeight', 'fontStyle', 'lineHeight',
                    'letterSpacing', 'wordSpacing', 'textTransform', 'textIndent',
                    'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft',
                    'borderTopWidth', 'borderRightWidth', 'borderBottomWidth', 'borderLeftWidth',
                    'borderRadius', 'boxSizing'
                ];

                function matchMetrics() {
                    if (!backdrop) return;

                    var from = window.getComputedStyle(area);

                    MIRRORED.forEach(function (property) {
                        backdrop.style[property] = from[property];
                    });

                    backdrop.style.borderStyle = 'solid';
                }

                // A name that was picked: an "@" followed by capitalised
                // words. Lower case is left alone on purpose -- it is what
                // somebody is in the middle of typing, and a pill forming
                // letter by letter under a half-written name flickers.
                //
                // The particles are listed rather than described as "a short
                // lower-case word", so that "@Juan dela Cruz" is one tag
                // while "@Christian and Maria" is a tag followed by two
                // ordinary words. "and" fits any loose description of a
                // particle, and swallowed the next name with it.
                var TAG = /@([A-Z][^\s@]*(?:\s+(?:de|del|dela|delos|delas|da|di|van|von|y)\s+)?(?:\s*[A-Z][^\s@]*){0,4})/g;

                function paint() {
                    if (!backdrop) return;

                    backdrop.textContent = '';

                    var text = area.value;
                    var at = 0;
                    var found;

                    TAG.lastIndex = 0;

                    while ((found = TAG.exec(text)) !== null) {
                        backdrop.appendChild(
                            document.createTextNode(text.slice(at, found.index))
                        );

                        var chip = document.createElement('span');
                        chip.className = 'mention-chip';
                        chip.textContent = found[0];
                        backdrop.appendChild(chip);

                        at = found.index + found[0].length;
                    }

                    // The trailing newline matters: without it a tag on the
                    // last line sits one line too high once the box scrolls.
                    backdrop.appendChild(document.createTextNode(text.slice(at) + '\n'));

                    backdrop.scrollTop = area.scrollTop;
                    backdrop.scrollLeft = area.scrollLeft;
                }

                matchMetrics();
                paint();

                area.addEventListener('scroll', function () {
                    if (!backdrop) return;

                    backdrop.scrollTop = area.scrollTop;
                    backdrop.scrollLeft = area.scrollLeft;
                });

                // Dragging the resize handle changes the wrap, and so does
                // turning a phone sideways.
                window.addEventListener('resize', matchMetrics);

                if (window.ResizeObserver) {
                    new window.ResizeObserver(paint).observe(area);
                }

                area.addEventListener('input', paint);

                area.addEventListener('input', function () {
                    var fragment = currentMention();

                    if (fragment === null) {
                        hide();
                        return;
                    }

                    var needle = fragment.trim();

                    // Two letters before asking. "@" alone would mean
                    // fetching every account on the first keystroke, which
                    // is the directory this is meant not to be.
                    if (needle.length < 2) {
                        matches = [];
                        active = -1;
                        render(needle.length ? null : 'Keep typing a name\u2026');
                        return;
                    }

                    if (needle === lastQuery) return;

                    lastQuery = needle;

                    // Debounced: one request when they pause, not one per
                    // keystroke.
                    window.clearTimeout(timer);
                    timer = window.setTimeout(function () {
                        fetch(LOOKUP + '?q=' + encodeURIComponent(needle), {
                            headers: { 'Accept': 'application/json' },
                            credentials: 'same-origin'
                        })
                            .then(function (response) {
                                // A lapsed session answers with the login
                                // PAGE, status 200. Parsed as JSON that
                                // throws, and the list just disappeared with
                                // nothing said.
                                var type = response.headers.get('content-type') || '';

                                if (!response.ok || type.indexOf('json') === -1) {
                                    return null;
                                }

                                return response.json();
                            })
                            .then(function (people) {
                                // They may have typed on, or chosen, while
                                // this was in flight.
                                if (lastQuery !== needle) return;

                                if (people === null) {
                                    matches = [];
                                    render('Could not look that up. Reload the page.');
                                    return;
                                }

                                matches = people;
                                active = matches.length ? 0 : -1;
                                render(matches.length ? null : 'No account by that name');
                            })
                            .catch(function () { hide(); });
                    }, 180);
                });

                area.addEventListener('keydown', function (event) {
                    if (box.hidden || !matches.length) return;

                    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                        event.preventDefault();
                        active = (active + (event.key === 'ArrowDown' ? 1 : -1) + matches.length) % matches.length;
                        render();
                        return;
                    }

                    // Tab as well as Enter: Enter alone is how somebody starts
                    // a new paragraph, and taking it always would fight them.
                    if (event.key === 'Tab' || (event.key === 'Enter' && active >= 0)) {
                        event.preventDefault();
                        choose(matches[active].name);
                        return;
                    }

                    if (event.key === 'Escape') {
                        hide();
                    }
                });

                area.addEventListener('blur', function () { window.setTimeout(hide, 120); });
            })();
        </script>

@endsection
