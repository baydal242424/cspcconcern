<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Students who signed in with CSPC Mail never pass through the registration
 * form, so they arrive with no college, course, year level or section. A
 * concern's department is now taken from the reporter's account and drives
 * routeConcern(), so an incomplete profile means the concern cannot reach the
 * right college. This bounces such students to a one-time completion form.
 *
 * Only Students are affected -- staff accounts are created by an admin or the
 * seeder and carry a department already.
 */
class EnsureStudentProfileComplete
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = Auth::user();

        if ($user && $user->needsProfileCompletion() && ! $request->routeIs('profile.complete*')) {
            return redirect()->route('profile.complete');
        }

        return $next($request);
    }
}
