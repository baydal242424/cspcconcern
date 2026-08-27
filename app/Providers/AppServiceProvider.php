<?php

namespace App\Providers;

use App\Models\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Feed the navbar bell. Bound to the partial rather than to '*' so
        // the query runs only on pages that actually render the bell, and
        // never for a guest.
        View::composer('partials.notification-bell', function ($view) {
            $unreadCount = 0;
            $notifications = collect();

            if (Auth::check()) {
                $notifications = Notification::where('user_id', Auth::id())
                    ->latest()
                    // Capped: the dropdown is a glance at what is recent, not
                    // an archive. Without a limit this query grows with the
                    // account and runs on every single page load.
                    ->limit(10)
                    ->get();

                $unreadCount = Notification::where('user_id', Auth::id())
                    ->where('is_read', false)
                    ->count();
            }

            $view->with(compact('notifications', 'unreadCount'));
        });
    }
}
