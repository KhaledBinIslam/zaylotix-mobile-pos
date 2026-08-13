<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline hardening headers on every response — cheap, has no effect on
 * legitimate use, and closes off clickjacking/MIME-sniffing/referrer-leak
 * classes of issue that a reverse proxy config alone often forgets to set.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(self), geolocation=(), microphone=()');

        // Bug found live, twice: first on /app/* (a restaurant order's "add
        // item" POST followed by an Inertia partial reload could come back
        // with the pre-add state — item flashed onto the cart and vanished),
        // then again on the PUBLIC /login page — reached directly (typed
        // URL / fresh tab), it rendered as raw, unstyled JSON instead of the
        // actual page. Same root cause both times: nothing told the
        // BROWSER's own native HTTP cache (a completely different layer
        // from sw.js's own deliberate, explicit caching) that it couldn't
        // reuse an earlier response for that URL. A Link click to /login
        // from the landing page fires an Inertia XHR (Accept:
        // application/json, raw JSON response) — with no Cache-Control
        // forbidding it, the browser was free to silently replay that
        // exact JSON response back for a LATER, completely different kind
        // of request to the same URL (a real top-level navigation expecting
        // full HTML). This app has no route anywhere that benefits from
        // browser-level caching — every one of them is either a live
        // Inertia page or a JSON/redirect response — so this is applied to
        // every response unconditionally rather than guessing at which
        // paths are "safe": static files (built JS/CSS, /storage uploads)
        // never reach this middleware in production, they're served
        // directly by the webserver from disk.
        $response->headers->set('Cache-Control', 'no-store, must-revalidate');

        return $response;
    }
}
