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

            return redirect()->route('login')->with('subscription_expired', true);
        }

        return $next($request);
    }
}
