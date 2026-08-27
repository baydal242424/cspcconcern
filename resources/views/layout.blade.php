{{-- July 2026 UI cleanup: defined the missing .btn-secondary (some views
     already used it), added .btn-muted / .btn-ghost-danger, the navbar's
     current-page highlight, .section-title + .detail-list (used by
     concerns/show), paginator styles, and screen-reader roles plus an
     auto-fade for the flash alerts (script at the bottom of <body>).
     Purely visual -- no routes or behavior changed. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title') - Report System</title>
    {{-- Optional: crisp SaaS font. Remove these 3 lines to use system fonts only. --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root{
            --navy-900:#0b1733; --navy-800:#0D1B3E;
            --brand:#2f5bea; --brand-600:#2347c4; --brand-50:#eef2ff;
            --ink:#1f2733; --muted:#64748b; --line:#e7ebf1;
            --bg:#f4f6fb; --surface:#ffffff;
            --ok-bg:#e8f7ee; --ok-ink:#0f6b34;
            --warn-bg:#fff4d6; --warn-ink:#8a5a00;
            --info-bg:#e2ecff; --info-ink:#1d4ed8;
            --danger-bg:#fde7ea; --danger-ink:#a31726;
            --radius:16px; --shadow-sm:0 1px 2px rgba(16,30,66,.06),0 1px 3px rgba(16,30,66,.05);
            --shadow:0 6px 24px -8px rgba(16,30,66,.18),0 2px 6px rgba(16,30,66,.06);
        }
        *{margin:0;padding:0;box-sizing:border-box}
        body{
            font-family:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;
            background:var(--bg); color:var(--ink); line-height:1.55;
            -webkit-font-smoothing:antialiased;
            background-image:radial-gradient(1200px 400px at 80% -120px,rgba(47,91,234,.07),transparent 60%);
        }
        h1,h2,h3{letter-spacing:-.02em; color:var(--navy-900); line-height:1.2}
        h1{font-size:1.6rem; font-weight:700}
        h3{font-size:1.05rem; font-weight:650}
        a{color:var(--brand)}

        .navbar{
            background:rgba(13,27,62,.92); backdrop-filter:saturate(140%) blur(8px);
            color:#fff; padding:.85rem 1.5rem; display:flex; justify-content:space-between;
            align-items:center; position:sticky; top:0; z-index:50;
            box-shadow:0 1px 0 rgba(255,255,255,.06),0 8px 24px -16px rgba(0,0,0,.6);
        }
        .navbar-brand{font-size:1.12rem; font-weight:700; letter-spacing:-.01em}
        .navbar-nav{display:flex; gap:.35rem; align-items:center}
        .navbar-nav a{
            color:#c7d2e8; text-decoration:none; font-size:.92rem; font-weight:500;
            padding:.5rem .8rem; border-radius:9px; transition:.18s;
        }
        .navbar-nav a:hover{color:#fff; background:rgba(255,255,255,.08)}
        .navbar-nav a.active{color:#fff; background:rgba(255,255,255,.14)}
        /* The logout button lives in a POST form but must look like a nav link. */
        .navbar-nav form{display:inline; margin:0}
        .navbar-nav button{
            background:none; border:none; cursor:pointer; font-family:inherit;
            color:#c7d2e8; font-size:.92rem; font-weight:500;
            padding:.5rem .8rem; border-radius:9px; transition:.18s;
        }
        .navbar-nav button:hover{color:#fff; background:rgba(255,255,255,.08)}
        .navbar-nav span{
            color:#aab8d4; font-size:.85rem; padding:.35rem .7rem;
            border:1px solid rgba(255,255,255,.14); border-radius:999px; white-space:nowrap;
        }

        /* ---- Notification bell ---- */
        /* The bell sits INSIDE .navbar-nav, which styles every <span> as a
           pill (padding, border, white-space:nowrap) and hides them outright
           below 768px. Those rules leak into the dropdown: they turned the
           unread dot into a wide pill and stopped the message text wrapping,
           so the panel overflowed sideways. Reset every span in here first,
           then re-declare the few that need real styling. Each selector is
           prefixed with .navbar-nav so it outranks both the base rule and its
           mobile override. */
        .navbar-nav .bell-wrap span{
            display:inline; padding:0; margin:0; border:none; border-radius:0;
            white-space:normal; font-size:inherit; color:inherit; line-height:inherit;
        }
        .navbar-nav .bell-wrap{position:relative; display:inline-flex}
        .navbar-nav .bell-btn{background:none; border:none; cursor:pointer; color:#c7d2e8; position:relative;
            padding:.5rem .6rem; border-radius:9px; display:inline-flex; align-items:center; transition:.18s}
        .navbar-nav .bell-btn:hover{color:#fff; background:rgba(255,255,255,.08)}
        .navbar-nav .bell-badge{position:absolute; top:.15rem; right:.1rem; min-width:17px; height:17px;
            padding:0 4px; background:#ef4458; color:#fff; border-radius:999px; font-size:.65rem;
            font-weight:700; display:flex; align-items:center; justify-content:center; line-height:1;
            border:2px solid var(--navy-800)}
        .navbar-nav .bell-panel{position:absolute; top:calc(100% + .5rem); right:0; z-index:60;
            width:340px; max-width:calc(100vw - 2rem);
            background:var(--surface); border:1px solid var(--line); border-radius:14px;
            box-shadow:0 18px 44px -12px rgba(16,30,66,.34); overflow:hidden}
        .navbar-nav .bell-head{display:flex; align-items:center; justify-content:space-between; gap:.5rem;
            padding:.8rem 1rem; border-bottom:1px solid var(--line); background:#f7f9fd; color:var(--ink)}
        .navbar-nav .bell-head strong{font-size:.9rem; color:var(--navy-900)}
        .navbar-nav .bell-head form{display:block; margin:0}
        .navbar-nav .bell-linkbtn{background:none; border:none; padding:0; cursor:pointer; font-family:inherit;
            font-size:.78rem; font-weight:600; color:var(--brand)}
        .navbar-nav .bell-linkbtn:hover{background:none; text-decoration:underline}
        /* Scrolls vertically only -- overflow-x:hidden stops a long unbroken
           word from producing the sideways scrollbar this panel had. */
        .navbar-nav .bell-list{max-height:380px; overflow-y:auto; overflow-x:hidden}
        .navbar-nav .bell-list form{display:block; margin:0}
        .navbar-nav .bell-item{width:100%; display:flex; gap:.6rem; align-items:flex-start; text-align:left;
            background:none; border:none; border-bottom:1px solid var(--line); cursor:pointer;
            padding:.8rem 1rem; font-family:inherit; border-radius:0; transition:background .15s}
        .navbar-nav .bell-item:hover{background:#f6f9ff}
        .navbar-nav .bell-item.unread{background:#f2f6ff}
        .navbar-nav .bell-dot{display:block; width:7px; height:7px; border-radius:50%;
            background:transparent; margin-top:.42rem; flex:0 0 7px}
        .navbar-nav .bell-item.unread .bell-dot{background:var(--brand)}
        /* min-width:0 lets the flex child shrink below its content width,
           which is what allows the text below to wrap instead of overflowing. */
        .navbar-nav .bell-item-body{display:flex; flex-direction:column; gap:.12rem; min-width:0; flex:1}
        .navbar-nav .bell-title{display:block; font-size:.85rem; font-weight:650; color:var(--navy-900)}
        .navbar-nav .bell-msg{display:block; font-size:.82rem; color:var(--muted); line-height:1.4;
            white-space:normal; overflow-wrap:anywhere}
        .navbar-nav .bell-time{display:block; font-size:.72rem; color:#94a3b8; margin-top:.15rem}
        .navbar-nav .bell-empty{padding:1.6rem 1rem; text-align:center; color:var(--muted);
            font-size:.85rem; line-height:1.5}

        .container{max-width:1140px; margin:2.25rem auto; padding:0 1.25rem; animation:rise .4s ease both}
        @keyframes rise{from{opacity:0; transform:translateY(8px)}to{opacity:1; transform:none}}

        .card{
            background:var(--surface); border:1px solid var(--line); border-radius:var(--radius);
            box-shadow:var(--shadow); padding:2rem; margin-bottom:1.5rem;
        }

        .btn{
            display:inline-flex; align-items:center; justify-content:center; gap:.5rem;
            padding:.7rem 1.25rem; border:1px solid transparent; border-radius:11px; cursor:pointer;
            text-decoration:none; font-size:.93rem; font-weight:600;
            transition:transform .12s,box-shadow .18s,background .18s; box-shadow:var(--shadow-sm);
        }
        .btn:hover{transform:translateY(-1px)}
        .btn:active{transform:translateY(0)}
        .btn-primary{background:linear-gradient(180deg,var(--brand),var(--brand-600)); color:#fff}
        .btn-primary:hover{box-shadow:0 8px 20px -6px rgba(47,91,234,.55)}
        .btn-success{background:linear-gradient(180deg,#1fb155,#149043); color:#fff}
        .btn-danger{background:linear-gradient(180deg,#ef4458,#cf2438); color:#fff}
        .btn-secondary{background:#eef1f6; color:#475569; border-color:#e2e7ef}
        .btn-secondary:hover{background:#e4e9f2}
        .btn-muted{background:#eef1f6; color:#475569; border-color:#e2e7ef}
        .btn-muted:hover{background:#e4e9f2}
        /* Quiet, text-style destructive action -- keeps delete available without
           making it the loudest control in the row. */
        .btn-ghost-danger{background:transparent; color:#b42318; box-shadow:none; border-color:transparent;
            padding:.5rem .7rem; font-weight:600}
        .btn-ghost-danger:hover{background:var(--danger-bg); transform:none}

        .form-group{margin-bottom:1.35rem}
        label{display:block; margin-bottom:.45rem; font-weight:600; color:var(--ink); font-size:.92rem}
        input[type="text"],input[type="email"],input[type="password"],select,textarea{
            width:100%; padding:.8rem .9rem; border:1.5px solid var(--line); border-radius:11px;
            font-size:.95rem; font-family:inherit; background:#fcfdff; color:var(--ink); transition:.18s;
        }
        input:focus,select:focus,textarea:focus{
            outline:none; border-color:var(--brand); box-shadow:0 0 0 4px var(--brand-50); background:#fff;
        }
        textarea{resize:vertical; min-height:150px}

        .alert{padding:.9rem 1.1rem; border-radius:12px; margin-bottom:1.4rem; font-weight:500;
            display:flex; gap:.6rem; align-items:center; border:1px solid transparent}
        .alert::before{font-size:1.05rem}
        .alert-success{background:var(--ok-bg); color:var(--ok-ink); border-color:#bfe8cd}
        .alert-success::before{content:"✓"}
        .alert-error{background:var(--danger-bg); color:var(--danger-ink); border-color:#f6c9cf}
        .alert-error::before{content:"!"}

        .status-badge,.urgency-badge{
            display:inline-flex; align-items:center; padding:.32rem .8rem; border-radius:999px;
            font-size:.78rem; font-weight:650; letter-spacing:.01em; border:1px solid transparent;
        }
        .urgency-badge{margin-left:.4rem}
        .status-submitted{background:var(--warn-bg); color:var(--warn-ink); border-color:#f3dca0}
        .status-in_progress{background:var(--info-bg); color:var(--info-ink); border-color:#bcd2ff}
        .status-resolved{background:var(--ok-bg); color:var(--ok-ink); border-color:#bfe8cd}
        /* Closed without action: deliberately NOT green. It is a finished
           case but not a successful one, and colouring it like a resolution
           would misread at a glance. */
        .status-closed_no_action{background:#eef1f6; color:#5b6577; border-color:#dfe4ec}
        .status-referred{background:#ece9ff; color:#5b3bd6; border-color:#d6cdfb}
        .status-approved{background:var(--ok-bg); color:var(--ok-ink); border-color:#bfe8cd}
        .status-pending{background:var(--warn-bg); color:var(--warn-ink); border-color:#f3dca0}
        .status-banned{background:var(--danger-bg); color:var(--danger-ink); border-color:#f6c9cf}
        .status-rejected{background:var(--danger-bg); color:var(--danger-ink); border-color:#f6c9cf}
        .urgency-low{background:#e6f0ff; color:#1d4ed8}
        .urgency-medium{background:#fff3d6; color:#8a5a00}
        .urgency-high{background:#ffe2e2; color:#b42318}
        .urgency-critical{background:linear-gradient(180deg,#a40e1f,#7d0a17); color:#fff}
        .urgency-pending,.urgency-{background:#eef1f6; color:#64748b; border-color:#e2e7ef}

        .table{width:100%; border-collapse:separate; border-spacing:0; margin-top:1rem;
            border:1px solid var(--line); border-radius:14px; overflow:hidden}
        .table th{background:#f7f9fd; padding:.85rem 1rem; text-align:left; font-size:.78rem;
            text-transform:uppercase; letter-spacing:.04em; color:var(--muted); border-bottom:1px solid var(--line)}
        .table td{padding:.95rem 1rem; border-bottom:1px solid var(--line); font-size:.92rem}
        .table tr:last-child td{border-bottom:none}
        .table tbody tr{transition:background .15s}
        .table tbody tr:hover{background:#f6f9ff}

        /* Section headings inside cards: h2 for correct document outline,
           sized to match the previous h3 look. */
        .section-title{font-size:1.05rem; font-weight:650; margin-bottom:1rem}

        /* Label/value rows for detail views. */
        .detail-list{display:grid; grid-template-columns:auto 1fr; gap:.55rem 1rem; align-items:baseline}
        .detail-list dt{color:var(--muted); font-size:.88rem; font-weight:600; white-space:nowrap}
        .detail-list dd{margin:0; font-size:.93rem}

        .pagination{display:inline-flex; gap:.3rem; list-style:none; padding:0; margin:0}
        .pagination .page-link{display:inline-flex; align-items:center; justify-content:center;
            min-width:2.2rem; padding:.45rem .65rem; border:1px solid var(--line); border-radius:9px;
            background:#fff; color:var(--ink); font-size:.88rem; font-weight:600; text-decoration:none;
            transition:.15s}
        .pagination .page-link:hover{border-color:var(--brand); color:var(--brand)}
        .pagination .page-item.active .page-link{background:var(--brand); border-color:var(--brand); color:#fff}
        .pagination .page-item.disabled .page-link{opacity:.45; pointer-events:none}

        .grid-2{display:grid; grid-template-columns:1fr 1fr; gap:1.75rem}
        .table-wrap{width:100%; overflow-x:auto; -webkit-overflow-scrolling:touch; border-radius:14px}
        .table-wrap .table{margin-top:0}

        footer{text-align:center; color:var(--muted); padding:2rem 1rem 3rem; font-size:.85rem}

        @media (max-width:768px){
            .grid-2{grid-template-columns:1fr; gap:1rem}
            .navbar{padding:.7rem 1rem; flex-wrap:wrap; gap:.5rem}
            .navbar-brand{font-size:.95rem}
            .navbar-nav{gap:.15rem; flex-wrap:wrap}
            .navbar-nav span{display:none}
            .card{padding:1.4rem}
            .container{margin:1.4rem auto}
            .table th,.table td{padding:.6rem .7rem; font-size:.82rem; white-space:nowrap}
            h1{font-size:1.35rem}
        }
        @media (max-width:480px){
            .navbar{padding:.6rem .8rem}
            .navbar-brand{font-size:.85rem}
            .navbar-nav a{padding:.4rem .55rem; font-size:.85rem}
            .card{padding:1.1rem}
            .btn{padding:.6rem 1rem; font-size:.88rem}
            h1{font-size:1.2rem}
        }
        @media (prefers-reduced-motion:reduce){*{animation:none!important; transition:none!important}}
    </style>
</head>
<body>
    <nav class="navbar">
        <div style="display:flex; align-items:center; gap:0.9rem;">
            <img src="{{ asset('images/cspc-logo.webp') }}" alt="CSPC Logo" style="width:42px; height:42px; border-radius:50%; background:#ffffff14; object-fit:contain;" />
            <div class="navbar-brand">Student Concern Reporting System</div>
        </div>
        <div class="navbar-nav">
            @if (Auth::check())
                @if (optional(Auth::user()->role)->name === 'Admin')
                    <a href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard') ? 'active' : '' }}">Dashboard</a>
                @endif
                <a href="{{ route('concerns.index') }}" class="{{ request()->routeIs('concerns.*') ? 'active' : '' }}">Concerns</a>
                @if (optional(Auth::user()->role)->name === 'Admin')
                    <a href="{{ route('admin.users') }}" class="{{ request()->routeIs('admin.users') ? 'active' : '' }}">Manage Users</a>
                @endif
                <a href="{{ route('policy') }}" class="{{ request()->routeIs('policy') ? 'active' : '' }}">Policy</a>
                @include('partials.notification-bell')
                <span>{{ Auth::user()->name }} ({{ optional(Auth::user()->role)->name ?? 'N/A' }})</span>
                <form action="{{ route('logout') }}" method="POST">
                    @csrf
                    <button type="submit">Logout</button>
                </form>
            @else
                <a href="{{ route('policy') }}" class="{{ request()->routeIs('policy') ? 'active' : '' }}">Policy</a>
                <a href="{{ route('login') }}" class="{{ request()->routeIs('login') ? 'active' : '' }}">Login</a>
            @endif
        </div>
    </nav>

    <div class="container">
        @if ($message = Session::get('success'))
            <div class="alert alert-success" role="status">{{ $message }}</div>
        @endif

        @if ($message = Session::get('error'))
            <div class="alert alert-error" role="alert">{{ $message }}</div>
        @endif

        @yield('content')
    </div>

    <footer>
        © {{ date('Y') }} Camarines Sur Polytechnic Colleges. All Rights Reserved.
    </footer>

    <script>
        // Success flashes fade out on their own; errors stay until dismissed by
        // navigation so the user can't miss them.
        (function () {
            var el = document.querySelector('.alert-success');
            if (!el) return;
            setTimeout(function () {
                if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) { el.remove(); return; }
                el.style.transition = 'opacity .4s ease';
                el.style.opacity = '0';
                setTimeout(function () { el.remove(); }, 400);
            }, 4000);
        })();
    </script>
</body>
</html>