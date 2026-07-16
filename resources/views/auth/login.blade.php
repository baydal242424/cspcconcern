{{-- July 2026 UI cleanup: clearer copy ("Forgot your password?", "Keep me
     signed in" -- the toggle is Laravel's remember-me), autocomplete
     attributes for password managers, demo panel now local-env only, the
     Mission text moved to the register page, and the floating-dots
     animation on the brand panel (.fx below). Form fields and routes are
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
        /* Ambient aurora: three blurred glows drifting on slow, offset loops.
           Decorative only -- they sit behind the content and never move fast
           enough to pull the eye away from the sign-in form. */
        /* Rising lights: crisp dots drifting slowly upward, echoing the panel's
           static dot-grid texture. Staggered negative delays mean the scene is
           already "mid-flight" on page load instead of starting empty. */
        .fx{position:absolute;inset:0;overflow:hidden;z-index:0;pointer-events:none}
        .fx span{position:absolute;bottom:-12px;border-radius:50%;opacity:0;
            background:#9fc1ff;box-shadow:0 0 10px 2px rgba(122,163,255,.75);
            animation:rise linear infinite}
        .fx span.gold{background:var(--gold);box-shadow:0 0 12px 2px rgba(244,196,48,.6)}
        @keyframes rise{
            0%{transform:translateY(0) translateX(0);opacity:0}
            8%{opacity:.85}
            85%{opacity:.5}
            100%{transform:translateY(-108vh) translateX(40px);opacity:0}
        }
        @media (prefers-reduced-motion:reduce){.fx{display:none}}
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
        .demo-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:.4rem}
        .demo-account{background:#fff;border:1px solid var(--line);padding:.4rem .45rem;border-radius:9px;
            font-size:.68rem;cursor:pointer;transition:.15s;line-height:1.25;text-align:left}
        .demo-account:hover{border-color:var(--brand);box-shadow:0 0 0 3px var(--brand50)}
        .demo-account b{display:block;color:var(--navy);font-size:.7rem;margin-bottom:.02rem}
        .demo-account span{color:var(--muted);font-size:.6rem;word-break:break-all}
        .foot{text-align:center;color:var(--muted);font-size:.8rem;margin-top:1.1rem}
        @media(max-width:900px){.wrap{grid-template-columns:1fr}.brand{display:none}.formside{padding:1.25rem}}
        @media(max-width:480px){.demo-grid{grid-template-columns:1fr 1fr}.card{padding:1.5rem 1.25rem}}
    </style>
</head>
<body>
    <div class="wrap">
        <aside class="brand">
            <div class="fx" aria-hidden="true">
                <span style="left:6%;  width:4px; height:4px; animation-duration:18s; animation-delay:-3s"></span>
                <span style="left:14%; width:3px; height:3px; animation-duration:24s; animation-delay:-14s"></span>
                <span style="left:23%; width:5px; height:5px; animation-duration:16s; animation-delay:-7s" class="gold"></span>
                <span style="left:31%; width:3px; height:3px; animation-duration:26s; animation-delay:-20s"></span>
                <span style="left:39%; width:4px; height:4px; animation-duration:20s; animation-delay:-1s"></span>
                <span style="left:47%; width:6px; height:6px; animation-duration:15s; animation-delay:-10s"></span>
                <span style="left:55%; width:3px; height:3px; animation-duration:25s; animation-delay:-17s" class="gold"></span>
                <span style="left:63%; width:4px; height:4px; animation-duration:19s; animation-delay:-5s"></span>
                <span style="left:71%; width:5px; height:5px; animation-duration:17s; animation-delay:-12s"></span>
                <span style="left:79%; width:3px; height:3px; animation-duration:23s; animation-delay:-8s"></span>
                <span style="left:87%; width:4px; height:4px; animation-duration:21s; animation-delay:-15s" class="gold"></span>
                <span style="left:94%; width:5px; height:5px; animation-duration:18s; animation-delay:-2s"></span>
            </div>
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
                <p>Submit academic, personal, or safety concerns to the right office — securely and, if you choose, anonymously.</p>
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

                <form action="{{ route('login.post') }}" method="POST">
                    @csrf
                    <div class="fg">
                        <label for="email">Email address</label>
                        <div class="iw">
                            <input type="email" id="email" name="email" value="{{ old('email') }}"
                                   placeholder="you@cspc.edu" autocomplete="email" required autofocus>
                        </div>
                    </div>
                    <div class="fg">
                        <label for="password">Password</label>
                        <div class="iw">
                            <input type="password" id="password" name="password" placeholder="••••••••"
                                   autocomplete="current-password" required>
                            <button type="button" class="toggle"
                                onclick="const p=document.getElementById('password');p.type=p.type==='password'?'text':'password';this.textContent=p.type==='password'?'Hide':'Show'">Show</button>
                        </div>
                    </div>
                    <a href="{{ route('password.request') }}" class="forgot">Forgot your password?</a>
                    <button type="submit" class="btn">Sign in</button>
                    <label class="remember">
                        <span>Keep me signed in</span>
                        <span class="switch">
                            <input type="checkbox" name="remember" value="1" {{ old('remember') ? 'checked' : '' }}>
                            <span class="slider"></span>
                        </span>
                    </label>
                </form>

                <div class="divider">or</div>
                <div class="social-lab">Log in using your account on:</div>
                <a href="{{ route('auth.google.redirect') }}" class="btn-google">
                    <svg width="18" height="18" viewBox="0 0 18 18" aria-hidden="true">
                        <path fill="#4285F4" d="M17.64 9.2c0-.64-.06-1.25-.16-1.84H9v3.48h4.84a4.14 4.14 0 0 1-1.8 2.72v2.26h2.91c1.7-1.57 2.69-3.88 2.69-6.62z"/>
                        <path fill="#34A853" d="M9 18c2.43 0 4.47-.8 5.96-2.18l-2.91-2.26c-.81.54-1.84.86-3.05.86-2.34 0-4.33-1.58-5.04-3.71H.96v2.33A9 9 0 0 0 9 18z"/>
                        <path fill="#FBBC05" d="M3.96 10.71A5.4 5.4 0 0 1 3.68 9c0-.59.1-1.17.28-1.71V4.96H.96A9 9 0 0 0 0 9c0 1.45.35 2.83.96 4.04l3-2.33z"/>
                        <path fill="#EA4335" d="M9 3.58c1.32 0 2.51.45 3.44 1.35l2.58-2.58C13.46.89 11.43 0 9 0A9 9 0 0 0 .96 4.96l3 2.33C4.67 5.16 6.66 3.58 9 3.58z"/>
                    </svg>
                    CSPC Mail
                </a>

                <p class="foot" style="margin-top:1.1rem">
                    Don't have an account? <a href="{{ route('register') }}" style="color:var(--brand);font-weight:600;text-decoration:none">Register here</a>
                </p>

                {{-- Demo shortcuts are a local-development convenience only;
                     they never render outside APP_ENV=local. --}}
                @if (app()->environment('local'))
                <div class="demo">
                    <h3>Demo accounts · password: <b>password</b></h3>
                    <div class="demo-grid">
                        <button type="button" class="demo-account"
                            onclick="document.getElementById('email').value='student@cspc.edu';document.getElementById('password').value='password'">
                            <b>Student</b><span>student@cspc.edu</span>
                        </button>
                        <button type="button" class="demo-account"
                            onclick="document.getElementById('email').value='staff@cspc.edu';document.getElementById('password').value='password'">
                            <b>Staff</b><span>staff@cspc.edu</span>
                        </button>
                        <button type="button" class="demo-account"
                            onclick="document.getElementById('email').value='counselor@cspc.edu';document.getElementById('password').value='password'">
                            <b>Counselor</b><span>counselor@cspc.edu</span>
                        </button>
                        <button type="button" class="demo-account"
                            onclick="document.getElementById('email').value='admin@cspc.edu';document.getElementById('password').value='password'">
                            <b>Admin</b><span>admin@cspc.edu</span>
                        </button>
                        <button type="button" class="demo-account"
                            onclick="document.getElementById('email').value='depthead@cspc.edu';document.getElementById('password').value='password'">
                            <b>Department Head</b><span>depthead@cspc.edu</span>
                        </button>
                        <button type="button" class="demo-account"
                            onclick="document.getElementById('email').value='head@cspc.edu';document.getElementById('password').value='password'">
                            <b>Head of School</b><span>head@cspc.edu</span>
                        </button>
                    </div>
                </div>
                @endif

                <div class="foot">© {{ date('Y') }} Camarines Sur Polytechnic Colleges. All rights reserved.</div>
            </div>
        </main>
    </div>
</body>
</html>