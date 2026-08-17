<?php

namespace App\Http\Middleware;

use App\Models\Shop;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class CheckSubscriptionActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('web')->user();
        // query fresh rather than $user->shop — avoids ever acting on a stale
        // cached relation if the same user model outlives a status change
        // within one process (e.g. queued jobs, long-lived workers).
        $shop = $user ? Shop::find($user->shop_id) : null;

        if (! $shop || ! $shop->isActive()) {
            // A background poll (see usePollingReload — Order/Pos/Stock/
            // Tables/Kds/Cds quietly refreshing every 8-15s) must NEVER be
            // allowed to actually destroy a real session over this. Unlike
            // EnsureShopUser (a harmless redirect), this branch LOGS THE
            // USER OUT and invalidates the session outright — a background
            // check hitting a transient false negative (a momentary failed
            // Shop::find under this host's chronically loaded shared DB, not
            // an actual subscription change) would have destroyed a live,
            // paying shop's session over nothing, not just misrouted one
            // request. For a poll, answer with the same "try again" JSON a
            // real inactive-shop rejection would give, but WITHOUT touching
            // the session — an explicit navigation or action (checkout,
            // etc.) happens within moments of any real subscription lapse
            // anyway and enforces it properly then; this only ever delays
            // enforcement for a tab left open with nothing else happening
            // in it, never weakens it.
            if ($request->header('X-Inertia-Poll') === 'true') {
                abort(401, 'আপনার সাবস্ক্রিপশনের মেয়াদ শেষ হয়ে গেছে বা shop নিষ্ক্রিয়।');
            }

            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            // see EnsureShopUser's matching comment — same reasoning, same
            // fix: a JSON-expecting fetch() must never get a redirect it'll
            // silently follow into an unparseable HTML page instead of the
            // clear "you're logged out" it can actually act on.
            if ($request->expectsJson()) {
                abort(401, 'আপনার সাবস্ক্রিপশনের মেয়াদ শেষ হয়ে গেছে বা shop নিষ্ক্রিয়।');
            }

            return redirect()->route('login')->with('subscription_expired', true);
        }

        return $next($request);
    }
}
