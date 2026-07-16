@extends('layout')

@section('title', 'Edit Concern')

@section('content')
<div class="card">
    <h1>Edit Student Concern</h1>
    <p style="color: #666; margin-top: 0.5rem; margin-bottom: 2rem;">Update the details of your concern before it is processed.</p>

    <form action="{{ route('concerns.update', $concern) }}" method="POST">
        @csrf
        @method('PATCH')

        <div class="form-group">
            <label for="category">Concern Category *</label>
            <select name="category" id="category" required>
                <option value="">-- Select a category --</option>
                <option value="Academic" {{ old('category', $concern->category) === 'Academic' ? 'selected' : '' }}>Academic</option>
                <option value="Mental Health / Personal" {{ old('category', $concern->category) === 'Mental Health / Personal' ? 'selected' : '' }}>Mental Health / Personal</option>
                <option value="Bullying / Harassment" {{ old('category', $concern->category) === 'Bullying / Harassment' ? 'selected' : '' }}>Bullying / Harassment</option>
                <option value="Administrative" {{ old('category', $concern->category) === 'Administrative' ? 'selected' : '' }}>Administrative</option>
                <option value="Physical / Safety" {{ old('category', $concern->category) === 'Physical / Safety' ? 'selected' : '' }}>Physical / Safety</option>
                <option value="Others" {{ old('category', $concern->category) === 'Others' ? 'selected' : '' }}>Others</option>
            </select>
            @error('category')
                <div style="color: #dc3545; font-size: 0.85rem; margin-top: 0.25rem;">{{ $message }}</div>
            @enderror
        </div>

        <div class="form-group">
            <label for="department">Department *</label>
            <select name="department" id="department" required>
                <option value="">-- Select a department --</option>
                <option value="College of Engineering and Architecture" {{ old('department', $concern->department) === 'College of Engineering and Architecture' ? 'selected' : '' }}>College of Engineering and Architecture</option>
                <option value="College of Computer Studies" {{ old('department', $concern->department) === 'College of Computer Studies' ? 'selected' : '' }}>College of Computer Studies</option>
                <option value="College of Health Sciences" {{ old('department', $concern->department) === 'College of Health Sciences' ? 'selected' : '' }}>College of Health Sciences</option>
                <option value="College of Tourism, Hospitality and Business Management" {{ old('department', $concern->department) === 'College of Tourism, Hospitality and Business Management' ? 'selected' : '' }}>College of Tourism, Hospitality and Business Management</option>
                <option value="College of Technological and Development Education" {{ old('department', $concern->department) === 'College of Technological and Development Education' ? 'selected' : '' }}>College of Technological and Development Education</option>
                <option value="Guidance Office" {{ old('department', $concern->department) === 'Guidance Office' ? 'selected' : '' }}>Guidance Office</option>
                <option value="SASO" {{ old('department', $concern->department) === 'SASO' ? 'selected' : '' }}>SASO</option>
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
            <label for="description">Detailed Description *</label>
            <textarea name="description" id="description" rows="6" minlength="20" maxlength="2000"
                placeholder="Please provide details about your concern..." required>{{ old('description', $concern->description) }}</textarea>
            <div style="margin-top:0.5rem; color:#60708a; font-size:0.85rem;" id="char-count">0 / 2000</div>
            @error('description')
                <div style="color: #dc3545; font-size: 0.85rem; margin-top: 0.25rem;">{{ $message }}</div>
            @enderror
        </div>

        <div class="form-group">
            <label>
                <input type="checkbox" name="is_anonymous" value="1" {{ old('is_anonymous', $concern->is_anonymous) ? 'checked' : '' }}>
                <span style="font-weight: normal; margin-left: 0.5rem;">Submit anonymously</span>
            </label>
            <p style="font-size: 0.85rem; color: #666; margin-top: 0.5rem;">If checked, your name is hidden from the staff handling your concern. Your identity is still recorded securely and can only be revealed by the Head of School through a logged action, and only when necessary. <a href="{{ route('policy') }}" target="_blank" style="color:#2f5bea;">Read the Privacy &amp; Confidentiality Policy</a>.</p>
        </div>

        <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
            <button type="submit" class="btn btn-primary">Save Changes</button>
            <a href="{{ route('concerns.show', $concern) }}" class="btn btn-muted">Cancel</a>
        </div>

        <script>
            (function() {
                const categoryEl = document.getElementById('category');
                const departmentEl = document.getElementById('department');
                const helperEl = document.getElementById('department-helper');
                const confidentialEl = document.getElementById('confidentiality-box');
                const descEl = document.getElementById('description');
                const charCountEl = document.getElementById('char-count');

                // Where each category is routed. This MUST mirror the server-side
                // routing in ConcernController::routeConcern(). Routing is decided
                // by category only; the department field is informational, so we
                // never overwrite the student's own department selection here.
                const routingByCategory = {
                    'Academic': 'Faculty/Staff',
                    'Mental Health / Personal': 'the Guidance Office',
                    'Bullying / Harassment': 'the Guidance Office',
                    'Administrative': 'the Admin Office',
                    'Physical / Safety': 'Faculty/Staff',
                    'Others': 'Faculty/Staff'
                };

                function updateDepartmentSuggestion() {
                    const cat = categoryEl.value;
                    const target = routingByCategory[cat] || null;

                    if (target) {
                        helperEl.textContent = 'Based on your category, this will be routed to ' + target + '.';
                        helperEl.style.display = 'block';
                    } else {
                        helperEl.style.display = 'none';
                    }

                    const sensitive = (cat === 'Mental Health / Personal' || cat === 'Bullying / Harassment');
                    confidentialEl.style.display = sensitive ? 'block' : 'none';
                }

                categoryEl.addEventListener('change', updateDepartmentSuggestion);
                updateDepartmentSuggestion();

                function updateCharCount() {
                    const len = (descEl.value || '').trim().length;
                    charCountEl.textContent = len + ' / 2000';
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
