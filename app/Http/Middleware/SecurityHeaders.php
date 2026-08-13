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

        // Bug found live: every shop/admin page response went out with no
        // Cache-Control at all, which left the BROWSER's own native HTTP
        // cache (a completely different layer from sw.js's deliberate,
        // explicit caching) free to reuse an earlier response for the same
        // URL on its own judgement. Concretely: a restaurant order's "add
        // item" does a POST, then an Inertia partial reload (GET, same URL)
        // to refresh the cart — that reload could come back with the
        // pre-add state, so the item flashed onto the cart and vanished a
        // moment later. These paths carry live, per-request, often
        // per-second-changing tenant data (stock, cart/order state, cash
        // balances) — nothing here should ever be reused without asking the
        // server again.
        if ($request->is('app/*') || $request->is('admin/*')) {
            $response->headers->set('Cache-Control', 'no-store, must-revalidate');
        }

        return $response;
    }
}
