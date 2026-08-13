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
