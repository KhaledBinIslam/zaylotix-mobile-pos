<?php

namespace App\Http\Middleware;

use App\Support\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureShopUser
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::guard('web')->check()) {
            return redirect()->route('login');
        }

        // Bind the tenant early so every scoped query in the rest of this
        // request (including the controller that's about to run) resolves
        // against the right shop.
        Tenancy::set(Auth::guard('web')->user()->shop_id);

        return $next($request);
    }
}
