<?php

namespace App\Http\Controllers;

use App\Models\Referral;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
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

        return view('admin.users', ['users' => $users, 'roles' => Role::orderBy('name')->get()]);
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

        $validated = $request->validate([
            'role_id' => 'required|exists:roles,id',
        ]);

        $user->update(['role_id' => $validated['role_id']]);

        return back()->with('success', "{$user->name}'s role has been updated.");
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
