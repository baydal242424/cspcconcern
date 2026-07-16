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
                <option value="Mental Health / Personal">Mental Health / Personal</option>
                <option value="Bullying / Harassment">Bullying / Harassment</option>
                <option value="Administrative">Administrative</option>
                <option value="Physical / Safety">Physical / Safety</option>
                <option value="Others">Others</option>
            </select>
            @error('category')
                <div style="color: #dc3545; font-size: 0.85rem; margin-top: 0.25rem;">{{ $message }}</div>
            @enderror
        </div>

        <div class="form-group">
            <label for="department">Department *</label>
            <select name="department" id="department" required>
                <option value="">-- Select a department --</option>
                <option value="College of Engineering and Architecture">College of Engineering and Architecture</option>
                <option value="College of Computer Studies">College of Computer Studies</option>
                <option value="College of Health Sciences">College of Health Sciences</option>
                <option value="College of Tourism, Hospitality and Business Management">College of Tourism, Hospitality and Business Management</option>
                <option value="College of Technological and Development Education">College of Technological and Development Education</option>
                <option value="Guidance Office">Guidance Office</option>
                <option value="SASO">SASO</option>
            </select>
            <p id="department-helper" style="font-size: 0.9rem; color: #60708a; font-style: italic; margin-top: 0.5rem; display:none;"></p>
            <div id="confidentiality-box" style="display:none; background:#fff3cd; border:1px solid #ffe69c; color:#856404; padding:0.9rem; border-radius:6px; margin-top:1rem; font-size:0.9rem;">
                Your concern will be handled confidentially.
            </div>
            @error('department')
                <div style="color: #dc3545; font-size: 0.85rem; margin-top: 0.25rem;">{{ $message }}</div>
            @enderror
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
                    <p style="margin-top:0.5rem; color:#64748b;">Take your time. If a concern is sensitive, share only what you feel safe sharing &mdash; you can also submit anonymously below.</p>
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

        <div class="form-group">
            <label>
                <input type="checkbox" id="about_toggle" onchange="document.getElementById('about_wrap').style.display=this.checked?'block':'none'; if(!this.checked){document.getElementById('about_staff_id').value='';}">
                <span style="font-weight: normal; margin-left: 0.5rem;">This concern is about a specific staff member</span>
            </label>
            <div id="about_wrap" style="display:none; margin-top:0.6rem;">
                <label for="about_staff_id" style="font-size:0.9rem;">Who is this concern about?</label>
                <select name="about_staff_id" id="about_staff_id">
                    <option value="">-- Select the staff member --</option>
                    @foreach ($staffMembers as $member)
                        <option value="{{ $member->id }}" {{ old('about_staff_id') == $member->id ? 'selected' : '' }}>{{ $member->name }}</option>
                    @endforeach
                </select>
                <p style="font-size: 0.82rem; color: #666; margin-top: 0.4rem;">To avoid a conflict of interest, this concern will <strong>not</strong> be assigned to the person named here. It will be routed to a higher authority instead.</p>
                @error('about_staff_id')
                    <div style="color: #dc3545; font-size: 0.85rem; margin-top: 0.25rem;">{{ $message }}</div>
                @enderror
            </div>
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

        <div class="form-group">
            <label>
                <input type="checkbox" name="is_anonymous" value="1">
                <span style="font-weight: normal; margin-left: 0.5rem;">Submit anonymously</span>
            </label>
            <p style="font-size: 0.85rem; color: #666; margin-top: 0.5rem;">If checked, your name is hidden from the staff handling your concern. Your identity is still recorded securely and can only be revealed by the Head of School through a logged action, and only when necessary (for example, a safety risk or a suspected false report). <a href="{{ route('policy') }}" target="_blank" style="color:#2f5bea;">Read the Privacy &amp; Confidentiality Policy</a>.</p>
        </div>

        <div style="display: flex; gap: 1rem;">
            <button type="submit" class="btn btn-primary">Send ✈</button>
            <a href="{{ route('concerns.index') }}" class="btn btn-muted">Cancel</a>
        </div>

        <script>
            (function() {
                const categoryEl = document.getElementById('category');
                const helperEl = document.getElementById('department-helper');
                const confidentialEl = document.getElementById('confidentiality-box');
                const descEl = document.getElementById('description');
                const charCountEl = document.getElementById('char-count');

                // Where each category is routed. This MUST mirror the server-side
                // routing in ConcernController::routeConcern(). Routing is decided
                // by category only; the department field is informational.
                const routingByCategory = {
                    'Academic': 'Faculty/Staff',
                    'Mental Health / Personal': 'the Guidance Office',
                    'Bullying / Harassment': 'the Guidance Office',
                    'Administrative': 'the Admin Office',
                    'Physical / Safety': 'Faculty/Staff',
                    'Others': 'Faculty/Staff'
                };

                function updateHelpers() {
                    const cat = categoryEl.value;
                    const target = routingByCategory[cat] || null;

                    if (target) {
                        helperEl.textContent = 'Based on your category, this will be routed to ' + target + '.';
                        helperEl.style.display = 'block';
                    } else {
                        helperEl.style.display = 'none';
                    }

                    // Show the confidentiality note only for sensitive categories.
                    const sensitive = (cat === 'Mental Health / Personal' || cat === 'Bullying / Harassment');
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