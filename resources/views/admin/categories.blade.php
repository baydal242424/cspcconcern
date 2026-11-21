@extends('layout')

@section('title', 'Concern Categories')

@section('content')

<div class="card">
    <h1>Concern Categories</h1>
    <p style="color:var(--muted); font-size:.88rem; margin-top:.3rem; max-width:48rem;">
        The kinds of concern a student may file. Adding one here puts it on the
        filing form straight away &mdash; no release, no deployment.
    </p>

    {{-- The one rule worth stating before anybody touches anything.
         The stored name is matched on every concern filed under it, so a
         rename would orphan them. There is no rename on this page for that
         reason, and saying so is better than leaving somebody to wonder
         where the field went. --}}
    <div class="alert alert-info" role="note" style="margin-top:1.2rem;">
        <div>
            <strong>A category&rsquo;s name is permanent.</strong>
            Every concern filed under it is matched by that name, so renaming one
            would lose them. The <em>label</em> underneath is what students read,
            and that can be changed whenever you like.
            To stop offering a category, untick <em>On the filing form</em> &mdash;
            the concerns already filed under it keep working and stay searchable.
        </div>
    </div>

    <h2 class="section-title" style="margin-top:2rem;">Add a category</h2>

    <form method="POST" action="{{ route('admin.categories.store') }}" style="max-width:40rem;">
        @csrf

        <div class="form-group">
            <label for="name">Stored name <span class="req">*</span></label>
            <input type="text" name="name" id="name" required maxlength="60"
                   value="{{ old('name') }}" placeholder="Canteen">
            <p style="color:var(--muted); font-size:.8rem; margin-top:.3rem;">
                Written once and never again. Letters, numbers, spaces, &amp; and - only.
            </p>
            @error('name')
                <div style="color:var(--danger-ink); font-size:.85rem; margin-top:.25rem;">{{ $message }}</div>
            @enderror
        </div>

        <div class="form-group">
            <label for="label">What students see <span class="req">*</span></label>
            <input type="text" name="label" id="label" required maxlength="80"
                   value="{{ old('label') }}" placeholder="Canteen &amp; Food Services">
            @error('label')
                <div style="color:var(--danger-ink); font-size:.85rem; margin-top:.25rem;">{{ $message }}</div>
            @enderror
        </div>

        <div class="form-group">
            <label for="routing_role">Which office handles it</label>
            <select name="routing_role" id="routing_role">
                <option value="">-- Let the system decide (goes to the class adviser) --</option>
                @foreach ($roles as $role)
                    <option value="{{ $role }}" @selected(old('routing_role') === $role)>{{ $role }}</option>
                @endforeach
            </select>
            @error('routing_role')
                <div style="color:var(--danger-ink); font-size:.85rem; margin-top:.25rem;">{{ $message }}</div>
            @enderror
        </div>

        <div class="form-group">
            <label for="subcategories">What is it about? &mdash; one per line</label>
            <textarea name="subcategories" id="subcategories" rows="5"
                      placeholder="Food quality&#10;Prices&#10;Cleanliness&#10;Staff">{{ old('subcategories') }}</textarea>
            <p style="color:var(--muted); font-size:.8rem; margin-top:.3rem;">
                Leave this empty and the second dropdown is hidden, the way
                &ldquo;Others&rdquo; works.
            </p>
            @error('subcategories')
                <div style="color:var(--danger-ink); font-size:.85rem; margin-top:.25rem;">{{ $message }}</div>
            @enderror
        </div>

        <button type="submit" class="btn btn-primary">Add category</button>
    </form>

    <hr style="margin:2.5rem 0; border:none; border-top:1px solid var(--line);">

    <h2 class="section-title">The ones you have</h2>

    @foreach ($categories as $category)
        @php $filed = $counts[$category->name] ?? 0; @endphp

        {{-- Collapsed by default, so the page is a list of what exists
             rather than eleven open forms. Editing is then something you
             choose to start, which is also what stops a stray keystroke
             landing in a field nobody meant to be in. --}}
        <details style="border:1px solid var(--line); border-radius:.75rem; padding:1.1rem; margin-bottom:1rem;">
            <summary style="cursor:pointer; display:flex; justify-content:space-between; align-items:baseline; gap:1rem; flex-wrap:wrap;">
                <span>
                    <strong style="font-size:1rem;">{{ $category->label }}</strong>
                    <span style="font-size:.8rem; color:var(--muted);">
                        &middot; stored as {{ $category->name }}
                        @if ($category->is_builtin) &middot; built in @endif
                        @unless ($category->is_active)
                            &middot; <span style="color:var(--danger-ink); font-weight:600;">not offered</span>
                        @endunless
                    </span>
                </span>
                <span style="font-size:.82rem; color:var(--muted);">
                    {{ $filed }} concern{{ $filed === 1 ? '' : 's' }} &middot; Edit
                </span>
            </summary>

        <form method="POST" action="{{ route('admin.categories.update', $category) }}" style="margin-top:1rem;">
            @csrf

            <div class="form-group" style="margin-top:.9rem;">
                <label for="label-{{ $category->id }}">What students see</label>
                <input type="text" name="label" id="label-{{ $category->id }}"
                       required maxlength="80" value="{{ $category->label }}">
            </div>

            <div class="form-group">
                <label for="role-{{ $category->id }}">Which office handles it</label>
                <select name="routing_role" id="role-{{ $category->id }}">
                    <option value="">-- Let the system decide --</option>
                    @foreach ($roles as $role)
                        <option value="{{ $role }}" @selected($category->routing_role === $role)>{{ $role }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Removal is per item and deliberate.
                 This was one textarea holding the whole list. Select-all
                 and a keystroke wiped all seven of Academic's in a single
                 action, with no warning and no undo, and the form looked
                 exactly as it does when somebody means it. --}}
            <div class="form-group">
                <label style="margin-bottom:.5rem;">What is it about?</label>

                @if (filled($category->subcategories))
                    <div style="display:flex; flex-direction:column; gap:.35rem; margin-bottom:.8rem;">
                        @foreach ($category->subcategories as $sub)
                            <label style="display:flex; align-items:center; gap:.5rem; font-weight:400; font-size:.88rem;">
                                <input type="checkbox" name="remove[]" value="{{ $sub }}">
                                <span>{{ $sub }}</span>
                                @if (($subCounts[$category->name][$sub] ?? 0) > 0)
                                    <span style="color:var(--muted); font-size:.78rem;">
                                        &middot; {{ $subCounts[$category->name][$sub] }} filed
                                    </span>
                                @endif
                            </label>
                        @endforeach
                    </div>
                    <p style="color:var(--muted); font-size:.8rem; margin:0 0 .8rem;">
                        Tick one to take it off the filing form. Concerns already
                        filed under it keep their value and stay searchable.
                    </p>
                @else
                    <p style="color:var(--muted); font-size:.82rem; margin:0 0 .8rem;">
                        None yet &mdash; this category has no second dropdown.
                    </p>
                @endif

                <label for="add-{{ $category->id }}" style="font-size:.85rem;">Add more &mdash; one per line</label>
                <textarea name="add" id="add-{{ $category->id }}" rows="3"
                          placeholder="Anything typed here is ADDED. Nothing above is replaced."></textarea>
            </div>

            <label style="display:flex; align-items:center; gap:.5rem; font-weight:500; font-size:.9rem;">
                <input type="checkbox" name="is_active" value="1" @checked($category->is_active)>
                On the filing form
            </label>
            {{-- Said here because the tick is the one control on this page
                 whose purpose is not obvious, and it is also the safe
                 alternative to the delete button below. --}}
            <p style="color:var(--muted); font-size:.8rem; margin:.4rem 0 0;">
                Ticked, students can choose this when filing. Unticked, it
                disappears from the filing form &mdash; but the
                {{ $filed }} concern{{ $filed === 1 ? '' : 's' }} already filed under it
                still {{ $filed === 1 ? 'opens' : 'open' }}, still
                {{ $filed === 1 ? 'appears' : 'appear' }} in the staff filter, and can
                still be worked on. This is how you retire a category without losing anything.
            </p>

            @if ($category->name === \App\Models\Concern::WEBSITE_FAULT)
                <p style="color:var(--muted); font-size:.8rem; margin-top:.4rem;">
                    This one is reached from the bug icon in the footer rather than
                    the category list, which is why it is unticked.
                </p>
            @endif

            <button type="submit" class="btn btn-muted" style="margin-top:.9rem;">Save changes</button>
        </form>

        {{-- Deleting is offered only where it is safe, and the reason it
             is not shows in its place. A disabled button with no
             explanation is a worse answer than a sentence. --}}
        <div style="border-top:1px solid var(--line); margin-top:1.1rem; padding-top:.9rem;">
            @if ($category->is_builtin)
                <p style="color:var(--muted); font-size:.8rem; margin:0;">
                    <strong>Cannot be deleted.</strong> This category came with the
                    system and is recreated whenever the database is built from
                    scratch, so deleting it would not last. Untick
                    <em>On the filing form</em> to stop offering it.
                </p>
            @elseif ($filed > 0)
                <p style="color:var(--muted); font-size:.8rem; margin:0;">
                    <strong>Cannot be deleted.</strong> {{ $filed }}
                    concern{{ $filed === 1 ? '' : 's' }}
                    {{ $filed === 1 ? 'is' : 'are' }} filed under it and
                    {{ $filed === 1 ? 'matches' : 'match' }} it by name, so deleting it
                    would leave {{ $filed === 1 ? 'that one' : 'them' }} pointing at
                    nothing. Untick <em>On the filing form</em> instead.
                </p>
            @else
                <form method="POST" action="{{ route('admin.categories.destroy', $category) }}"
                      onsubmit="return confirm('Delete &quot;{{ $category->name }}&quot;? Nothing has been filed under it, so nothing is lost — but this cannot be undone.');">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn" style="background:var(--danger-bg); color:var(--danger-ink);">
                        Delete this category
                    </button>
                    <span style="color:var(--muted); font-size:.8rem; margin-left:.6rem;">
                        Nothing has been filed under it.
                    </span>
                </form>
            @endif
        </div>
        </details>
    @endforeach
</div>

@endsection
