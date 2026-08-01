<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureApiShopActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $shop = $request->user()?->shop;

        if (! $shop || ! $shop->isActive()) {
            return response()->json(['message' => 'Subscription inactive.'], 403);
        }

        return $next($request);
    }
}
