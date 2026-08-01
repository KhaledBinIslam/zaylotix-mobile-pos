<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Cashier management is owner-only — a cashier can never grant themselves (or another cashier) more access. */
class EnsureOwner
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::guard('web')->user()?->isOwner()) {
            abort(403, 'শুধু দোকান মালিক এই কাজটি করতে পারবেন।');
        }

        return $next($request);
    }
}
