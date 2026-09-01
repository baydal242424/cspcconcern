<?php

namespace App\Http\Controllers;

use App\Models\Referral;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Admin view of every registered account: who they are, whether they're
 * currently online (see User::isOnline, backed by UpdateLastSeen), and the
 * ability to ban an account suspected of being fake or filing fraudulent
 * reports. A ban takes effect immediately, even mid-session (see
 * UpdateLastSeen middleware) -- it doesn't just block the next login.
 */
class AdminController extends Controller
{
    /**
     * List every registered account.
     */
    public function index()
    {
        $this->authorizeAdmin();

        $users = User::with(['role', 'bannedBy'])
            ->orderByDesc('last_seen_at')
            ->orderByDesc('created_at')
            ->get();

        // Colleges first, then the units and offices already in use. A
        // department is not a fixed list: colleges come from COURSES_BY_COLLEGE,
        // but office staff carry things like "Information and Communications
        // Technology Unit" that exist only as data. Offering only the colleges
        // would quietly wipe those the next time somebody's role was changed.
        $colleges = array_keys(User::COURSES_BY_COLLEGE);
        $otherUnits = User::query()
            ->whereNotNull('department')
            ->whereNotIn('department', $colleges)
            ->distinct()
            ->orderBy('department')
            ->pluck('department')
            ->all();

        return view('admin.users', [
            'users' => $users,
            'roles' => Role::orderBy('name')->get(),
            'colleges' => $colleges,
            'otherUnits' => $otherUnits,
            'courses' => User::COURSES_BY_COLLEGE,
        ]);
    }

    /**
     * Ban an account: blocks login and signs out any active session.
     */
    public function ban(Request $request, User $user)
    {
        $this->authorizeAdmin();

        if ($user->id === Auth::id()) {
            abort(422, 'You cannot ban your own account.');
        }

        $validated = $request->validate([
            'reason' => 'nullable|string|max:255',
        ]);

        $user->update([
            'status' => 'banned',
            'banned_by' => Auth::id(),
            'banned_at' => now(),
            'ban_reason' => $validated['reason'] ?? null,
        ]);

        return back()->with('success', "{$user->name}'s account has been banned.");
    }

    /**
     * Lift a ban and restore normal access.
     */
    public function unban(User $user)
    {
        $this->authorizeAdmin();

        $user->update([
            'status' => 'approved',
            'banned_by' => null,
            'banned_at' => null,
            'ban_reason' => null,
        ]);

        return back()->with('success', "{$user->name}'s account has been unbanned.");
    }

    /**
     * Change an account's role (e.g. a student moving to a staff position).
     */
    public function updateRole(Request $request, User $user)
    {
        $this->authorizeAdmin();

        if ($user->id === Auth::id()) {
            abort(422, 'You cannot change your own role.');
        }

        // Department is validated as free text against what is actually in use
        // rather than against a fixed list, because it holds two different
        // kinds of thing: the six colleges, and the units and offices that
        // exist only as data on other accounts.
        $validated = $request->validate([
            'role_id' => 'required|exists:roles,id',
            'department' => 'nullable|string|max:255',
            'course' => ['nullable', 'string', Rule::in(User::allCourses())],
        ]);

        $changes = ['role_id' => $validated['role_id']];

        // Only touch what was actually submitted. The admin form always sends
        // all three, but a role-only post -- from a script, or an older form --
        // would otherwise silently blank someone's college, and a handler with
        // no college is skipped by findHandler() without anything appearing to
        // be wrong.
        if ($request->has('department')) {
            $changes['department'] = $validated['department'] ?? null;
        }

        // Only programme-scoped accounts carry a course: a student's own, and
        // the programme a Program Chair covers. Storing one on anybody else
        // would make findHandler() prefer them for that programme's concerns,
        // which is a routing bug that is very hard to see.
        if ($request->has('course')) {
            $changes['course'] = $validated['course'] ?? null;
        }

        $user->update($changes);

        return back()->with('success', "{$user->name} has been updated.");
    }

    /**
     * Permanently delete an account. Referrals restrict deletion of either
     * party, so any referral this user sent or received is removed first;
     * everything else (their own concerns, attachments, audit entries,
     * notifications) cascades at the database level.
     */
    public function destroy(User $user)
    {
        $this->authorizeAdmin();

        if ($user->id === Auth::id()) {
            abort(422, 'You cannot delete your own account.');
        }

        $name = $user->name;

        DB::transaction(function () use ($user) {
            Referral::where('referred_by', $user->id)
                ->orWhere('referred_to', $user->id)
                ->delete();

            $user->delete();
        });

        return back()->with('success', "{$name}'s account and submitted concerns have been permanently deleted.");
    }

    /**
     * Only Admins may manage accounts.
     */
    private function authorizeAdmin(): void
    {
        $user = Auth::user();

        if (! $user->role || $user->role->name !== 'Admin') {
            abort(403, 'Only an Admin can manage accounts.');
        }
    }
}
