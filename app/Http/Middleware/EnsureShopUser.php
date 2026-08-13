<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureShopUser
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::guard('web')->check()) {
            // Root-caused live: a raw-fetch screen (POS/Restaurant checkout,
            // addItem, etc.) sends Accept: application/json and handles its
            // own JSON error shape — a redirect() here gets silently
            // followed by fetch() to the login page's full HTML, landing
            // back as an ordinary 200 response the caller then fails to
            // parse as JSON, surfacing as a confusing "not valid JSON"
            // failure instead of a clear "you're logged out" one. A plain
            // browser navigation (no Accept: application/json) is unaffected
            // — still gets the normal redirect exactly as before.
            if ($request->expectsJson()) {
                abort(401, 'সেশনের মেয়াদ শেষ হয়ে গেছে — আবার login করুন।');
            }

            return redirect()->route('login');
        }

        // Deliberately does NOT call Tenancy::set() here — that binds a
        // container override which Tenancy::id() checks FIRST, before its
        // own branch-switch session logic ever runs, which would silently
        // pin every request back to the owner's own shop_id and make
        // switching to a branch (BranchController::switch) a no-op for the
        // rest of the app. Tenancy::id() already resolves correctly on its
        // own from the authenticated user (falling back to shop_id, or the
        // switched branch when session('active_branch_id') applies) — this
        // middleware's only job is making sure someone's actually logged in.
        return $next($request);
    }
}
