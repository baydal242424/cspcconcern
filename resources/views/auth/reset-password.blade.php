<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - CSPC Report Concern</title>
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
            -webkit-mask-image:radial-gradient(700px 500px at 50% 45%,#000,transparent 75%);opacity:.7}
        .brand>*{position:relative;z-index:1}
        .bhead{display:flex;align-items:center;gap:1rem}
        .bhead img{width:74px;height:74px;border-radius:14px;object-fit:contain;background:#fff;padding:6px;
            box-shadow:0 8px 22px -8px rgba(0,0,0,.6)}
        .bhead .t1{font-size:1.45rem;font-weight:800;letter-spacing:-.02em;line-height:1.1}
        .bhead .t2{font-size:.9rem;color:#9fc1ff;font-weight:600;margin-top:2px}
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
        input:read-only{background:#f3f5f9;color:var(--muted)}
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
        .foot{text-align:center;color:var(--muted);font-size:.8rem;margin-top:1.1rem}
        @media(max-width:900px){.wrap{grid-template-columns:1fr}.brand{display:none}.formside{padding:1.25rem}}
        @media(max-width:480px){.card{padding:1.5rem 1.25rem}}
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
            </div>

            <div class="hero">
                <h1>Choose a new<br>password.</h1>
                <p>Make it something you'll remember, but hard for others to guess.</p>
            </div>

            <div class="botquote">
                <span class="lab">MISSION:</span>
                <p>"CSPC is highly committed to delivering quality polytechnic education, providing excellent services, and promoting sustainable development and resilience in alignment with legal and statutory requirements, through cutting-edge technological innovation, impactful extension services, robust research culture, efficient resource management, and mutually beneficial partnerships with local and international stakeholders."</p>
            </div>
        </aside>

        <main class="formside">
            <div class="card">
                <h2>Reset password</h2>
                <p class="sub">Enter your new password below.</p>

                @if ($errors->any())
                    <div class="alert-error">
                        <strong>Something went wrong</strong>
                        <ul>
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form action="{{ route('password.update') }}" method="POST">
                    @csrf
                    <input type="hidden" name="token" value="{{ $token }}">
                    <div class="fg">
                        <label for="email">Email address</label>
                        <input type="email" id="email" name="email" value="{{ old('email', $email) }}"
                               placeholder="you@cspc.edu" required autofocus readonly>
                    </div>
                    <div class="fg">
                        <label for="password">New password</label>
                        <div class="iw">
                            <input type="password" id="password" name="password" placeholder="••••••••" required minlength="8">
                            <button type="button" class="toggle"
                                onclick="const p=document.getElementById('password');p.type=p.type==='password'?'text':'password';this.textContent=p.type==='password'?'Hide':'Show'">Show</button>
                        </div>
                    </div>
                    <div class="fg">
                        <label for="password_confirmation">Confirm new password</label>
                        <div class="iw">
                            <input type="password" id="password_confirmation" name="password_confirmation" placeholder="••••••••" required minlength="8">
                        </div>
                    </div>
                    <button type="submit" class="btn">Reset password</button>
                </form>

                <div class="foot">© {{ date('Y') }} Camarines Sur Polytechnic Colleges. All rights reserved.</div>
            </div>
        </main>
    </div>
</body>
</html>
