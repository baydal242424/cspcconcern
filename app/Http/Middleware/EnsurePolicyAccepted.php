<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Nobody uses the system before reading what it does with what they write.
 *
 * The policy page existed and nothing led anyone to it. A student filed a
 * concern without ever learning that it is read under their own name, that it
 * reaches an office rather than a single person, or that the person it is
 * about can never see it -- all of which change what somebody chooses to
 * write, and all of which they had to go looking for.
 *
 * Shown ONCE. The acceptance is recorded against the account, so a student
 * meets this on their first visit and never again -- unless the policy is
 * rewritten, which makes it a different promise and asks everybody afresh. A
 * notice shown at every sign-in is a door people push through without
 * reading, and the agreement it collects is worth nothing.
 *
 * It runs after the profile gate, so a new student completes their details
 * first and then reads the policy: two steps, each with one job, rather than
 * one screen asking for everything.
 */
class EnsurePolicyAccepted
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = Auth::user();

        if (! $user || $user->hasAcceptedPolicy()) {
            return $next($request);
        }

        // The policy page itself, the act of accepting it, and signing out.
        // Without the last one somebody who will not agree would be trapped
        // on the page with no way off it.
        if ($request->routeIs('policy') || $request->routeIs('policy.accept') || $request->routeIs('logout')) {
            return $next($request);
        }

        // GET only. A form post bounced to a page loses what was typed into
        // it, and the student is left looking at a policy wondering where
        // their concern went.
        if (! $request->isMethod('GET')) {
            return redirect()->route('policy')
                ->with('error', 'Please read and accept the policy before using the system.');
        }

        return redirect()->route('policy');
    }
}
