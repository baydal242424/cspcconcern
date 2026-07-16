@extends('layout')

@section('title', 'Privacy & Confidentiality Policy')

@section('content')
<div class="card" style="max-width: 860px; margin: 0 auto; line-height: 1.7;">
    <h1 style="margin-bottom: 0.3rem;">Data Privacy &amp; Confidentiality Policy</h1>
    <p style="color:#64748b; margin-bottom: 1.5rem;">Student Concern Reporting System · Camarines Sur Polytechnic Colleges</p>

    <div style="background:#eef2ff; border:1px solid #dbe2ff; border-radius:12px; padding:1rem 1.25rem; margin-bottom:1.75rem;">
        <strong>In short:</strong> You can report a concern without your name being shown to the staff who handle it.
        Your identity is stored securely and can only be revealed by the Head of School, through a deliberate,
        logged action, and only when there is a serious justification. Report in good faith without fear of retaliation.
    </div>

    <h3 style="margin:1.4rem 0 .5rem;">1. Purpose</h3>
    <p>This policy explains how student concerns are received, handled, and protected. It balances two duties:
    protecting the privacy and safety of students who raise sensitive issues, and maintaining accountability so that
    false or malicious reports can be investigated. It applies to everyone who uses the system, and especially to
    those with privileged access.</p>

    <h3 style="margin:1.4rem 0 .5rem;">2. Anonymity &amp; Identity Protection</h3>
    <p>When you submit a concern anonymously, the staff member handling it sees the content and a case reference,
    but <strong>not your name</strong>. Your identity remains stored securely and is never shown to handlers during
    normal operation. No system can promise absolute anonymity while also preserving accountability, because your
    identity must be stored for false reports to be investigated. What this system guarantees is that your identity
    is <strong>hidden by default</strong>, and that any disclosure is restricted, deliberate, and permanently logged.</p>

    <h3 style="margin:1.4rem 0 .5rem;">3. Who Handles Concerns (Separation of Powers)</h3>
    <p>The power to <em>handle</em> a concern is kept separate from the power to <em>reveal</em> a reporter's identity:</p>
    <ul style="margin:.5rem 0 .5rem 1.2rem;">
        <li><strong>Faculty / Staff &amp; Counselors</strong> handle and resolve concerns in their area. They see content, never the identity of an anonymous reporter.</li>
        <li><strong>Department Heads</strong> receive referrals and escalations. Where a concern involves a Department Head, it is escalated to a different (peer) authority so that no one ever handles a case about themselves.</li>
        <li><strong>The Head of School</strong> holds only one special power: the ability to reveal an anonymous reporter's identity (see Section 5). This is rare and exceptional, not part of day-to-day handling.</li>
    </ul>

    <h3 style="margin:1.4rem 0 .5rem;">4. Conflict of Interest</h3>
    <p>A concern is never handled by the person it is about. If you indicate that your concern is about a specific
    staff member, the system automatically routes it <strong>away</strong> from that person and escalates it to a
    higher authority. The reported person cannot view, download, or act on a concern about themselves through any
    part of the system &mdash; this protects you from retaliation.</p>

    <h3 style="margin:1.4rem 0 .5rem;">5. Revealing an Identity (Break-Glass)</h3>
    <p>Only the <strong>Head of School</strong> may reveal the identity of a student who submitted anonymously,
    subject to strict controls:</p>
    <ul style="margin:.5rem 0 .5rem 1.2rem;">
        <li>A written reason is <strong>required</strong> before any reveal.</li>
        <li>The reveal is <strong>permanently logged</strong> &mdash; who revealed it, when, and why.</li>
        <li>Revealing an identity does <strong>not</strong> expose it to the staff handling the concern.</li>
        <li>A reveal is justified only in limited situations, such as a credible safety risk, or a documented investigation of a suspected false or malicious report.</li>
    </ul>

    <h3 style="margin:1.4rem 0 .5rem;">6. Evidence Attachments</h3>
    <p>If you attach evidence (such as a screenshot or document), those files are treated as strictly confidential.
    They are stored privately, never in a publicly reachable location, and can only be opened by the same people
    authorized to view the concern itself. The reported person cannot access your evidence, even with a direct link.
    Attaching evidence is always <strong>optional</strong>.</p>

    <h3 style="margin:1.4rem 0 .5rem;">7. Obligations of Privileged Users</h3>
    <p>Anyone with privileged access &mdash; particularly the Head of School &mdash; must not disclose a student's
    identity or confidential information to anyone not authorized to receive it, must not access identity information
    out of curiosity, must record a truthful reason for every reveal, and must treat all concern content and evidence
    as confidential, including after a concern is resolved.</p>

    <h3 style="margin:1.4rem 0 .5rem;">8. Prohibited Conduct &amp; Penalties</h3>
    <p>The following are strictly prohibited and subject to disciplinary action under the institution's rules and
    applicable law (including the Data Privacy Act):</p>
    <ul style="margin:.5rem 0 .5rem 1.2rem;">
        <li>Disclosing or leaking a student's identity or confidential concern information without authorization.</li>
        <li>Using the identity-reveal power without a legitimate reason, or recording a false reason.</li>
        <li>Retaliating against a student for reporting in good faith.</li>
        <li>Submitting knowingly false or malicious reports.</li>
    </ul>
    <p>The system's audit log serves as evidence in any investigation of misuse.</p>

    <h3 style="margin:1.4rem 0 .5rem;">9. Good-Faith Reporting</h3>
    <p>A student who reports in good faith &mdash; even if the concern is ultimately not substantiated &mdash; is
    protected under this policy and must not face retaliation. The purpose of this system is to make it safe to raise
    sensitive issues involving staff, classmates, or other personnel.</p>

    <hr style="margin:1.75rem 0 1rem; border:none; border-top:1px solid #e2e8f0;">
    <p style="color:#64748b; font-size:.9rem; font-style:italic;">This policy is enforced partly by technical controls
    in the system (anonymous submission, conflict-of-interest routing, least-privilege access, secure evidence
    handling, and logged identity reveals) and partly by institutional governance. Technology limits who <em>can</em>
    access information; this policy governs whether they <em>should</em>.</p>

    <div style="margin-top:1.5rem;">
        @auth
            <a href="{{ route('concerns.create') }}" class="btn btn-primary">Submit a Concern</a>
        @else
            <a href="{{ route('login') }}" class="btn btn-primary">Sign in to Report</a>
        @endauth
    </div>
</div>
@endsection