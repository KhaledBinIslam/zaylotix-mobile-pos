<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class EnsureShopUser
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::guard('web')->check()) {
            // Temporary diagnostic (2026-10-21) — Khaled reported POS
            // repeatedly bouncing to the login form from an admin-
            // impersonated session, with no way to reproduce it directly.
            // A plain redirect() never appears in laravel.log on its own —
            // this makes the next real occurrence visible instead of
            // invisible. Remove once the real cause is confirmed.
            Log::warning('EnsureShopUser: no web session, redirecting to login', [
                'url' => $request->fullUrl(),
                'expects_json' => $request->expectsJson(),
                'is_poll' => $request->header('X-Inertia-Poll'),
                'session_id' => $request->session()->getId(),
                'impersonating_admin_id' => $request->session()->get('impersonating_admin_id'),
                'has_session_cookie' => $request->hasCookie(config('session.cookie')),
            ]);
            // Root-caused live, twice: first a raw-fetch screen (POS/
            // Restaurant checkout, addItem, etc.) sends Accept:
            // application/json and handles its own JSON error shape — a
            // redirect() here gets silently followed by fetch() to the
            // login page's full HTML, surfacing as a confusing "not valid
            // JSON" failure instead of a clear "you're logged out" one.
            // Second, and more disruptive: this app has ~6 screens
            // (Order/Pos/Stock/Tables/Kds/Cds) that quietly poll every
            // 8-15s for another device's changes via router.reload() — a
            // completely normal Inertia navigation, which Inertia's client
            // auto-FOLLOWS if it gets a redirect back, same as any other
            // Inertia visit. One transient session-read hiccup on this
            // host's chronically loaded shared DB (not an actual elapsed
            // timeout — it could hit at any point) was enough to silently
            // drag a cashier away from an in-progress sale to the login
            // page, with whatever they'd already rung up still sitting
            // unsaved on the screen they got yanked off of. usePollingReload
            // marks every one of those background polls with this header so
            // they always get a real, catchable JSON error instead — never
            // a redirect Inertia would blindly follow. A plain browser
            // navigation (neither of these) is unaffected — still gets the
            // normal redirect exactly as before.
            if ($request->expectsJson() || $request->header('X-Inertia-Poll') === 'true') {
                abort(401, 'সেশনের মেয়াদ শেষ হয়ে গেছে — আবার login করুন।');
            }

            // ->guest() (not a plain redirect()->route()) stashes this URL
            // as the session's "intended" destination — LoginController's
            // own redirect()->intended() already reads it back after a
            // successful login, so someone genuinely logged out mid-browse
            // lands back where they actually were, not just the generic
            // home page (see docs/SESSION_FIX.md).
            return redirect()->guest(route('login'));
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
