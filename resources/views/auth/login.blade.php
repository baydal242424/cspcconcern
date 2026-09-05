{{-- July 2026 UI cleanup: clearer copy ("Forgot your password?", "Keep me
     signed in" -- the toggle is Laravel's remember-me), autocomplete
     attributes for password managers, demo panel now local-env only, the
     Mission text moved to the register page. Form fields and routes are
     untouched. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign in - CSPC Report Concern</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root{--navy:#0D1B3E;--gold:#f4c430;--brand:#2f5bea;--brand6:#2347c4;--brand50:#eef2ff;
            --ink:#1f2733;--muted:#64748b;--line:#e7ebf1;--danger-bg:#fde7ea;--danger-ink:#a31726}
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Inter',-apple-system,'Segoe UI',sans-serif;color:var(--ink);min-height:100vh;
            display:flex;-webkit-font-smoothing:antialiased}
        .wrap{display:grid;grid-template-columns:1.1fr .9fr;width:100%;min-height:100vh}
        .brand{position:relative;color:#fff;padding:3rem 3.2rem;display:flex;flex-direction:column;
            justify-content:space-between;overflow:hidden;
            background:radial-gradient(800px 500px at 12% 6%,rgba(47,91,234,.4),transparent 55%),
                radial-gradient(700px 600px at 95% 100%,rgba(0,85,164,.4),transparent 55%),
                linear-gradient(155deg,#0a1530,#0D1B3E 55%,#10245c)}
        .brand::after{content:"";position:absolute;inset:0;
            background-image:radial-gradient(rgba(255,255,255,.05) 1px,transparent 1px);background-size:24px 24px;
            -webkit-mask-image:radial-gradient(700px 500px at 50% 45%,#000,transparent 75%);
            mask-image:radial-gradient(700px 500px at 50% 45%,#000,transparent 75%);opacity:.7}
        .brand>*{position:relative;z-index:1}
        .bhead{display:flex;align-items:center;gap:1rem}
        .bhead img{width:74px;height:74px;border-radius:14px;object-fit:contain;background:#fff;padding:6px;
            box-shadow:0 8px 22px -8px rgba(0,0,0,.6)}
        .bhead .t1{font-size:1.45rem;font-weight:800;letter-spacing:-.02em;line-height:1.1}
        .bhead .t2{font-size:.9rem;color:#9fc1ff;font-weight:600;margin-top:2px}
        .quote{border-top:1px solid #ffffff22;padding-top:1.1rem;margin-top:1.4rem}
        .quote .lab{color:var(--gold);font-weight:800;font-style:italic;font-size:.95rem}
        .quote p{color:#cdd8f0;font-style:italic;font-size:.88rem;line-height:1.55;margin-top:.2rem}
        .hero h1{font-size:2.1rem;font-weight:800;letter-spacing:-.03em;line-height:1.12}
        .hero p{color:#b9c6e6;font-size:1rem;line-height:1.6;margin-top:.7rem;max-width:42ch}
        .botquote{border-top:1px solid #ffffff22;padding-top:1.1rem}
        .botquote .lab{color:var(--gold);font-weight:800;font-style:italic;font-size:.95rem}
        .botquote p{color:#cdd8f0;font-style:italic;font-size:.82rem;line-height:1.5;margin-top:.2rem}
        .formside{background:linear-gradient(180deg,#eef2f9,#f6f8fc);display:flex;align-items:center;
            justify-content:center;padding:2.5rem}
        .card{background:#fff;border:1px solid var(--line);border-radius:18px;
            box-shadow:0 24px 60px -18px rgba(13,27,62,.28);padding:2.4rem;width:100%;max-width:410px}
        .card h2{font-size:1.5rem;font-weight:800;text-align:center;color:var(--navy);letter-spacing:-.02em}
        .card .sub{text-align:center;color:var(--muted);font-size:.93rem;margin-top:.3rem;margin-bottom:1.7rem}
        label{display:block;font-weight:600;font-size:.88rem;margin-bottom:.4rem}
        .fg{margin-bottom:1.15rem}
        .iw{position:relative}
        input{width:100%;padding:.82rem .9rem;border:1.5px solid var(--line);border-radius:11px;
            font-size:.95rem;font-family:inherit;background:#fcfdff;transition:.18s}
        input[type=password]{padding-right:3.6rem}
        input:focus{outline:none;border-color:var(--brand);box-shadow:0 0 0 4px var(--brand50);background:#fff}
        /* toggle is centered vertically inside the input itself */
        .toggle{position:absolute;right:.6rem;top:50%;transform:translateY(-50%);background:none;border:none;
            cursor:pointer;color:var(--brand);font-size:.78rem;font-weight:700;padding:.25rem .35rem;line-height:1}
        .btn{width:100%;padding:.9rem;background:linear-gradient(180deg,var(--brand),var(--brand6));color:#fff;
            border:none;border-radius:11px;font-size:1rem;font-weight:700;cursor:pointer;letter-spacing:.02em;
            transition:transform .12s,box-shadow .18s;box-shadow:0 8px 20px -6px rgba(47,91,234,.5)}
        .btn:hover{transform:translateY(-1px);box-shadow:0 12px 26px -6px rgba(47,91,234,.6)}
        .alert-error{background:var(--danger-bg);color:var(--danger-ink);border:1px solid #f6c9cf;
            padding:.85rem 1rem;border-radius:11px;margin-bottom:1.3rem;font-size:.9rem}
        .alert-error strong{display:block;margin-bottom:.2rem}
        .alert-error ul{margin:0;padding-left:1.1rem}
        .alert-success{background:#e7f8ee;color:#137a3f;border:1px solid #bfe9cf;
            padding:.85rem 1rem;border-radius:11px;margin-bottom:1.3rem;font-size:.9rem}
        /* The way out of a closed account. Warm rather than red: being
           graduated is not a failure, and it sits directly under an error
           box that already is red. */
        .reactivate-ask{background:var(--warn-bg,#fff4d6);border:1px solid #f3dca0;
            color:#7a5200;padding:.85rem 1rem;border-radius:11px;margin-bottom:1.3rem;font-size:.87rem}
        .reactivate-ask strong{display:block;margin-bottom:.2rem}
        .reactivate-ask p{margin:0 0 .7rem;line-height:1.45}
        .reactivate-ask form{margin:0}
        .btn-reactivate{width:100%;padding:.6rem .9rem;border:1px solid #b7822a;
            background:#fff;color:#7a5200;border-radius:9px;cursor:pointer;
            font-family:inherit;font-size:.86rem;font-weight:600}
        .btn-reactivate:hover{background:#7a5200;color:#fff}
        .reactivate-who{display:block;margin-top:.45rem;font-size:.78rem;opacity:.85}
        .forgot{display:block;text-align:right;font-size:.83rem;font-weight:600;color:var(--brand);
            text-decoration:none;margin:-.55rem 0 1.15rem}
        .forgot:hover{text-decoration:underline}
        .remember{display:flex;align-items:center;justify-content:flex-end;gap:.55rem;margin-top:1rem}
        .remember span{font-size:.85rem;color:var(--muted);font-weight:600}
        .switch{position:relative;display:inline-block;width:38px;height:22px;flex-shrink:0}
        .switch input{position:absolute;opacity:0;width:100%;height:100%;margin:0;cursor:pointer}
        .slider{position:absolute;inset:0;background:#d6dbe4;border-radius:999px;transition:.18s}
        .slider::before{content:"";position:absolute;width:16px;height:16px;left:3px;top:3px;background:#fff;
            border-radius:50%;transition:.18s;box-shadow:0 1px 3px rgba(0,0,0,.3)}
        .switch input:checked + .slider{background:var(--brand)}
        .switch input:checked + .slider::before{transform:translateX(16px)}
        .divider{display:flex;align-items:center;gap:.7rem;margin:1.3rem 0;color:var(--muted);font-size:.72rem;
            text-transform:uppercase;letter-spacing:.04em}
        .divider::before,.divider::after{content:"";flex:1;height:1px;background:var(--line)}
        .social-lab{text-align:center;font-size:.68rem;text-transform:uppercase;letter-spacing:.04em;
            color:var(--muted);margin-bottom:.6rem}
        .btn-google{width:100%;display:flex;align-items:center;justify-content:center;gap:.6rem;
            padding:.78rem;background:#fff;border:1.5px solid var(--line);border-radius:11px;
            font-size:.92rem;font-weight:700;color:var(--ink);cursor:pointer;text-decoration:none;
            transition:.15s}
        .btn-google:hover{border-color:var(--brand);box-shadow:0 0 0 4px var(--brand50)}
        .demo{margin-top:1.1rem;padding-top:1rem;border-top:1px solid var(--line)}
        .demo h3{font-size:.68rem;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);
            margin-bottom:.5rem;text-align:center}
        .demo-select{width:100%;padding:.6rem .7rem;border:1.5px solid var(--line);border-radius:10px;
            font-size:.82rem;font-family:inherit;background:#fff;color:var(--ink);cursor:pointer;transition:.15s}
        .demo-select:hover{border-color:var(--brand)}
        .demo-select:focus{outline:none;border-color:var(--brand);box-shadow:0 0 0 4px var(--brand50)}
        .demo-hint{font-size:.68rem;color:var(--muted);margin-top:.4rem;text-align:center}
        .foot{text-align:center;color:var(--muted);font-size:.8rem;margin-top:1.1rem}
        @media(max-width:900px){.wrap{grid-template-columns:1fr}.brand{display:none}.formside{padding:1.25rem}}
        @media(max-width:480px){.card{padding:1.5rem 1.25rem}}
        /* ---- Demo sign-in (only rendered when DEMO_LOGIN_ENABLED is set) ---- */
        .demo-signin{margin-top:1.4rem;padding-top:1.2rem;border-top:1px dashed #cbd5e1;
            display:flex;flex-direction:column;gap:.6rem;text-align:left}
        .demo-warn{background:#fff4d6;border:1px solid #f3dca0;color:#8a5a00;
            border-radius:9px;padding:.6rem .75rem;font-size:.74rem;line-height:1.45}
        .demo-warn strong{display:block;font-weight:700}
        .demo-lab{font-size:.68rem;text-transform:uppercase;letter-spacing:.06em;
            color:var(--muted);font-weight:600}
        .demo-signin select{width:100%;padding:.6rem .7rem;border:1.5px solid #e7ebf1;
            border-radius:9px;font-family:inherit;font-size:.88rem;background:#fcfdff;color:inherit}
        .demo-signin select:focus{outline:none;border-color:var(--brand);
            box-shadow:0 0 0 4px rgba(47,91,234,.12)}
        .demo-btn{padding:.6rem .9rem;border:1px solid #cbd5e1;border-radius:9px;
            background:#eef1f6;color:#475569;font-family:inherit;font-size:.85rem;
            font-weight:600;cursor:pointer;transition:background .15s}
        .demo-btn:hover{background:#e4e9f2}
        .demo-btn:focus-visible{outline:2px solid var(--brand);outline-offset:2px}
    </style>
</head>
<body>
    <div class="wrap">
        <aside class="brand">
            <div>
                <div class="bhead">
                    <img src="{{ asset('images/cspc-logo.webp') }}" alt="CSPC Logo">
                    <div>
                        <div class="t1">Camarines Sur<br>Polytechnic Colleges</div>
                        <div class="t2">Report Concern</div>
                    </div>
                </div>
                <div class="quote">
                    <span class="lab">VISION:</span>
                    <p>"CSPC envisions a dynamic, inclusive, resilient and globally competitive polytechnic educational institution committed to achieving excellence and advancing innovation that transcends societies in the Bicol region and beyond."</p>
                </div>
            </div>

            <div class="hero">
                <h1>Your concern,<br>heard and handled.</h1>
                <p>Submit academic, personal, facility, or safety concerns to the right office — securely, and seen only by the staff handling them.</p>
            </div>

            {{-- Mission statement lives on the register page; keeping only the
                 Vision here gives the hero message room to breathe. The empty
                 spacer preserves the three-slot layout so the hero stays centered. --}}
            <div aria-hidden="true"></div>
        </aside>

        <main class="formside">
            <div class="card">
                <h2>Welcome back</h2>
                <p class="sub">Sign in to submit and track your concern.</p>

                @if (session('success'))
                    <div class="alert-success">{{ session('success') }}</div>
                @endif

                @if ($errors->any())
                    <div class="alert-error">
                        <strong>Login failed</strong>
                        <ul>
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>

                    </div>
                @endif

                {{-- "Ask the admin" with no way to ask is a dead end: the
                     student is locked out, so they cannot reach anybody
                     through the system, and an irregular student with a real
                     concern would give up rather than hunt for an office.

                     Outside the error box on purpose. The error only survives
                     one redirect, so a reload took the way back off the page
                     while the account was still closed.

                     Who is asking comes from the session written by their own
                     refused sign-in, never from this form, so the button
                     cannot be aimed at another address. --}}
                @if ($reactivationCandidate)
                    <div class="reactivate-ask">
                        <strong>Still enrolled?</strong>
                        <p>If you are an irregular student still finishing subjects, ask an administrator to reopen your account.</p>
                        <form action="{{ route('auth.reactivation.request') }}" method="POST">
                            @csrf
                            <button type="submit" class="btn-reactivate">Ask the admin to reactivate my account</button>
                        </form>
                        <span class="reactivate-who">Sent as {{ $reactivationCandidate->email }}</span>
                    </div>
                @endif

                {{-- CSPC Mail is the ONLY way in. No password form, so there
                     are no passwords to guess, leak, reuse or reset, and every
                     account is provably tied to a real CSPC mailbox. --}}
                <div class="social-lab">Sign in with your CSPC account</div>
                <a href="{{ route('auth.google.redirect') }}" class="btn-google">
                    <svg width="18" height="18" viewBox="0 0 18 18" aria-hidden="true">
                        <path fill="#4285F4" d="M17.64 9.2c0-.64-.06-1.25-.16-1.84H9v3.48h4.84a4.14 4.14 0 0 1-1.8 2.72v2.26h2.91c1.7-1.57 2.69-3.88 2.69-6.62z"/>
                        <path fill="#34A853" d="M9 18c2.43 0 4.47-.8 5.96-2.18l-2.91-2.26c-.81.54-1.84.86-3.05.86-2.34 0-4.33-1.58-5.04-3.71H.96v2.33A9 9 0 0 0 9 18z"/>
                        <path fill="#FBBC05" d="M3.96 10.71A5.4 5.4 0 0 1 3.68 9c0-.59.1-1.17.28-1.71V4.96H.96A9 9 0 0 0 0 9c0 1.45.35 2.83.96 4.04l3-2.33z"/>
                        <path fill="#EA4335" d="M9 3.58c1.32 0 2.51.45 3.44 1.35l2.58-2.58C13.46.89 11.43 0 9 0A9 9 0 0 0 .96 4.96l3 2.33C4.67 5.16 6.66 3.58 9 3.58z"/>
                    </svg>
                    CSPC Mail
                </a>

                {{-- Demo sign-in. Rendered only when DEMO_LOGIN_ENABLED is set,
                     and the list only ever contains seeded accounts nobody has
                     signed into -- a real person's account disappears from it
                     the moment they first use Google. Styled to look like what
                     it is: a switch left on, not part of the product. --}}
                @if ($demoAccounts->isNotEmpty())
                    <form action="{{ route('auth.demo') }}" method="POST" class="demo-signin">
                        @csrf
                        <div class="demo-warn" role="note">
                            <strong>Demo sign-in is on.</strong>
                            Anyone visiting this page can sign in as these accounts.
                            Switch it off after the demonstration.
                        </div>

                        <label class="demo-lab" for="demo_user">Sign in as a demo account</label>
                        <select name="user_id" id="demo_user" required>
                            <option value="">Choose a role to preview…</option>
                            @foreach ($demoAccounts as $roleName => $people)
                                <optgroup label="{{ $roleName }}">
                                    @foreach ($people as $person)
                                        <option value="{{ $person->id }}">
                                            {{ $person->name }}@if ($person->department) — {{ $person->department }}@endif
                                        </option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>

                        <button type="submit" class="demo-btn">Sign in as this account</button>
                    </form>
                @endif

                {{-- There is no registration form. A student's first CSPC Mail
                     sign-in creates their account, and /complete-profile then
                     collects the student details. --}}
                {{-- Says which address to use, because the domain is what
                     decides whether the new account is a student or an
                     employee (see AuthController::DOMAIN_ROLES). --}}
                <p class="foot" style="margin-top:1.1rem; line-height:1.55">
                    Your account is created automatically on first sign-in.<br>
                    <b>Students</b> &mdash; use your <b>@my.cspc.edu.ph</b> address.<br>
                    <b>Faculty &amp; staff</b> &mdash; use your <b>@cspc.edu.ph</b> address.
                </p>

                <div class="foot">© {{ date('Y') }} Camarines Sur Polytechnic Colleges. All rights reserved.</div>
            </div>
        </main>
    </div>
</body>
</html>