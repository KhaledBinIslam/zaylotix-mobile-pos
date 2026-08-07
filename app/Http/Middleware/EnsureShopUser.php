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
