@extends('layout')

@section('title', 'Privacy & Confidentiality Policy')

@section('content')
{{-- Rewritten September 2026 to match what the system actually does. The page
     still described anonymous submission, which was removed; promised that only
     the assigned handler could read a concern, when each office sees its own
     categories and the teaching tiers share an open queue; sent facility cases
     to the Administration rather than General Services; escalated a concern
     about a Dean sideways to another Dean rather than up to the VPAA; and said
     nothing about the class adviser, the emails the system sends, or what
     deleting an account takes with it. A policy that overstates privacy is
     worse than none: a student decides what to write based on it. --}}
<div class="card" style="max-width: 860px; margin: 0 auto; line-height: 1.7;">
    <h1 style="margin-bottom: 0.3rem;">Data Privacy &amp; Confidentiality Policy</h1>
    <p style="color:#64748b; margin-bottom: 1.5rem;">Student Concern Reporting System · Camarines Sur Polytechnic Colleges</p>

    <div style="background:#eef2ff; border:1px solid #dbe2ff; border-radius:12px; padding:1rem 1.25rem; margin-bottom:1.75rem;">
        <strong>In short:</strong> You file a concern under your own name, not anonymously. It is read by the staff
        who handle that kind of concern &mdash; never by the person your concern is about &mdash; and every action
        taken on it is recorded and shown back to you. Report in good faith without fear of retaliation.
    </div>

    <h3 style="margin:1.4rem 0 .5rem;">1. Purpose</h3>
    <p>This policy explains how student concerns are received, handled, and protected. It balances two duties:
    protecting the privacy and safety of students who raise sensitive issues, and maintaining accountability so that
    false or malicious reports can be investigated. It applies to everyone who uses the system, and especially to
    those with privileged access.</p>

    <h3 style="margin:1.4rem 0 .5rem;">2. Signing In &amp; Your Identity</h3>
    <p>You sign in with your <strong>CSPC Mail account</strong> &mdash; Google is the only way in, and the system
    stores no password you could lose or have guessed. Your account is created the first time you sign in, and access
    ends the moment CSPC disables your school account.</p>
    <p>Concerns are submitted <strong>under your name</strong>. This system does not offer anonymous submission.
    Being able to identify a reporter is what allows staff to follow up with you, and allows false or malicious
    reports to be investigated fairly.</p>

    <h3 style="margin:1.4rem 0 .5rem;">3. Who Can Read Your Concern</h3>
    <p>Access follows the concern, not rank. Nobody sees a case simply because they are senior, and your report is
    never shown to the wider faculty:</p>
    <ul style="margin:.5rem 0 .5rem 1.2rem;">
        <li><strong>The staff member it is assigned to</strong> &mdash; always, from the moment it is routed to them.</li>
        <li><strong>The office that handles that kind of concern.</strong> The Guidance Office sees counselling
            cases (Mental Health, Personal, Bullying, Harassment). The General Services Unit sees Facilities and
            Equipment. The System Admin sees reports of faults in the system itself. None of them sees the
            others' categories.</li>
        <li><strong>Class advisers, Program Chairs and Deans share one open queue</strong> for newly submitted
            Academic, Physical, Safety and Others concerns, so a case is picked up even when the first handler is
            away. Once it is assigned, it belongs to the person handling it.</li>
        <li><strong>The Head of School</strong> can read concern content in order to adjudicate escalations and
            suspected false reports &mdash; except any concern filed about them, which they are walled off from.</li>
        <li><strong>The person your concern is about</strong> can never read it, whatever their rank (Section 5).</li>
    </ul>

    <h3 style="margin:1.4rem 0 .5rem;">4. Where Your Concern Goes</h3>
    <p>The category you choose decides which office receives it, and your programme and section decide which person
    inside that office:</p>
    <ul style="margin:.5rem 0 .5rem 1.2rem;">
        <li><strong>Academic, Physical, Safety, Others</strong> &mdash; your own <strong>class adviser</strong>
            first. If your class has no adviser on record yet, an instructor of your college receives it instead.</li>
        <li><strong>Mental Health, Personal, Bullying, Harassment</strong> &mdash; the <strong>Guidance Office</strong>.</li>
        <li><strong>System Problem</strong> &mdash; a fault in this website goes to the <strong>System
            Admin</strong>, who maintain it.</li>
        <li><strong>Facilities, Equipment</strong> &mdash; the <strong>General Services Unit</strong>.</li>
    </ul>
    <p>You may tick <strong>&ldquo;I do not want this to go to my class adviser&rdquo;</strong> on the form. You do
    not have to give a reason, and it does not accuse anyone: the concern goes to the <strong>Program Chair</strong>
    of your programme instead.</p>
    <p>Staff can hand a concern on to another office or a named person when it is not theirs to settle. A referral is
    recorded on the concern and shown to you in its timeline.</p>

    <h3 style="margin:1.4rem 0 .5rem;">5. Conflict of Interest</h3>
    <p>A concern is never handled by the person it is about. If you name a staff member, the system routes it
    <strong>away</strong> from that person and up to somebody with standing over them:</p>
    <ul style="margin:.5rem 0 .5rem 1.2rem;">
        <li>A concern about your <strong>class adviser</strong> goes to the <strong>Program Chair</strong>.</li>
        <li>A concern about a <strong>Program Chair</strong> goes to the <strong>Dean</strong> of that college.</li>
        <li>A concern about a <strong>Dean</strong> goes to the <strong>VPAA</strong> &mdash; never to another Dean,
            who is a peer rather than a reviewer.</li>
        <li>A concern about the <strong>administration</strong> goes above it, not to a colleague at the next desk.</li>
    </ul>
    <p>The reported person cannot view, download, or act on a concern about themselves through any part of the
    system, and cannot have it referred back to them. This protects you from retaliation.</p>

    <h3 style="margin:1.4rem 0 .5rem;">6. Emails the System Sends</h3>
    <p>The system emails CSPC addresses when a concern is <strong>assigned</strong> to a staff member, and when its
    <strong>status changes</strong>, so nobody has to keep checking the website. Those emails deliberately carry
    <strong>only the concern number and its status</strong> &mdash; never the category, the description, your name, or
    any evidence. Inboxes get forwarded and phones show previews on lock screens; the details stay behind sign-in.</p>

    <h3 style="margin:1.4rem 0 .5rem;">7. Audit Trail</h3>
    <p>Every action taken on your concern is recorded &mdash; who did it, what changed, when, and from which address.
    That record is shown to you on your own concern page as an Activity Timeline, so you can see exactly how your
    report was handled:</p>
    <ul style="margin:.5rem 0 .5rem 1.2rem;">
        <li>Submission, and the urgency the system assigned it.</li>
        <li>Every status change, referral, and hand-off between staff.</li>
        <li>Every edit to investigation or resolution notes.</li>
    </ul>
    <p>Staff handling your case cannot edit or quietly remove that trail. An administrator deleting a concern or an
    account removes that record with it (Section 9), which is itself a privileged action governed by this policy.</p>

    <h3 style="margin:1.4rem 0 .5rem;">8. Evidence Attachments</h3>
    <p>If you attach evidence (such as a screenshot or document), those files are treated as strictly confidential.
    They are stored privately, never in a publicly reachable location, and can only be opened by the same people
    authorized to view the concern itself. The reported person cannot access your evidence, even with a direct link.
    Attaching evidence is always <strong>optional</strong>.</p>

    <h3 style="margin:1.4rem 0 .5rem;">9. Keeping &amp; Removing Information</h3>
    <p>Concerns are kept so that they can be followed up, reviewed, and counted for institutional reporting. Counts
    used for reporting &mdash; how many concerns of each kind, how long they took &mdash; contain no names.</p>
    <p>An administrator can delete an account. The account stops working at once, and the concerns that person filed
    are hidden from everyone &mdash; but they are kept, so that a deletion made by mistake can be undone. Signing in
    again restores the account and everything filed under it. An administrator can also erase a deleted account for
    good, which removes the concerns and the uploaded files with it and cannot be undone.</p>
    <p>At the end of a school year, final-year student accounts are closed as graduated, which ends their access. A
    student still enrolled can ask an administrator to reopen their account.</p>

    <h3 style="margin:1.4rem 0 .5rem;">10. Obligations of Privileged Users</h3>
    <p>Anyone with privileged access &mdash; the Head of School, Deans, and administrators &mdash; must not disclose
    a student's identity or confidential information to anyone not authorized to receive it, must not open a concern
    out of curiosity, must record a truthful reason for any privileged action the system asks them to justify, and
    must treat all concern content and evidence as confidential, including after a concern is resolved.</p>

    <h3 style="margin:1.4rem 0 .5rem;">11. Prohibited Conduct &amp; Penalties</h3>
    <p>The following are strictly prohibited and subject to disciplinary action under the institution's rules and
    applicable law (including the Data Privacy Act):</p>
    <ul style="margin:.5rem 0 .5rem 1.2rem;">
        <li>Disclosing or leaking a student's identity or confidential concern information without authorization.</li>
        <li>Opening, copying, or forwarding a concern you are not handling.</li>
        <li>Retaliating against a student for reporting in good faith.</li>
        <li>Submitting knowingly false or malicious reports.</li>
    </ul>
    <p>The system's audit log serves as evidence in any investigation of misuse.</p>

    <h3 style="margin:1.4rem 0 .5rem;">12. Good-Faith Reporting</h3>
    <p>A student who reports in good faith &mdash; even if the concern is ultimately not substantiated &mdash; is
    protected under this policy and must not face retaliation. The purpose of this system is to make it safe to raise
    sensitive issues involving staff, classmates, or other personnel.</p>

    <hr style="margin:1.75rem 0 1rem; border:none; border-top:1px solid #e2e8f0;">
    <p style="color:#64748b; font-size:.9rem; font-style:italic;">This policy is enforced partly by technical controls
    in the system (conflict-of-interest routing, least-privilege access, secure evidence handling, and a permanent
    audit trail) and partly by institutional governance. Technology limits who <em>can</em>
    access information; this policy governs whether they <em>should</em>.</p>

    {{-- The agreement, shown once.
         A signed-in person who has not yet agreed meets this on their first
         visit and never again, unless the policy is rewritten -- which makes
         it a different promise, so everybody is asked afresh. A notice shown
         at every sign-in is a door people push through without reading, and
         the agreement it collects is worth nothing. --}}
    @auth
        @if (! Auth::user()->hasAcceptedPolicy())
            <div style="margin-top:1.75rem; background:#eef2ff; border:1px solid #dbe2ff; border-radius:12px; padding:1.25rem;">
                <p style="margin:0 0 0.9rem; font-weight:600;">Before you use the system</p>
                <p style="margin:0 0 1.1rem; color:#475569; font-size:0.92rem;">
                    Please confirm you have read this policy. It explains who reads your concern, where
                    it goes, and what the system keeps. You will only be asked once.
                </p>
                <form action="{{ route('policy.accept') }}" method="POST" style="margin:0;">
                    @csrf
                    <button type="submit" class="btn btn-primary">I have read and accept this policy</button>
                </form>
            </div>
        @else
            <div style="margin-top:1.5rem;">
                <p style="color:#64748b; font-size:0.85rem; margin-bottom:0.75rem;">
                    You accepted this policy on
                    {{ Auth::user()->policy_accepted_at->local()->format('M d, Y') }}.
                </p>
                <a href="{{ route('concerns.create') }}" class="btn btn-primary">Submit a Concern</a>
            </div>
        @endif
    @else
        <div style="margin-top:1.5rem;">
            <a href="{{ route('login') }}" class="btn btn-primary">Sign in to Report</a>
        </div>
    @endauth
</div>
@endsection
