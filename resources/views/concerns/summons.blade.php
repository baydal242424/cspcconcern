{{-- The letter, as it is handed over.

     Its own page rather than a panel on the concern: this is printed, and
     everything a screen needs around it -- the navbar, the buttons, the
     rest of the case -- is what you do not want coming out of a printer.
     The print rules below drop them. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Letter · Concern #{{ $concern->id }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Inter',-apple-system,'Segoe UI',sans-serif; background:#eef1f6;
            color:#1f2733; line-height:1.6; padding:2rem 1rem}

        .bar{max-width:760px; margin:0 auto 1rem; display:flex; gap:.6rem; justify-content:flex-end; flex-wrap:wrap}
        .btn{border:none; cursor:pointer; font-family:inherit; font-size:.88rem; font-weight:600;
            padding:.6rem 1.1rem; border-radius:10px; text-decoration:none; display:inline-block}
        .btn-print{background:#2f5bea; color:#fff}
        .btn-back{background:#fff; color:#44506b; border:1px solid #d9e0ea}

        /* A4-ish, so what is on screen is what comes out. */
        .sheet{max-width:760px; margin:0 auto; background:#fff; padding:3.2rem 3.4rem;
            box-shadow:0 10px 36px -14px rgba(16,30,66,.3); border-radius:4px}

        .head{display:flex; gap:1rem; align-items:center; border-bottom:2px solid #0D1B3E; padding-bottom:1rem}
        .head img{width:64px; height:64px; object-fit:contain}
        .head .who{font-size:1.1rem; font-weight:700; color:#0D1B3E; letter-spacing:-.01em}
        .head .office{font-size:.9rem; color:#5b6577; margin-top:1px}

        .date{margin-top:2rem; font-size:.92rem; color:#44506b}
        .to{margin-top:1.6rem; font-size:.95rem}
        .to .name{font-weight:700; font-size:1.02rem}
        .body{margin-top:1.6rem; font-size:.97rem}
        .body p{margin-bottom:1rem}

        .when{margin:1.4rem 0; padding:1rem 1.2rem; border-left:3px solid #2f5bea; background:#f6f8fc}
        .when .line{font-size:1.05rem; font-weight:700; color:#0D1B3E}
        .when .place{font-size:.95rem; color:#44506b; margin-top:.2rem}

        .sign{margin-top:3rem}
        .sign .rule{width:16rem; border-top:1px solid #1f2733; padding-top:.35rem; font-size:.92rem; font-weight:600}
        .sign .role{font-size:.85rem; color:#5b6577}

        .ref{margin-top:2.6rem; padding-top:1rem; border-top:1px solid #e7ebf1;
            font-size:.76rem; color:#8c97a8}

        @media print{
            /* Ink, and nothing that is not the letter. */
            body{background:#fff; padding:0}
            .bar{display:none}
            .sheet{box-shadow:none; max-width:none; padding:2.4rem; border-radius:0}
        }
    </style>
</head>
<body>
    <div class="bar">
        {{-- The person being called in can read their own letter but not the
             concern behind it, so they are not offered a link into a 403. --}}
        @if (auth()->id() === $summons->user_id)
            <a href="{{ route('dashboard') }}" class="btn btn-back">Back</a>
        @else
            <a href="{{ route('concerns.show', $concern) }}" class="btn btn-back">Back to the concern</a>
        @endif
        <button type="button" class="btn btn-print" onclick="window.print()">Print or save as PDF</button>
    </div>

    <div class="sheet">
        <div class="head">
            <img src="{{ asset('images/cspc-logo.webp') }}" alt="">
            <div>
                <div class="who">Camarines Sur Polytechnic Colleges</div>
                <div class="office">{{ optional($summons->issuer->role)->name ?? 'Student Concern Office' }}</div>
            </div>
        </div>

        <p class="date">{{ $summons->created_at->local()->format('j F Y') }}</p>

        <div class="to">
            <div class="name">{{ $summons->person->name }}</div>
            @if ($summons->person->course || $summons->person->section)
                <div>{{ trim($summons->person->course.' '.$summons->person->section) }}</div>
            @endif
            @if ($summons->person->department)
                <div>{{ $summons->person->department }}</div>
            @endif
        </div>

        <div class="body">
            <p>Good day,</p>

            {{-- What it says, and what it does not. The person reading this
                 cannot open the concern, so the letter cannot carry what the
                 concern holds -- not the complaint, not the category, and
                 above all not who made it. It says that a matter involving
                 them is being looked into and asks them to come in. --}}
            <p>
                A matter involving you is being looked into by this office. You are asked
                to come in so that it can be discussed with you directly.
            </p>

            <div class="when">
                <div class="line">{{ $summons->meet_at->local()->format('l, j F Y') }} at {{ $summons->meet_at->local()->format('g:i A') }}</div>
                <div class="place">{{ $summons->place }}</div>
            </div>

            @if ($summons->note)
                <p>{{ $summons->note }}</p>
            @endif

            <p>
                Nothing is being decided before you have been heard. If you cannot attend at
                this time, contact this office so another can be arranged.
            </p>
        </div>

        <div class="sign">
            <div class="rule">{{ $summons->issuer->name }}</div>
            <div class="role">{{ optional($summons->issuer->role)->name }}</div>
        </div>

        {{-- The reference is the concern number and nothing more. It is how
             the office finds the file; it tells the reader nothing. --}}
        <p class="ref">Reference: Concern #{{ $concern->id }} · Letter issued {{ $summons->created_at->local()->format('j M Y, g:i A') }}</p>
    </div>
</body>
</html>
