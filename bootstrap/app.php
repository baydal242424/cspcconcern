<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // TLS is terminated at the host's edge, so the app itself is reached
        // over plain HTTP and every URL Laravel generates would come out as
        // http:// -- including the OAuth callback, which Google compares
        // character for character against the registered URI. Trusting the
        // forwarded headers restores the real scheme and host.
        $middleware->trustProxies(at: '*');

        $middleware->alias([
            'track.last_seen' => \App\Http\Middleware\UpdateLastSeen::class,
            'profile.complete' => \App\Http\Middleware\EnsureStudentProfileComplete::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
