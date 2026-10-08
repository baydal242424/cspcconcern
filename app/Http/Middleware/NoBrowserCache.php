<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keep signed-in pages out of the browser's cache.
 *
 * Logging out ends the session, and the next REQUEST is refused -- but the
 * Back button does not make a request. The browser re-displays the copy it
 * already has, so a student who logs out on a shared machine leaves their
 * concern list, their filing form and whoever reads it next one keypress
 * away. The page is stale and harmless to the server; it is the reader it
 * matters to.
 *
 * Three headers, because browsers disagree about which they honour:
 * Cache-Control is the modern one, Pragma covers HTTP/1.0 proxies, and a
 * past Expires date catches anything that reads neither. "no-store" is the
 * one that counts -- "no-cache" alone permits a stored copy to be shown
 * after revalidation, and with no network request there is nothing to
 * revalidate against.
 *
 * Applied to the authenticated routes only. Assets and the login page are
 * fine to cache, and saying otherwise would make every visit slower for no
 * gain.
 */
class NoBrowserCache
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Cache-Control', 'no-store, no-cache, max-age=0, must-revalidate');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', 'Sat, 01 Jan 2000 00:00:00 GMT');

        return $response;
    }
}
