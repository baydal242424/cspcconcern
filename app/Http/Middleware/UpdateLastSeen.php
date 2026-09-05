<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stamps last_seen_at on every authenticated request (drives the admin
 * "online / last active" view) and enforces a ban immediately -- without
 * this, a banned user with an existing session would stay logged in until
 * it expired on its own instead of being cut off on their very next request.
 */
class UpdateLastSeen
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user && $user->status !== 'approved') {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'email' => match ($user->status) {
                    'banned' => 'Your account has been banned. Contact the admin for details.',
                    // Closed by the start-of-year promotion. An irregular
                    // student still finishing subjects looks exactly like a
                    // graduate here, so say how to get back rather than
                    // stopping at "not approved".
                    'graduated' => 'Your account was closed at the end of the school year. '
                        .'If you are still enrolled, ask the admin to reactivate it.',
                    default => 'Your account access was not approved. Contact the admin for details.',
                },
            ]);
        }

        if ($user) {
            $user->forceFill(['last_seen_at' => now()])->saveQuietly();
        }

        return $next($request);
    }
}
