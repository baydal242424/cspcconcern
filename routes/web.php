<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ConcernController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\NotificationController;

Route::get('/', function () {
    return redirect()->route('login');
});

// Authentication. CSPC Mail (Google) is the ONLY way in -- for students AND
// for staff. There is no password form, no self-registration, and therefore
// no password-reset flow: the app stores no password anyone can use, so
// there is nothing to guess, leak, reuse or reset. Every account is provably
// tied to a real CSPC mailbox, and access is revoked institution-wide the
// moment CSPC disables someone's Google account.
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
// Logout is a state change, so it must be a CSRF-protected POST -- a GET
// logout can be triggered by any third-party page or a link prefetcher.
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// Google ("CSPC Mail") OAuth. The callback auto-provisions a Student account
// on first sign-in; /complete-profile then collects their student details.
// A staff member's role is assigned by an Admin, not by this flow.
// Demo sign-in. The controller aborts with a 404 unless DEMO_LOGIN_ENABLED is
// set, so the route existing is not the same as the route working.
Route::post('/auth/demo', [AuthController::class, 'demoLogin'])->name('auth.demo');

Route::get('/auth/google/redirect', [AuthController::class, 'redirectToGoogle'])->name('auth.google.redirect');
Route::get('/auth/google/callback', [AuthController::class, 'handleGoogleCallback'])->name('auth.google.callback');

// Public confidentiality policy page -- intentionally accessible to everyone
// (including guests) so students can read the privacy guarantees before they
// decide to submit a concern.
Route::view('/policy', 'policy')->name('policy');

// Protected routes. track.last_seen stamps last_seen_at for the admin
// online/last-active view and signs a user out immediately if banned.
// Students provisioned by CSPC Mail sign-in have no college/course yet. They
// must supply them before using the app, so this pair sits OUTSIDE the
// profile.complete middleware to avoid a redirect loop.
Route::middleware(['auth', 'track.last_seen'])->group(function () {
    Route::get('/complete-profile', [AuthController::class, 'showCompleteProfile'])->name('profile.complete');
    Route::post('/complete-profile', [AuthController::class, 'completeProfile'])->name('profile.complete.post');
});

Route::middleware(['auth', 'track.last_seen', 'profile.complete'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    // No 'edit' route: a concern is final once submitted. Staff still act on
    // one through 'update' (the status form on the show page).
    Route::resource('concerns', ConcernController::class)->except(['edit']);
    // Break-glass: Head of School reveals a pseudonymous reporter's identity
    // (logged, reason required). Kept separate from the normal update flow.
    Route::post('concerns/{concern}/reveal-identity', [ConcernController::class, 'revealIdentity'])
        ->name('concerns.reveal');
    // Secure evidence download: authorization is checked inside the controller
    // (same rule as viewing the concern) before the file is streamed.
    Route::get('concerns/{concern}/attachments/{attachment}', [ConcernController::class, 'downloadAttachment'])
        ->name('concerns.attachment');
    // Reporter rates a resolved concern (once, after resolution).
    Route::post('concerns/{concern}/feedback', [ConcernController::class, 'storeFeedback'])
        ->name('concerns.feedback');

    // Navbar notification bell. Both are POST because they change state --
    // a GET would let a link prefetcher silently clear someone's badge.
    // Ownership is checked inside the controller.
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'read'])
        ->name('notifications.read');
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])
        ->name('notifications.readAll');

    // Admin: view all accounts, ban/unban, change role, delete (authorization checked inside the controller)
    Route::get('/admin/users', [AdminController::class, 'index'])->name('admin.users');
    Route::post('/admin/users/{user}/ban', [AdminController::class, 'ban'])->name('admin.users.ban');
    Route::post('/admin/users/{user}/unban', [AdminController::class, 'unban'])->name('admin.users.unban');
    Route::post('/admin/users/{user}/role', [AdminController::class, 'updateRole'])->name('admin.users.role');
    Route::delete('/admin/users/{user}', [AdminController::class, 'destroy'])->name('admin.users.destroy');
});