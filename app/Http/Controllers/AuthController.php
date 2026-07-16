<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Laravel\Socialite\Facades\Socialite;

/**
 * Every way in and out of the app: email/password login, the Google
 * "CSPC Mail" sign-in, self-registration, and the forgot/reset password
 * flow. Both login paths run through rejectIfNotApproved(), so a pending
 * or rejected account can never sign in. Note that Google sign-in never
 * creates accounts -- it only matches existing ones by email.
 */
class AuthController extends Controller
{
    /** Email domains accepted for registration. */
    private const CSPC_EMAIL_DOMAINS = ['cspc.edu', 'my.cspc.edu.ph'];

    /**
     * Show the login form.
     */
    public function showLogin()
    {
        if (Auth::check()) {
            return redirect()->route('concerns.index');
        }
        return view('auth.login');
    }

    /**
     * Handle login request.
     */
    public function login(Request $request)
    {
        // No minimum length at LOGIN time -- the stored hash decides. A length
        // rule here would lock out accounts created under older, shorter
        // password policies without improving security.
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            if ($blocked = $this->rejectIfNotApproved(Auth::user())) {
                return $blocked;
            }

            $request->session()->regenerate();
            return redirect()->route('concerns.index')->with('success', 'Logged in successfully!');
        }

        return back()->withErrors([
            'email' => 'The provided credentials do not match our records.',
        ])->onlyInput('email');
    }

    /**
     * Handle logout.
     */
    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('login')->with('success', 'Logged out successfully!');
    }

    /**
     * Redirect to Google's OAuth consent screen.
     */
    public function redirectToGoogle()
    {
        return Socialite::driver('google')->redirect();
    }

    /**
     * Handle the callback from Google. Only pre-provisioned accounts
     * (matched by email) may sign in this way — there is no self-registration.
     */
    public function handleGoogleCallback()
    {
        // A cancelled consent screen, expired state token, or Google outage
        // all surface here as exceptions -- turn them into a friendly retry
        // message instead of a 500 page.
        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (\Throwable $e) {
            // Log the real failure before showing the generic message --
            // otherwise problems like a missing CA bundle (cURL SSL failure
            // during the token exchange) are impossible to diagnose.
            \Illuminate\Support\Facades\Log::error('Google OAuth callback failed', [
                'class' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            return redirect()->route('login')->withErrors([
                'email' => 'Google sign-in was cancelled or could not be completed. Please try again.',
            ]);
        }

        $user = User::where('email', $googleUser->getEmail())->first();

        if (! $user) {
            return redirect()->route('login')->withErrors([
                'email' => 'No CSPC account found for this Google account. Contact the admin to get access.',
            ]);
        }

        if (! $user->google_id) {
            $user->forceFill(['google_id' => $googleUser->getId()])->save();
        }

        Auth::login($user, true);

        if ($blocked = $this->rejectIfNotApproved($user)) {
            return $blocked;
        }

        request()->session()->regenerate();

        return redirect()->route('concerns.index')->with('success', 'Logged in successfully!');
    }

    /**
     * If the account isn't approved yet, log it back out and bounce to the
     * login page with an explanatory error. Returns null when the account is fine.
     */
    private function rejectIfNotApproved(User $user): ?RedirectResponse
    {
        if ($user->status === 'approved') {
            return null;
        }

        Auth::logout();

        $message = $user->status === 'pending'
            ? 'Your account is pending admin approval. Please check back later.'
            : 'Your account access was not approved. Contact the admin for details.';

        return redirect()->route('login')->withErrors(['email' => $message]);
    }

    /**
     * Show the registration form.
     */
    public function showRegister()
    {
        if (Auth::check()) {
            return redirect()->route('concerns.index');
        }

        return view('auth.register', ['roles' => Role::orderBy('name')->get()]);
    }

    /**
     * Handle a self-registration request. Students are approved immediately;
     * every other role starts 'pending' and needs an Admin's sign-off (see AdminController)
     * since those roles can access or handle concern reports.
     */
    public function register(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => [
                'required', 'email', 'max:255', 'unique:users,email',
                function ($attribute, $value, $fail) {
                    $domain = strtolower(substr(strrchr($value, '@'), 1));
                    if (! in_array($domain, self::CSPC_EMAIL_DOMAINS, true)) {
                        $fail('Please register with your official CSPC email address (@cspc.edu or @my.cspc.edu.ph).');
                    }
                },
            ],
            'password' => 'required|string|min:8|confirmed',
            'role_id' => 'required|exists:roles,id',
            'department' => 'nullable|string|max:255',
        ]);

        $role = Role::find($validated['role_id']);
        $isStudent = $role && $role->name === 'Student';

        User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role_id' => $validated['role_id'],
            'department' => $validated['department'] ?? null,
            'status' => $isStudent ? 'approved' : 'pending',
        ]);

        $message = $isStudent
            ? 'Registration complete! You can now sign in.'
            : 'Registration submitted! Your account is pending admin approval — you\'ll be able to sign in once it\'s reviewed.';

        return redirect()->route('login')->with('success', $message);
    }

    /**
     * Show the forgot-password request form.
     */
    public function showForgotPassword()
    {
        return view('auth.forgot-password');
    }

    /**
     * Send a password reset link to the given email.
     */
    public function sendResetLink(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $status = Password::sendResetLink($request->only('email'));

        return $status === Password::RESET_LINK_SENT
            ? back()->with('success', __($status))
            : back()->withErrors(['email' => __($status)]);
    }

    /**
     * Show the reset-password form.
     */
    public function showResetPassword(Request $request, string $token)
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => $request->query('email'),
        ]);
    }

    /**
     * Reset the user's password.
     */
    public function resetPassword(Request $request)
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill(['password' => Hash::make($password)])->save();
            }
        );

        return $status === Password::PASSWORD_RESET
            ? redirect()->route('login')->with('success', 'Password reset successfully! You can now sign in.')
            : back()->withErrors(['email' => __($status)]);
    }
}
