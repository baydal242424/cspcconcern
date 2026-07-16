<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ConcernController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AdminController;

Route::get('/', function () {
    return redirect()->route('login');
});

// Authentication routes
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
//Brute-force protection
Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:5,1')
    ->name('login.post');
// Logout is a state change, so it must be a CSRF-protected POST -- a GET
// logout can be triggered by any third-party page or a link prefetcher.
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// Self-registration (creates a 'pending' account for non-Student roles;
// requires Admin approval before login works -- Students are auto-approved)
Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
Route::post('/register', [AuthController::class, 'register'])
    ->middleware('throttle:5,1')
    ->name('register.post');

// Google ("CSPC Mail") OAuth login
Route::get('/auth/google/redirect', [AuthController::class, 'redirectToGoogle'])->name('auth.google.redirect');
Route::get('/auth/google/callback', [AuthController::class, 'handleGoogleCallback'])->name('auth.google.callback');

// Forgot / reset password
Route::get('/forgot-password', [AuthController::class, 'showForgotPassword'])->name('password.request');
Route::post('/forgot-password', [AuthController::class, 'sendResetLink'])
    ->middleware('throttle:5,1')
    ->name('password.email');
Route::get('/reset-password/{token}', [AuthController::class, 'showResetPassword'])->name('password.reset');
Route::post('/reset-password', [AuthController::class, 'resetPassword'])->name('password.update');

// Public confidentiality policy page -- intentionally accessible to everyone
// (including guests) so students can read the privacy guarantees before they
// decide to submit a concern.
Route::view('/policy', 'policy')->name('policy');

// Protected routes
Route::middleware('auth')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::resource('concerns', ConcernController::class);
    // Break-glass: Head of School reveals a pseudonymous reporter's identity
    // (logged, reason required). Kept separate from the normal update flow.
    Route::post('concerns/{concern}/reveal-identity', [ConcernController::class, 'revealIdentity'])
        ->name('concerns.reveal');
    // Secure evidence download: authorization is checked inside the controller
    // (same rule as viewing the concern) before the file is streamed.
    Route::get('concerns/{concern}/attachments/{attachment}', [ConcernController::class, 'downloadAttachment'])
        ->name('concerns.attachment');

    // Admin: approve or reject pending self-registrations
    // (authorization is checked inside the controller, same pattern as ConcernController)
    Route::get('/admin/pending-users', [AdminController::class, 'index'])->name('admin.pending-users');
    Route::post('/admin/pending-users/{user}/approve', [AdminController::class, 'approve'])->name('admin.pending-users.approve');
    Route::post('/admin/pending-users/{user}/reject', [AdminController::class, 'reject'])->name('admin.pending-users.reject');
});