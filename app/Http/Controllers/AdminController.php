<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Admin approval of self-registered accounts. Non-Student registrations
 * start as status='pending' (see AuthController::register) and can't log
 * in until approved here.
 */
class AdminController extends Controller
{
    /**
     * List accounts awaiting approval.
     */
    public function index()
    {
        $this->authorizeAdmin();

        $pendingUsers = User::where('status', 'pending')
            ->with('role')
            ->latest()
            ->get();

        return view('admin.pending-users', ['pendingUsers' => $pendingUsers]);
    }

    /**
     * Approve a pending account.
     */
    public function approve(User $user)
    {
        $this->authorizeAdmin();
        $this->ensurePending($user);

        $user->update([
            'status' => 'approved',
            'approved_by' => Auth::id(),
            'approved_at' => now(),
        ]);

        return back()->with('success', "{$user->name}'s account has been approved.");
    }

    /**
     * Reject a pending account.
     */
    public function reject(User $user)
    {
        $this->authorizeAdmin();
        $this->ensurePending($user);

        $user->update([
            'status' => 'rejected',
            'approved_by' => Auth::id(),
            'approved_at' => now(),
        ]);

        return back()->with('success', "{$user->name}'s account has been rejected.");
    }

    /**
     * Approval decisions only apply to accounts that are still pending.
     * Without this guard, "reject" could disable any ACTIVE account --
     * including the Head of School or another Admin -- via a direct POST.
     */
    private function ensurePending(User $user): void
    {
        if ($user->status !== 'pending') {
            abort(422, 'This account is not pending approval.');
        }
    }

    /**
     * Only Admins may manage account approvals.
     */
    private function authorizeAdmin(): void
    {
        $user = Auth::user();

        if (! $user->role || $user->role->name !== 'Admin') {
            abort(403, 'Only an Admin can manage account approvals.');
        }
    }
}
