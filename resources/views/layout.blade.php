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
        /* All / Unread. Pills rather than links: they swap what the list
           shows, they do not go anywhere. */
        .navbar-nav .bell-tabs{display:flex; gap:.4rem; padding:.55rem .8rem;
            border-bottom:1px solid var(--line)}
        .navbar-nav .bell-tab{background:#eef1f6; border:none; cursor:pointer; font-family:inherit;
            font-size:.82rem; font-weight:600; color:#51607a; padding:.35rem .85rem; border-radius:999px;
            display:inline-flex; align-items:center; gap:.35rem; transition:.15s}
        .navbar-nav .bell-tab:hover{background:#e3e8f1; color:var(--navy-900)}
        .navbar-nav .bell-tab.is-on{background:var(--brand); color:#fff}
        .navbar-nav .bell-tab-n{background:rgba(255,255,255,.28); border-radius:999px;
            padding:0 .35rem; font-size:.72rem; font-weight:700; line-height:1.5}
        .navbar-nav .bell-tab:not(.is-on) .bell-tab-n{background:var(--brand); color:#fff}
        /* "New" / "Earlier". A date on every row answers a question nobody
           asked; two headings answer the one they did. */
        .navbar-nav .bell-wrap p.bell-group{margin:0; padding:.7rem 1rem .3rem; font-size:.78rem;
            font-weight:800; color:var(--navy-900); letter-spacing:-.01em}
        .navbar-nav .bell-item{width:100%; display:flex; gap:.65rem; align-items:center; text-align:left;
            background:none; border:none; cursor:pointer;
            padding:.6rem .8rem; font-family:inherit; border-radius:10px; transition:background .15s}
        .navbar-nav .bell-row{padding:0 .35rem}
        .navbar-nav .bell-item:hover{background:#eef2f9}
        /* Unread is carried by the dot and the weight of the title, not by
           a tinted row: ten tinted rows in a column is just a tinted panel. */
        .navbar-nav .bell-item.unread .bell-title{font-weight:800}
        .navbar-nav .bell-dot{display:block; width:9px; height:9px; border-radius:50%;
            background:transparent; flex:0 0 9px; margin-left:.3rem}
        .navbar-nav .bell-item.unread .bell-dot{background:var(--brand)}
        /* min-width:0 lets the flex child shrink below its content width,
           which is what allows the text below to wrap instead of overflowing. */
        .navbar-nav .bell-item-body{display:flex; flex-direction:column; gap:.12rem; min-width:0; flex:1}
        .navbar-nav .bell-wrap span.bell-title{display:block; font-size:.85rem; font-weight:700; color:var(--navy-900)}
        .navbar-nav .bell-wrap span.bell-msg{display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical;
            overflow:hidden; font-size:.82rem; color:#44506b; line-height:1.45;
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
        /* Neither good news nor bad: a standing fact about what is on the
           page, such as "this is your copy of a case you handed on". */
        .alert-info{background:var(--brand50); color:var(--ink); border-color:#c7d4fb; font-weight:400}
        .alert-info::before{content:"i"; font-weight:700; color:var(--brand)}

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
        /* Withdrawn: grey like a closed case, because it is finished, but
           not the same grey -- it was the student's decision, not a
           finding, and the two should not read as one outcome. */
        .status-withdrawn{background:#f5f3ef; color:#6b6052; border-color:#e6e0d6}
        /* How many are in the folder. A count rather than a dot, now
           that there can be more than one -- "Drafts" with a dot beside
           it does not tell somebody whether the one they want is there. */
        .draft-pip{display:inline-block; min-width:18px; height:18px; padding:0 .3rem;
            border-radius:999px; background:var(--brand); color:#fff; font-size:.72rem;
            font-weight:700; line-height:18px; text-align:center; margin-left:.4rem;
            vertical-align:middle}
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

           overflow-wrap:anywhere -- a description can run to thousands of characters with
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
            display:flex; align-items:flex-start; gap:.6rem;
            padding:.5rem .75rem; font-weight:400; cursor:pointer;
        }
        /* Name on its own line, the rest under it in small grey.
           These used to be one string -- "Ms. Rosel O. Onesa (OIC) — Dean,
           College of Computer Studies" -- and the office repeated down every
           row of a group, so the names all sat in the middle of identical
           sentences with nothing to pick them out. */
        .people-picker .person-text{display:flex; flex-direction:column; gap:.1rem; min-width:0}
        .people-picker .person-name{font-size:.95rem; color:var(--ink); line-height:1.35}
        .people-picker .person-who{
            font-size:.78rem; color:var(--muted); line-height:1.3;
            overflow-wrap:anywhere;
        }
        /* The chosen names, above the box, so what you have picked does not
           need scrolling back for. */
        .people-chosen{display:flex; flex-wrap:wrap; gap:.35rem; margin:.1rem 0 .45rem}
        .people-chosen:empty{display:none}
        .people-chosen .chip{
            display:inline-flex; align-items:center; gap:.4rem;
            background:var(--brand-50); color:var(--navy-900);
            border:1px solid #d5dffb; border-radius:999px;
            padding:.22rem .4rem .22rem .7rem; font-size:.82rem; line-height:1.4;
        }
        .people-chosen .chip button{
            border:0; background:none; cursor:pointer; font:inherit;
            color:var(--muted); line-height:1; padding:.1rem .25rem; border-radius:999px;
        }
        .people-chosen .chip button:hover{background:#dbe4fd; color:var(--ink)}
        /* The name filter and the "other colleges" fold both hide rows with
           row.hidden = true, and [hidden] only carries display:none from the
           user-agent stylesheet -- which the display:flex above outranks.
           Without this the search box narrowed nothing and the fold showed all
           368 instructors anyway. The headings are <p> elements, so they hide
           on their own; only the rows needed rescuing. */
        .people-picker .person[hidden]{display:none}
        /* 44px of tappable height per row: a phone is the common case.
           Scoped to the row's own text wrapper -- as a bare "span" it also
           hit the name and the office line inside it and stacked three lots
           of minimum height on every row. */
        .people-picker .person > .person-text{min-height:2.2rem; justify-content:center}
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

        /* The asterisk on a required field.
           It was the same near-black as the label it follows, so the one
           character carrying "you have to fill this in" read as punctuation.
           --danger-ink is the red the validation messages under these same
           fields already use, so a field that is required and a field that
           has just been refused speak in one colour. */
        .req{color:var(--danger-ink); font-weight:700}

        /* Long text that would otherwise push the rest of the page away.
           A description may now run to 60,000 characters, and one of those
           on a phone buries the timeline, the notes and the action form
           under a scroll nobody reaches the end of. Ten lines is about a
           screenful; the rest is one tap away.

           Both spellings of the property: the -webkit- one is what every
           browser actually honours today, the unprefixed one is the
           standard and will outlive it.

           The fold is applied BY THE SCRIPT, not by this rule: a browser
           with JavaScript off would otherwise cut the text at ten lines
           and draw no button to open it, which is the one failure worse
           than a long scroll. Plain `.clamp` does nothing on its own. */
        .clamp.is-folded{
            display:-webkit-box; -webkit-box-orient:vertical;
            -webkit-line-clamp:10; line-clamp:10; overflow:hidden;
        }

        /* Hidden until the script finds the text really is too long. A
           "Show more" under four lines of text is a button that lies. */
        .clamp-toggle{
            display:none; margin-top:.5rem; padding:0; border:0; background:none;
            font:inherit; font-size:.85rem; font-weight:600; color:var(--brand);
            cursor:pointer; text-decoration:underline; text-underline-offset:3px;
        }
        .clamp-toggle.is-needed{display:inline-block}

        /* The same idea for the timeline, counted in entries rather than
           lines: a case that crossed nine desks reads as nine steps, and
           the five most recent are what anybody opens the page for.

           Folded by the script for the same reason as above: left to this
           rule alone, a browser with JavaScript off would hide the older
           half of a case's history with no way to ask for it.

           One mechanism for all of them -- the activity timeline, the
           review notes, the resolution notes. Each was growing its own
           copy, and three copies of "show the first few" is three places
           for them to stop agreeing. */
        .foldable.is-folded .fold-rest{display:none}

        /* A list long enough to fold is set more tightly even once it is
           open. Eight handling notes at full size is a page of scrolling
           whether or not the first five are showing, and these are short
           entries read in sequence rather than prose read once. */
        .fold-body{font-size:.92rem}
        .fold-meta{font-size:.82rem}
        .foldable.is-compact .fold-body,
        .foldable.is-compact .user-text{font-size:.86rem; line-height:1.5}
        .foldable.is-compact .fold-meta{font-size:.72rem}
        .foldable.is-compact{gap:.55rem}
        .foldable.is-compact .fold-entry{padding-bottom:.75rem}

        /* The bug box. A <dialog>, so the backdrop, the focus trap and
           Escape are the browser's job rather than three more scripts. */
        .bug-box{
            width:min(30rem, calc(100vw - 2rem)); padding:1.4rem;
            border:1px solid var(--line); border-radius:var(--radius, 16px);
            background:#fff; color:var(--ink); box-shadow:var(--shadow);
        }
        .bug-box::backdrop{background:rgba(13,27,62,.45)}
        .bug-box h2{font-size:1.1rem; margin-bottom:.4rem}
        .bug-box p{font-size:.86rem; color:var(--muted); margin-bottom:1rem}
        .bug-box label{display:block; font-weight:600; font-size:.88rem; margin-bottom:.35rem}
        .bug-box textarea{
            width:100%; padding:.65rem .8rem; font:inherit; font-size:.92rem;
            border:1px solid var(--line); border-radius:.6rem; resize:vertical;
            color:var(--ink); background:#fff;
        }
        .bug-box-actions{display:flex; justify-content:flex-end; gap:.6rem; margin-top:1rem}

        /* The message dock.
           "Write to someone on this concern" sat at the bottom of the page,
           under the notes, the timeline and the evidence. A handler who
           wanted to ask the student for a receipt scrolled past the whole
           case to find it, and on a long case did not know it was there.

           A <details> rather than a scripted panel: the tab opens and
           closes it natively, so it works with JavaScript off, keeps
           keyboard and screen-reader behaviour for free, and needs no
           state of its own. */
        .dock{
            position:fixed; right:1.25rem; bottom:1.25rem; z-index:60;
            display:flex; flex-direction:column; align-items:flex-end; gap:.7rem;
        }

        /* An ordinary button, bottom right, the way a chat widget sits.
           The panel opens ABOVE it and the button stays where it is, so
           the thing you just pressed does not move out from under you. */
        .dock-tab{
            list-style:none; cursor:pointer; order:2;
            display:flex; align-items:center; justify-content:center;
            width:3.1rem; height:3.1rem; border-radius:.9rem;
            background:var(--brand); color:#fff; border:0;
            box-shadow:0 6px 20px -4px rgba(47,91,234,.5), 0 2px 6px rgba(16,30,66,.2);
            transition:background .15s ease;
        }
        .dock-tab::-webkit-details-marker{display:none}
        .dock-tab::marker{content:""}
        .dock-tab:hover{background:var(--brand6)}
        .dock-tab:focus-visible{outline:3px solid var(--brand50); outline-offset:3px}
        .dock-tab svg{width:21px; height:21px; flex:none}

        /* The icon says what the next press does: an envelope to open,
           a cross to close. Swapped in CSS off the open attribute, so
           there is no script holding the two in step. */
        .dock-tab .dock-close{display:none}
        .dock[open] .dock-tab .dock-open{display:none}
        .dock[open] .dock-tab .dock-close{display:block}
        .dock[open] .dock-tab{background:var(--ink)}

        .dock-panel{
            order:1; width:min(21rem, calc(100vw - 2.5rem));
            max-height:min(70vh, 34rem); overflow-y:auto;
            padding:1.2rem; background:#fff; color:var(--ink);
            border:1px solid var(--line); border-radius:.9rem;
            box-shadow:0 18px 40px -12px rgba(16,30,66,.3), 0 4px 10px rgba(16,30,66,.08);
        }
        .dock-panel .section-title{font-size:1rem}

        /* It rises rather than appearing, which is what says it belongs to
           the button underneath it. Off for anybody who has asked for less
           movement. */
        @media (prefers-reduced-motion:no-preference){
            .dock[open] .dock-panel{animation:dock-rise .16s ease-out}
            @keyframes dock-rise{
                from{opacity:0; transform:translateY(.75rem)}
                to{opacity:1; transform:none}
            }
        }

        /* On a phone the panel spans the width, with the button still in
           its corner on top of it. */
        @media (max-width:520px){
            .dock{right:.75rem; bottom:.75rem; left:.75rem; align-items:flex-end}
            .dock-panel{width:100%; max-height:65vh}
        }

        /* What a notification just brought you to.

           A filled blue block, the way a comment linked to from a
           notification is marked elsewhere. The first version used
           --brand50, which is so near white that on a white card it read
           as nothing at all: the reader arrived at the right place and
           could not tell they had. */
        .is-flagged{
            background:#dbe7ff;
            border-left:3px solid var(--brand) !important;
            border-radius:.5rem;
            padding:.55rem .7rem .55rem .9rem;
            margin-left:-.2rem;
            box-shadow:0 0 0 4px rgba(47,91,234,.1);
        }

        /* Held, then let go: long enough to find after the scroll settles,
           and no longer, because a mark that never leaves becomes part of
           the page and stops meaning anything. */
        @media (prefers-reduced-motion:no-preference){
            .is-flagged{animation:flag-fade 6s ease-out forwards}
            @keyframes flag-fade{
                0%,66%{background:#dbe7ff; box-shadow:0 0 0 4px rgba(47,91,234,.1)}
                100%{background:transparent; box-shadow:0 0 0 4px rgba(47,91,234,0)}
            }
        }

        footer{text-align:center; color:var(--muted); padding:2rem 1rem 3rem; font-size:.85rem}
        .footer-link{color:var(--muted); text-decoration:underline; text-underline-offset:3px}
        .footer-link:hover{color:var(--ink)}
        .footer-link.is-here{color:var(--ink); font-weight:600}
        .footer-sep{margin:0 .55rem; color:var(--line)}

        /* The bug link.
           A fault in this website is no longer one of the categories the
           filing form offers, so this is the way to it. It sits in the
           footer because that is where it is wanted: on whatever page has
           just misbehaved, rather than three clicks away on a form about
           the college. The underline is on the label and not the anchor, or
           the icon gets a line under it too. */
        .footer-bug{display:inline-flex; align-items:center; gap:.3rem; text-decoration:none}
        .footer-bug span{text-decoration:underline; text-underline-offset:3px}
        .footer-bug svg{width:14px; height:14px; flex:none}
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
        /* ---- Name completion in the description ------------------------------
       Positioned against the form group so it sits under the textarea.
       It inserts text and nothing else -- naming somebody as the SUBJECT of
       a concern is a checkbox, and it carries consequences this does not. */
    .mention-box{position:absolute; z-index:40; left:0; right:0; margin-top:-.4rem;
        background:#fff; border:1px solid var(--line,#e7ebf1); border-radius:12px;
        box-shadow:0 14px 32px -12px rgba(16,30,66,.3); overflow:hidden; max-height:260px;
        overflow-y:auto}
    .mention-row{display:flex; align-items:center; gap:.6rem; width:100%; text-align:left;
        padding:.5rem .7rem; background:none; border:none; cursor:pointer; font-family:inherit}
    .mention-row:hover,.mention-row.is-active{background:var(--brand-50,#eef2ff)}
    /* Initials, because there are no profile photos. A row that is only two
       lines of text reads as a menu; the circle is what makes it read as a
       person, which is the whole difference between picking an option and
       picking somebody. */
    .mention-avatar{flex:0 0 34px; width:34px; height:34px; border-radius:50%;
        display:flex; align-items:center; justify-content:center; font-size:.78rem;
        font-weight:700; color:#fff; background:var(--brand,#2f5bea); letter-spacing:.02em}
    .mention-text{display:flex; flex-direction:column; gap:.1rem; min-width:0}
    .mention-name{font-size:.9rem; font-weight:500; color:var(--ink,#1f2733);
        white-space:nowrap; overflow:hidden; text-overflow:ellipsis}
    /* The letters they have typed so far, picked out of the name. With eight
       near-identical names in the list, this is what shows WHY each one is
       there and how much further there is to type to separate them. */
    .mention-name b{font-weight:700; color:var(--brand-600,#2347c4)}
    .mention-who{font-size:.76rem; color:var(--muted,#64748b);
        white-space:nowrap; overflow:hidden; text-overflow:ellipsis}
    .mention-empty{margin:0; padding:.6rem .8rem; font-size:.82rem; color:var(--muted,#64748b)}

    /* A chosen name, shown as a tag.
       A textarea cannot style part of its own text, so the pill is painted on
       a div behind it that mirrors the same text with the same metrics. The
       mirror's own glyphs are invisible; only the pill behind them shows, and
       the real text of the textarea sits on top of it. Everything that moves
       the text -- typing, wrapping, scrolling -- has to move both. */
    .mention-field{position:relative}
    /* The mirror carries the field's fill, since the textarea above it is
       now transparent. Same two colours the input rule uses, and the same
       change on focus, so the field looks no different for having a mirror
       behind it. */
    .mention-backdrop{position:absolute; inset:0; z-index:0; overflow:hidden;
        pointer-events:none; color:transparent; background:#fcfdff;
        white-space:pre-wrap; overflow-wrap:anywhere; border-color:transparent}
    .mention-field:focus-within .mention-backdrop{background:#fff}
    /* :focus named too. The focus rule above also sets a background and has
       the same weight as this one, so without it the field turns opaque the
       moment somebody clicks into it -- which is exactly when the tags
       need to be visible. */
    .mention-field textarea,.mention-field textarea:focus{position:relative; z-index:1;
        background:transparent}
    .mention-chip{border-radius:4px; background:var(--brand-50,#eef2ff);
        box-shadow:0 0 0 1px rgba(47,91,234,.18)}

    /* ---- Attaching evidence ----------------------------------------------
       A drop zone wrapped around the real file input, not a replacement for
       it: the input still holds the files and still submits them. The list
       below exists because a FileList cannot be edited, so the native
       control has no way to drop one file -- picking again replaces the
       whole set. */
    .dropzone{position:relative; border:2px dashed var(--line,#e7ebf1); border-radius:14px;
        background:#fcfdff; padding:1.5rem 1.1rem; text-align:center; transition:.15s}
    .dropzone.is-over{border-color:var(--brand,#2f5bea); background:var(--brand-50,#eef2ff)}
    /* Kept in the layout, not display:none -- a hidden input cannot be
       focused, and the keyboard needs to reach it. */
    .dropzone-input{position:absolute; width:1px; height:1px; opacity:0; pointer-events:none}
    .dropzone-lead{margin:0; font-size:.92rem; color:var(--ink,#1f2733)}
    .dropzone-sub{margin:.3rem 0 0; font-size:.78rem; color:var(--muted,#64748b)}
    .dropzone-browse{background:none; border:none; padding:0; font:inherit; cursor:pointer;
        color:var(--brand,#2f5bea); font-weight:600; text-decoration:underline}
    .dropzone-input:focus-visible + .dropzone-lead .dropzone-browse,
    .dropzone-browse:focus-visible{outline:2px solid var(--brand,#2f5bea); outline-offset:3px}

    .file-list{list-style:none; margin:.7rem 0 0; padding:0; display:flex;
        flex-direction:column; gap:.4rem}
    .file-row{display:flex; align-items:center; gap:.7rem; padding:.5rem .7rem;
        border:1px solid var(--line,#e7ebf1); border-radius:10px; background:#fff}
    .file-row.is-too-big{border-color:#f6c9cf; background:var(--danger-bg,#fde7ea)}
    .file-name{flex:1 1 auto; min-width:0; font-size:.88rem; overflow:hidden;
        text-overflow:ellipsis; white-space:nowrap}
    .file-size{flex:0 0 auto; font-size:.78rem; color:var(--muted,#64748b)}
    .file-row.is-too-big .file-size{color:var(--danger-ink,#a31726); font-weight:600}
    .file-remove{flex:0 0 auto; width:26px; height:26px; border-radius:50%;
        border:1px solid var(--line,#e7ebf1); background:#fff; cursor:pointer;
        font-size:1.05rem; line-height:1; color:var(--muted,#64748b)}
    .file-remove:hover{background:var(--danger-bg,#fde7ea); color:var(--danger-ink,#a31726);
        border-color:#f6c9cf}
    .file-note{margin:.55rem 0 0; font-size:.82rem; color:var(--danger-ink,#a31726)}

    /* ---- "This happened again" ---- */
    .follows-up{background:var(--warn-bg,#fff4d6); border:1px solid #f3dca0; color:#7a5200;
        border-radius:12px; padding:.9rem 1.1rem; margin-bottom:1.4rem; font-size:.9rem}
    .follows-up strong{display:block; margin-bottom:.25rem}
    .follows-up p{margin:0; line-height:1.55}
    .follows-up a{color:inherit; font-weight:700}

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
                {{-- The page existed with no way to reach it but typing the
                     address. System Admin only: adding a kind of concern
                     decides where every future one of them is routed, and
                     that is a decision for the person who answers for the
                     system rather than for whoever is covering the desk. --}}
                @if (Auth::user()->hasAnyRole(['System Admin']))
                    <a href="{{ route('admin.categories') }}" class="{{ request()->routeIs('admin.categories') ? 'active' : '' }}">Categories</a>
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
        {{-- Something wrong with the site itself.
             Signed-in only: the form it opens is behind auth, and offering a
             guest a link that bounces them to the sign-in page is no help to
             somebody whose problem IS the sign-in page. They have no account
             to file from either -- that one goes to the ICT Unit in person.

             The category rides in the query string because the filing form no
             longer offers it; see Concern::WEBSITE_FAULT. --}}
        @auth
            <a href="{{ route('concerns.create', ['category' => \App\Models\Concern::WEBSITE_FAULT]) }}"
               class="footer-link footer-bug"
               title="Report a problem with this website">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                    <path d="m8 2 1.88 1.88"/>
                    <path d="M14.12 3.88 16 2"/>
                    <path d="M9 7.13v-1a3.003 3.003 0 1 1 6 0v1"/>
                    <path d="M12 20c-3.3 0-6-2.7-6-6v-3a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v3c0 3.3-2.7 6-6 6"/>
                    <path d="M12 20v-9"/>
                    <path d="M6.53 9C4.6 8.8 3 7.1 3 5"/>
                    <path d="M6 13H2"/>
                    <path d="M3 21c0-2.1 1.7-3.9 3.8-4"/>
                    <path d="M20.97 5c0 2.1-1.6 3.8-3.5 4"/>
                    <path d="M22 13h-4"/>
                    <path d="M17.2 17c2.1.1 3.8 1.9 3.8 4"/>
                </svg>
                <span>Report a site problem</span>
            </a>
            <span class="footer-sep">|</span>

            {{-- The box the link opens.
                 It is a <dialog>, so the browser handles the backdrop, the
                 focus trap and Escape without a line of script. The link
                 above still points at the filing form: if the script below
                 never runs, clicking it goes there instead of doing
                 nothing, which is the whole reason it is an anchor and not
                 a button. --}}
            <dialog id="bug-box" class="bug-box">
                <form method="POST" action="{{ route('concerns.bug') }}">
                    @csrf
                    <h2>Report a problem with this site</h2>
                    <p>
                        Something broken, missing or wrong on the page? Tell the ICT
                        team what happened. The page you are on is sent with it.
                    </p>

                    <label for="bug-message">What went wrong? <span class="req">*</span></label>
                    <textarea id="bug-message" name="message" rows="5" required
                              minlength="10" maxlength="2000"
                              placeholder="I clicked Submit and nothing happened."></textarea>

                    <input type="hidden" name="page" id="bug-page" value="">

                    <div class="bug-box-actions">
                        <button type="button" class="btn btn-muted" data-bug-close>Cancel</button>
                        <button type="submit" class="btn btn-primary">Send report</button>
                    </div>
                </form>
            </dialog>
        @endauth
        © {{ date('Y') }} Camarines Sur Polytechnic Colleges. All Rights Reserved.
    </footer>

    <script>
        // Long text, folded to ten lines with a way back.
        //
        // The button is drawn only where the text really is too long --
        // measured, not guessed from a character count, because ten lines
        // of narrow phone column and ten lines of desktop are different
        // numbers of characters and a guess is wrong on one of them.
        (function () {
            var blocks = document.querySelectorAll('.clamp');

            Array.prototype.forEach.call(blocks, function (block) {
                var toggle = document.querySelector('[data-clamp="' + block.id + '"]');
                if (!toggle) return;

                // Fold first, then ask whether it made any difference.
                block.classList.add('is-folded');

                // scrollHeight is the full text; clientHeight is the ten
                // lines on screen. A couple of pixels of rounding is not
                // overflow, hence the margin.
                if (block.scrollHeight - block.clientHeight < 4) {
                    // Short enough to stand as it is. Unfold it again and
                    // leave no button: "Show more" over four lines of text
                    // is a button that lies.
                    block.classList.remove('is-folded');
                    return;
                }

                toggle.classList.add('is-needed');
                toggle.textContent = 'Show more';
                toggle.setAttribute('aria-expanded', 'false');

                toggle.addEventListener('click', function () {
                    var folded = block.classList.toggle('is-folded');
                    toggle.textContent = folded ? 'Show more' : 'Show less';
                    toggle.setAttribute('aria-expanded', folded ? 'false' : 'true');
                });
            });
        })();

        // Lists long enough to bury what is under them.
        //
        // Five entries is about as much as anyone reads at a glance; past
        // that it is history, and history goes behind a button. The rest
        // are RENDERED and hidden rather than left out, so Ctrl+F still
        // finds them and opening them needs no second trip.
        //
        // One loop for the activity timeline and both sets of handling
        // notes. Each was growing its own copy of this.
        (function () {
            var lists = document.querySelectorAll('.foldable');

            Array.prototype.forEach.call(lists, function (list) {
                var rest = list.querySelectorAll('.fold-rest');

                // Long enough to be worth setting tightly, whether or not
                // anything is hidden.
                if (Number(list.dataset.total) >= Number(list.dataset.fold || 5)) {
                    list.classList.add('is-compact');
                }

                if (!rest.length) return;

                var toggle = document.querySelector('[data-fold-toggle="' + list.id + '"]');
                if (!toggle) return;

                // What the thing in this list is called, so the button can
                // say "Show all 9 steps" rather than "Show all 9 items".
                var noun = list.dataset.noun || 'entries';
                var more = 'Show all ' + list.dataset.total + ' ' + noun;
                var less = 'Show fewer';

                list.classList.add('is-folded');
                toggle.classList.add('is-needed');
                toggle.textContent = more;
                toggle.setAttribute('aria-expanded', 'false');

                toggle.addEventListener('click', function () {
                    var folded = list.classList.toggle('is-folded');
                    toggle.textContent = folded ? more : less;
                    toggle.setAttribute('aria-expanded', folded ? 'false' : 'true');
                });
            });
        })();

        // Arriving from a notification, at the thing it was about.
        //
        // The browser's own fragment jump is not enough here for two
        // reasons. The target may be inside a folded list, where it is
        // display:none and the jump silently does nothing -- the reader
        // lands at the top of the page and concludes the notice lied.
        // And an element scrolled to without being marked is just the
        // page, somewhere in the middle of it.
        //
        // Runs after the fold script above, which is what makes the
        // unfolding work: by now every list knows whether it is folded.
        (function () {
            var hash = window.location.hash;
            if (!hash || hash.length < 2) return;

            var target;

            // A hash is attacker-supplied text. querySelector on it
            // throws on anything that is not a valid selector, which
            // would stop every script after this one.
            try {
                target = document.getElementById(decodeURIComponent(hash.slice(1)));
            } catch (e) { return; }

            if (!target) return;

            // Open anything hiding it: a folded list, or a closed
            // <details> if one ever wraps it.
            var node = target;

            while (node && node !== document.body) {
                if (node.classList && node.classList.contains('foldable')) {
                    node.classList.remove('is-folded');

                    var toggle = document.querySelector('[data-fold-toggle="' + node.id + '"]');
                    if (toggle) {
                        toggle.textContent = 'Show fewer';
                        toggle.setAttribute('aria-expanded', 'true');
                    }
                }

                if (node.tagName === 'DETAILS') node.open = true;

                node = node.parentNode;
            }

            target.classList.add('is-flagged');

            // After the unfolding, or the browser measures a position that
            // is about to change.
            requestAnimationFrame(function () {
                target.scrollIntoView({
                    block: 'center',
                    behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches
                        ? 'auto'
                        : 'smooth',
                });
            });
        })();

        // The bug box.
        //
        // The footer link is an anchor pointing at the filing form, and
        // stays one: if this script never runs, or the browser has no
        // <dialog>, the click goes to the form and the fault still gets
        // reported. Only where the box will actually open is the default
        // navigation taken away.
        (function () {
            var link = document.querySelector('.footer-bug');
            var box = document.getElementById('bug-box');

            if (!link || !box || typeof box.showModal !== 'function') return;

            link.addEventListener('click', function (e) {
                e.preventDefault();

                // The page they are on, captured rather than asked for:
                // it is the most useful line in a bug report and the one
                // nobody thinks to write. The server keeps the path and
                // drops the query string.
                var page = document.getElementById('bug-page');
                if (page) page.value = window.location.pathname;

                box.showModal();
            });

            var cancel = box.querySelector('[data-bug-close]');
            if (cancel) {
                cancel.addEventListener('click', function () { box.close(); });
            }

            // A click on the backdrop closes it too. The dialog element
            // itself fills only the panel, so a click landing ON the
            // dialog but outside that rectangle is a backdrop click.
            box.addEventListener('click', function (e) {
                var r = box.getBoundingClientRect();
                var inside = e.clientX >= r.left && e.clientX <= r.right
                    && e.clientY >= r.top && e.clientY <= r.bottom;
                if (!inside) box.close();
            });
        })();

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

    {{-- Anything that floats over the page rather than sitting in it.

         It has to be emitted HERE, as a direct child of <body>, and not
         inside the content. A fixed element is positioned against the
         viewport only while no ancestor has a transform, a filter or
         containment on it; inside the page's card it was being measured
         against that card instead, so it scrolled away with the content
         and was clipped at the edge. --}}
    @stack('floating')
</body>
</html>