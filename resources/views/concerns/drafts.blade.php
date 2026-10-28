@extends('layout')

@section('title', 'Drafts')

@section('content')
<div class="card">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:1rem; flex-wrap:wrap;">
        <div>
            <h1>Drafts</h1>
            {{-- What this page is for, in one line. A student arrives here
                 from a button called "Draft" and the rest should be the
                 list, not an explanation of it. --}}
            <p style="color:#64748b; font-size:0.85rem; margin-top:0.25rem;">
                Started but not sent. Nobody can see these but you.
            </p>
        </div>
        <div style="display:flex; gap:0.6rem; align-items:center;">
            <a href="{{ route('concerns.index') }}" class="btn btn-muted">Back to List</a>
            <a href="{{ route('concerns.create') }}" class="btn btn-primary">+ New Concern</a>
        </div>
    </div>

    @if ($drafts->isEmpty())
        <hr style="margin:1.5rem 0; border:none; border-top:1px solid #ddd;">
        <p style="text-align:center; color:var(--muted); font-size:.9rem; padding:2rem 1rem; line-height:1.6;">
            Nothing saved yet.<br>
            Start a concern and press <strong>Save as draft</strong> to keep it here.
        </p>
    @else
        <div style="display:flex; flex-direction:column; gap:.7rem; margin-top:1.4rem;">
            @foreach ($drafts as $draft)
                <div style="border:1px solid var(--line,#e7ebf1); border-radius:12px; padding:.9rem 1rem;
                            display:flex; gap:1rem; align-items:center; flex-wrap:wrap;">
                    <div style="flex:1; min-width:14rem;">
                        <p style="margin:0; font-weight:700; font-size:.95rem; color:var(--navy-900,#0b1733);">
                            {{ $draft->label() }}
                        </p>
                        @if ($draft->preview() !== '')
                            <p style="margin:.2rem 0 0; font-size:.85rem; color:#44506b; overflow-wrap:anywhere;">
                                {{ $draft->preview() }}
                            </p>
                        @endif
                        <p style="margin:.35rem 0 0; font-size:.76rem; color:var(--muted,#64748b);">
                            Saved {{ $draft->updated_at->diffForHumans() }}
                        </p>
                    </div>

                    <div style="display:flex; gap:.5rem; align-items:center;">
                        <a href="{{ route('concerns.create', ['draft' => $draft->id]) }}"
                           class="btn btn-primary" style="font-size:.85rem;">Continue</a>
                        <form action="{{ route('concerns.draft.discard', $draft) }}" method="POST" style="margin:0;">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-ghost-danger" style="font-size:.85rem;"
                                onclick="return confirm('Delete this draft? It has not been sent to anybody, and this cannot be undone.');">Delete</button>
                        </form>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
@endsection
