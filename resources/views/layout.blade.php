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
        html{background:var(--navy-800); overscroll-behavior-y:none}
        body{
            font-family:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;
            background:var(--bg); color:var(--ink); line-height:1.55;
            /* html is navy so the mobile overscroll at the top matches the
               navbar. On a short page the body stopped at its content and the
               navy showed through underneath it as a dark band -- most
               obvious on the empty "no concerns yet" screen. Filling the
               viewport keeps the page background behind the whole page. */
            min-height:100vh;
            -webkit-font-smoothing:antialiased;
            background-image:radial-gradient(1200px 400px at 80% -120px,rgba(47,91,234,.07),transparent 60%);
        }
        h1,h2,h3{letter-spacing:-.02em; color:var(--navy-900); line-height:1.2}
        h1{font-size:1.6rem; font-weight:700}
        h3{font-size:1.05rem; font-weight:650}
        a{color:var(--brand)}

        /* Solid, not translucent. A semi-transparent bar with a backdrop blur
           has to recompute what is behind it on every scroll frame, and the
           page content sliding underneath showed through as a shimmer -- worst
           on Windows, where the blur is not GPU-accelerated. The bar is a fixed
           surface; it should look like one and cost nothing to keep on screen.

           top:-1px with a matching border removes the pale hairline that
           appeared above the bar when the page was over-scrolled upward. */
        .navbar{
            background:var(--navy-800);
            color:#fff; padding:.85rem 1.5rem; display:flex; justify-content:space-between;
            align-items:center; position:sticky; top:-1px; z-index:50;
            border-top:1px solid var(--navy-800);
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
            color:var(--ink);
            width:340px; max-width:calc(100vw - 2rem);
            background:var(--surface); border:1px solid var(--line); border-radius:14px;
            box-shadow:0 18px 44px -12px rgba(16,30,66,.34); overflow:hidden}

        /* On a narrow screen the panel spans the bar rather than hanging off
           the bell. Anchored to the icon it grew leftward from wherever that
           icon happened to sit, so on a phone its left edge fell off the
           screen -- the width was capped, the position was not. */
        @media (max-width:560px){
            /* Anchored to the whole nav row instead of to the icon, so it
               spans the screen under the bar wherever the icon sits.
               position:fixed was the obvious alternative and the wrong one:
               the navbar wraps on a phone, so there is no height to pin a
               top offset to. */
            .navbar-nav{position:relative}
            .navbar-nav .bell-wrap{position:static}
            .navbar-nav .bell-panel{left:0; right:0; width:auto; max-width:none}
            .navbar-nav .bell-list{max-height:min(60vh, 420px)}
        }
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
        .navbar-nav .bell-wrap span.bell-title{display:block; font-size:.85rem; font-weight:700; color:var(--navy-900)}
        .navbar-nav .bell-wrap span.bell-msg{display:block; font-size:.82rem; color:var(--ink); line-height:1.45;
            white-space:normal; overflow-wrap:anywhere}
        .navbar-nav .bell-wrap span.bell-time{display:block; font-size:.72rem; color:#5d6b80; margin-top:.2rem}
        .navbar-nav .bell-wrap p.bell-empty{padding:1.6rem 1rem; text-align:center; color:var(--muted);
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
        /* Closed at the end of the year, not disciplined -- so it must not
           read like a ban sitting next to one in the same list. */
        .status-graduated{background:#eef1f6; color:#5b6577; border-color:#dfe4ec}
        .urgency-low{background:#e6f0ff; color:#1d4ed8}
        .urgency-medium{background:#fff3d6; color:#8a5a00}
        .urgency-high{background:#ffe2e2; color:#b42318}
        .urgency-critical{background:linear-gradient(180deg,#a40e1f,#7d0a17); color:#fff}
        .urgency-pending,.urgency-{background:#eef1f6; color:#64748b; border-color:#e2e7ef}

        /* Concern list filter toolbar.
           The first version was a grey panel holding seven labelled boxes. It
           wrapped onto two ragged rows, orphaned the last two controls, and
           drew more attention than the table it was there to filter. This is
           one line: search, the two filters people actually reach for, and the
           rest behind "More". Applied filters appear as chips underneath, each
           removable on its own, so the current view is readable at a glance
           instead of having to be reconstructed from seven boxes. */
        .filters{display:flex; gap:.5rem; align-items:center; flex-wrap:wrap; margin-bottom:1.1rem}
        .filters input[type="text"],.filters input[type="date"],.filters select{
            width:auto; padding:.52rem .7rem; border-radius:10px; border-width:1px;
            font-size:.86rem; background:#fff}
        .filters .search{flex:1 1 210px; min-width:150px}
        .filters select{cursor:pointer}
        /* The filters open in a dialog rather than a dropdown panel.
           As a panel they had two faults: it floated over the table, hiding
           the very rows being filtered, and because each control applied on
           change the page reloaded and the panel shut -- so setting a date and
           a sort order meant opening it twice. A dialog holds still while
           several choices are made, and Done applies them together. */
        .filters .filter-btn{display:inline-flex; align-items:center; gap:.45rem; white-space:nowrap}
        .filters .filter-btn svg{width:15px; height:15px}
        .filter-dialog{border:none; padding:0; border-radius:16px; color:var(--ink);
            width:min(430px,calc(100vw - 2rem)); box-shadow:0 26px 60px -18px rgba(15,23,42,.45)}
        .filter-dialog::backdrop{background:rgba(11,23,51,.45)}
        .filter-dialog .fd-head{display:flex; align-items:flex-start; justify-content:space-between;
            gap:1rem; padding:1.05rem 1.25rem .9rem}
        .filter-dialog .fd-head h3{margin:0 0 .2rem; font-size:1.05rem}
        .filter-dialog .fd-head p{margin:0; font-size:.82rem; color:var(--muted)}
        .filter-dialog .fd-close{flex:none; background:#eef1f6; border:none; cursor:pointer; color:#475569;
            width:30px; height:30px; border-radius:50%; font-size:1.05rem; line-height:1}
        .filter-dialog .fd-close:hover{background:#e0e5ee}
        .filter-dialog .fd-body{padding:.25rem 1.25rem 1.15rem; display:grid; gap:.8rem;
            border-bottom:1px solid var(--line)}
        .filter-dialog .fd-row{display:grid; grid-template-columns:92px 1fr; align-items:center; gap:.9rem}
        .filter-dialog .fd-row label{margin:0; font-size:.88rem; color:#475569}
        .filter-dialog .fd-row select,.filter-dialog .fd-row input{width:100%; padding:.55rem .7rem;
            font-size:.88rem; border-radius:10px; border-width:1px; background:#fff}
        .filter-dialog .fd-foot{display:flex; align-items:center; justify-content:flex-end; gap:1.1rem;
            padding:.95rem 1.25rem}
        .filter-dialog .fd-foot a{color:var(--brand); font-weight:600; font-size:.9rem; text-decoration:none}
        .filter-dialog .fd-foot a:hover{text-decoration:underline}
        @media(max-width:480px){
            .filter-dialog .fd-row{grid-template-columns:1fr; gap:.3rem}
            .filter-dialog .fd-row label{font-size:.8rem; font-weight:600}
        }
        .filter-chips{display:flex; gap:.45rem; flex-wrap:wrap; align-items:center;
            margin:-.35rem 0 1.15rem; font-size:.8rem; color:var(--muted)}
        .filter-chip{display:inline-flex; align-items:center; gap:.4rem; font-weight:600;
            background:var(--brand-50); color:var(--brand-600); border:1px solid #dde4fb;
            border-radius:999px; padding:.24rem .5rem .24rem .68rem}
        .filter-chip a{color:inherit; text-decoration:none; opacity:.6; line-height:1; font-size:1rem}
        .filter-chip a:hover{opacity:1}
        @media(max-width:640px){
            .filters .search{flex:1 1 100%}
            .filters select{flex:1 1 calc(50% - .25rem)}
        }

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

        /* Stacked on a phone. Two columns need a label column wide enough for
           the longest label, and these labels do not wrap -- "Being handled
           by" and "Concern is about" set the width, leaving a name, a role
           and a college to wrap in whatever is left of a 360px screen.

           No real names in here: this stylesheet is inlined into every page,
           so an example person would be served on all of them -- which is
           how a test asserting a staff member is absent from the filing form
           caught this comment. */
        @media (max-width:560px){
            .detail-list{grid-template-columns:1fr; gap:0}
            .detail-list dt{white-space:normal; margin-top:.7rem}
            .detail-list dt:first-of-type{margin-top:0}
            .detail-list dd{font-size:.95rem; line-height:1.45}
        }

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

        /* Anything a person typed: a concern, staff notes, a closure reason.
           Two problems, both only visible with real input rather than test
           input.

           overflow-wrap:anywhere -- a description can be 2000 characters with
           no spaces in it. Without a break opportunity the line does not wrap,
           it just keeps going, widening the page and pushing every other
           element sideways.

           white-space:pre-wrap -- HTML collapses newlines, so a letter written
           in paragraphs arrived as one unbroken block. The student pressed
           Enter; showing it back to them any other way loses what they wrote. */
        .user-text{
            line-height:1.6; color:#555;
            white-space:pre-wrap; overflow-wrap:anywhere;
        }
        .user-text.closure{color:#6b4a00}

        /* Naming people on a concern. Checkboxes rather than a multi-select:
           picking several from a <select multiple> needs a Ctrl key, and most
           students file from a phone. A scrolling box keeps a list of several
           hundred instructors from burying the rest of the form. */
        .people-picker{
            max-height:16rem; overflow-y:auto; border:1px solid var(--line);
            border-radius:.5rem; background:#fff; padding:.35rem 0;
        }
        .people-picker .people-group{
            position:sticky; top:0; background:#f4f6fb; color:var(--navy-900);
            font-size:.78rem; font-weight:650; letter-spacing:.01em;
            padding:.35rem .75rem; border-bottom:1px solid var(--line);
        }
        .people-picker .person{
            display:flex; align-items:flex-start; gap:.55rem;
            padding:.45rem .75rem; font-weight:400; cursor:pointer;
        }
        /* The name filter and the "other colleges" fold both hide rows with
           row.hidden = true, and [hidden] only carries display:none from the
           user-agent stylesheet -- which the display:flex above outranks.
           Without this the search box narrowed nothing and the fold showed all
           368 instructors anyway. The headings are <p> elements, so they hide
           on their own; only the rows needed rescuing. */
        .people-picker .person[hidden]{display:none}
        /* 44px of tappable height per row: a phone is the common case. */
        .people-picker .person span{min-height:1.6rem; line-height:1.6}
        .people-picker .person:hover{background:#f7f9ff}
        .people-picker .person input{margin-top:.25rem; flex:none}
        .show-all-people{
            background:none; border:0; padding:.35rem 0; font:inherit;
            font-size:.85rem; color:var(--brand); cursor:pointer; text-decoration:underline;
        }
        .people-filter{
            padding:.5rem .7rem; border:1px solid var(--line); border-radius:.5rem;
            font:inherit; color:var(--ink); background:#fff;
        }

        footer{text-align:center; color:var(--muted); padding:2rem 1rem 3rem; font-size:.85rem}
        .footer-link{color:var(--muted); text-decoration:underline; text-underline-offset:3px}
        .footer-link:hover{color:var(--ink)}
        .footer-link.is-here{color:var(--ink); font-weight:600}
        .footer-sep{margin:0 .55rem; color:var(--line)}
        @media(max-width:520px){
            .footer-sep{display:block; visibility:hidden; height:.35rem}
        }

        /* Tablets, portrait and landscape. The gap between the phone rules
           and the desktop ones was the whole iPad range, which got the full
           bar -- longest brand string, widest padding -- on a screen narrower
           than the layout was drawn for. */
        @media (min-width:769px) and (max-width:1024px){
            .navbar{padding:.75rem 1.1rem}
            .navbar-brand{font-size:1rem}
            .navbar-nav{gap:.25rem}
            .navbar-nav a{padding:.45rem .7rem}
            .container{padding:0 1rem; margin:1.75rem auto}
            .grid-2{gap:1.25rem}
            .card{padding:1.6rem}
        }

        @media (max-width:768px){
            .grid-2{grid-template-columns:1fr; gap:1rem}
            .navbar{padding:.7rem 1rem; flex-wrap:wrap; gap:.5rem}
            .navbar-brand{font-size:.95rem}
            .navbar-nav{gap:.15rem; flex-wrap:wrap}
            /* The DIRECT child only: that is the signed-in user's name, the
               longest item in the bar and the first thing worth dropping.
               As a descendant selector this also hid every span inside the
               notification panel -- each row rendered as a bare dot with no
               title, message or time, on every phone and small tablet. */
            .navbar-nav > span{display:none}
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
        /* ---- Concern timeline ------------------------------------------------
       Two columns: a fixed label column and a track. The track and the date
       header are each their own grid of one cell per day, so a bar is placed
       by column number and span, and the day cells underneath keep the row
       stripes and the today line showing through it. */
    .tl-card{background:var(--surface,#fff); border:1px solid var(--line,#e7ebf1);
        border-radius:var(--radius,16px); box-shadow:var(--shadow-sm,0 1px 3px rgba(16,30,66,.05));
        padding:1.4rem 1.5rem 1rem; margin:1.5rem 0}
    .tl-head{display:flex; flex-wrap:wrap; gap:1rem; align-items:flex-start;
        justify-content:space-between; margin-bottom:1.1rem}
    .tl-title{font-size:1.05rem; font-weight:700; letter-spacing:-.01em;
        color:var(--ink,#1f2733); margin:0}
    .tl-range{font-size:.82rem; color:var(--muted,#64748b); margin:.15rem 0 0}

    .tl-legend{display:flex; flex-wrap:wrap; gap:.9rem; list-style:none; margin:0; padding:0}
    .tl-legend li{display:flex; align-items:center; gap:.4rem;
        font-size:.76rem; color:var(--muted,#64748b); font-weight:500}
    .tl-dot{width:9px; height:9px; border-radius:50%; flex:0 0 auto}

    /* One palette for the legend dot and the bar that matches it. */
    .tl-c-submitted{background:#f59e0b}
    .tl-c-in_progress{background:#3b82f6}
    .tl-c-referred{background:#a855f7}
    .tl-c-resolved{background:#10b981}
    .tl-c-closed_no_action{background:#94a3b8}
    .tl-c-today{background:#f59e0b}

    .tl-scroll{overflow-x:auto; padding-bottom:.3rem}
    .tl-grid{display:grid; grid-template-columns:210px 1fr; min-width:640px}

    .tl-corner{font-size:.66rem; text-transform:uppercase; letter-spacing:.09em;
        color:var(--muted,#64748b); font-weight:700;
        display:flex; align-items:flex-end; padding:0 .9rem .65rem 0}

    .tl-days{display:grid;
        grid-template-columns:repeat(var(--tl-days), minmax(32px, 1fr))}
    .tl-day{display:flex; flex-direction:column; align-items:center; gap:.1rem;
        padding-bottom:.65rem}
    .tl-dow{font-size:.58rem; font-weight:700; letter-spacing:.06em;
        color:var(--muted,#64748b)}
    .tl-dom{font-size:.84rem; font-weight:700; color:var(--ink,#1f2733)}
    .tl-day.is-today .tl-dow,
    .tl-day.is-today .tl-dom{color:#f59e0b}

    .tl-label{padding:.5rem .9rem .5rem 0; border-top:1px solid var(--line,#e7ebf1);
        display:flex; flex-direction:column; gap:.1rem; justify-content:center}
    .tl-name{font-size:.84rem; font-weight:600; color:var(--ink,#1f2733);
        text-decoration:none; line-height:1.3}
    .tl-name:hover{color:var(--brand,#2f5bea); text-decoration:underline}
    .tl-name-plain{cursor:default}
    .tl-name-plain:hover{color:var(--ink,#1f2733); text-decoration:none}
    .tl-when{font-size:.71rem; color:var(--muted,#64748b)}

    .tl-track{position:relative; display:grid;
        grid-template-columns:repeat(var(--tl-days), minmax(32px, 1fr));
        align-items:center; border-top:1px solid var(--line,#e7ebf1); min-height:46px}
    /* Alternating stripes, so the eye can follow a row across fourteen
       columns without losing it. Applied to both halves of the row. */
    /* Children run: corner, day header, then a label/track pair per row.
       Every SECOND row is therefore children 5-6, 9-10, 13-14 and so on --
       nth-of-type counted both halves as divs and tinted every row. */
    .tl-grid > :nth-child(4n+5), .tl-grid > :nth-child(4n+6){background:#fafbfd}
    .tl-cell{grid-row:1; height:100%; border-left:1px solid #f2f5fa}
    .tl-cell.is-today{background:rgba(245,158,11,.08);
        border-left:1px dashed #f59e0b; border-right:1px dashed #f59e0b}

    .tl-bar{grid-row:1; height:21px; border-radius:999px; display:flex; align-items:center;
        justify-content:center; text-decoration:none; margin:0 3px; position:relative; z-index:1;
        box-shadow:0 1px 3px rgba(16,30,66,.18); transition:transform .12s}
    .tl-bar:hover{transform:translateY(-1px)}
    .tl-bar-text{font-size:.63rem; font-weight:700; color:#fff; letter-spacing:.02em;
        white-space:nowrap; overflow:hidden; text-overflow:ellipsis; padding:0 .55rem}

    .tl-month{text-align:center; font-size:.68rem; font-weight:800; color:#f59e0b;
        letter-spacing:.1em; margin:.7rem 0 0}

    @media(max-width:640px){
        .tl-grid{grid-template-columns:150px 1fr}
        .tl-card{padding:1.1rem 1rem .8rem}
        .tl-head{gap:.6rem}
    }

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
                @if (Auth::user()->hasAnyRole(['System Admin', 'Staff Admin']))
                    <a href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard') ? 'active' : '' }}">Dashboard</a>
                @endif
                <a href="{{ route('concerns.index') }}" class="{{ request()->routeIs('concerns.*') ? 'active' : '' }}">Concerns</a>
                @if (Auth::user()->hasAnyRole(['System Admin', 'Staff Admin']))
                    <a href="{{ route('admin.users') }}" class="{{ request()->routeIs('admin.users') ? 'active' : '' }}">Manage Users</a>
                @endif
                @include('partials.notification-bell')
                <span>{{ Auth::user()->name }} ({{ optional(Auth::user()->role)->name ?? 'N/A' }})</span>
                <form action="{{ route('logout') }}" method="POST">
                    @csrf
                    <button type="submit">Logout</button>
                </form>
            @else
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
        <a href="{{ route('policy') }}" class="footer-link {{ request()->routeIs('policy') ? 'is-here' : '' }}">Data Privacy &amp; Confidentiality Policy</a>
        <span class="footer-sep">|</span>
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